<?php

declare(strict_types=1);

namespace Qiick\Audit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Qiick\Audit\Models\Activity;

/**
 * The retention purge (Luca, 2026-11-23, design §10).
 *
 * Two passes on `created_at`, so the two floors can never be conflated:
 * 1. rows outside the security log (`log_name IS NULL OR log_name <> 'auth'`)
 *    older than `audit.retention_days` (365);
 * 2. rows of the security log older than `audit.security_retention_days`
 *    (730).
 *
 * Spatie's activitylog:clean takes one cutoff and one optional log name, so a
 * plain 365-day clean would purge auth rows a year early: products schedule
 * this command instead (the provider schedules it daily by default). Each
 * pass deletes in chunks of `audit.purge.chunk_size` ids, so no statement
 * locks the whole table, and the ids are read first because MySQL refuses a
 * DELETE whose subquery reads the same table.
 */
final class PurgeActivityLogCommand extends Command
{
    protected $signature = 'audit:purge';

    protected $description = 'Delete activity rows past their retention: 365 days, 730 for the security log';

    public function handle(ConfigRepository $config): int
    {
        try {
            $retentionDays = self::positiveInteger($config->get('audit.retention_days'), 'audit.retention_days must be a positive number of days.');
            $securityRetentionDays = self::positiveInteger($config->get('audit.security_retention_days'), 'audit.security_retention_days must be a positive number of days.');
            $chunkSize = self::positiveInteger($config->get('audit.purge.chunk_size'), 'audit.purge.chunk_size must be a positive integer.');
            $securityLogName = $config->get('audit.security_log_name');

            if ($securityRetentionDays < $retentionDays) {
                throw new InvalidArgumentException('audit.security_retention_days must not be shorter than audit.retention_days.');
            }

            if (! is_string($securityLogName) || $securityLogName === '') {
                throw new InvalidArgumentException('audit.security_log_name must name a log.');
            }
        } catch (InvalidArgumentException $invalidConfiguration) {
            $this->error($invalidConfiguration->getMessage());

            return self::FAILURE;
        }

        $now = Carbon::now('UTC');

        $generalRows = $this->purge(
            fn (Builder $query) => $query->where(fn (Builder $logQuery) => $logQuery
                ->whereNull('log_name')
                ->orWhere('log_name', '<>', $securityLogName)),
            $now->copy()->subDays($retentionDays),
            $chunkSize,
        );

        $securityRows = $this->purge(
            fn (Builder $query) => $query->where('log_name', $securityLogName),
            $now->copy()->subDays($securityRetentionDays),
            $chunkSize,
        );

        $this->info("Purged {$generalRows} rows older than {$retentionDays} days and {$securityRows} {$securityLogName} rows older than {$securityRetentionDays} days.");

        return self::SUCCESS;
    }

    /**
     * @param  callable(Builder): mixed  $scopeToLogs
     */
    private function purge(callable $scopeToLogs, Carbon $cutoff, int $chunkSize): int
    {
        $activityModel = Activity::newConfiguredModel();
        $connection = $activityModel->getConnection();
        $table = $activityModel->getTable();
        $storedCutoff = $activityModel->fromDateTime($cutoff);
        $deletedRows = 0;

        do {
            $query = $connection->table($table)->where('created_at', '<', $storedCutoff);
            $scopeToLogs($query);
            $ids = $query->orderBy('id')->limit($chunkSize)->pluck('id')->all();

            if ($ids !== []) {
                $deletedRows += $connection->table($table)->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $chunkSize);

        return $deletedRows;
    }

    private static function positiveInteger(mixed $value, string $message): int
    {
        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }
}
