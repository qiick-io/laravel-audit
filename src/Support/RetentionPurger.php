<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Qiick\Audit\Models\Activity;

/**
 * Deletes activity rows past their retention floor (Luca, 2026-11-23, design
 * §10): rows outside the security log after `audit.retention_days` (365),
 * security log rows after `audit.security_retention_days` (730).
 *
 * Every deleting path goes through here (audit:purge and Spatie's
 * activitylog:clean, through the package's clean action), so no path deletes
 * a row younger than its floor: a shorter age asked by a caller is raised to
 * the floor. Each pass deletes in chunks of `audit.purge.chunk_size` ids, so
 * no statement locks the whole table; the ids are read first because MySQL
 * refuses a DELETE whose subquery reads the same table.
 *
 * @internal
 */
final readonly class RetentionPurger
{
    private function __construct(
        public int $retentionDays,
        public int $securityRetentionDays,
        public string $securityLogName,
        private int $chunkSize,
    ) {}

    /**
     * A misconfigured floor would purge early or never, so it fails before
     * anything is deleted.
     */
    public static function fromConfig(ConfigRepository $config): self
    {
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

        return new self($retentionDays, $securityRetentionDays, $securityLogName, $chunkSize);
    }

    /**
     * @param  int  $minimumAgeInDays  a caller's own cutoff, never below the floors
     * @param  string|null  $onlyLogName  restrict the purge to one log
     * @return array{general: int, security: int} the rows deleted by each pass
     */
    public function purge(int $minimumAgeInDays, ?string $onlyLogName = null): array
    {
        $now = Carbon::now('UTC');
        $deletedRows = ['general' => 0, 'security' => 0];

        if ($onlyLogName !== $this->securityLogName) {
            $deletedRows['general'] = $this->deleteOlderThan(
                fn (Builder $query) => $onlyLogName === null
                    ? $query->where(fn (Builder $logQuery) => $logQuery->whereNull('log_name')->orWhere('log_name', '<>', $this->securityLogName))
                    : $query->where('log_name', $onlyLogName),
                $now->copy()->subDays(max($minimumAgeInDays, $this->retentionDays)),
            );
        }

        if ($onlyLogName === null || $onlyLogName === $this->securityLogName) {
            $deletedRows['security'] = $this->deleteOlderThan(
                fn (Builder $query) => $query->where('log_name', $this->securityLogName),
                $now->copy()->subDays(max($minimumAgeInDays, $this->securityRetentionDays)),
            );
        }

        return $deletedRows;
    }

    /**
     * @param  callable(Builder): mixed  $scopeToLogs
     */
    private function deleteOlderThan(callable $scopeToLogs, Carbon $cutoff): int
    {
        $activityModel = Activity::newConfiguredModel();
        $connection = $activityModel->getConnection();
        $table = $activityModel->getTable();
        $storedCutoff = $activityModel->fromDateTime($cutoff);
        $deletedRows = 0;

        do {
            $query = $connection->table($table)->where('created_at', '<', $storedCutoff);
            $scopeToLogs($query);
            $ids = $query->orderBy('id')->limit($this->chunkSize)->pluck('id')->all();

            if ($ids !== []) {
                $deletedRows += $connection->table($table)->whereIn('id', $ids)->delete();
            }
        } while (count($ids) === $this->chunkSize);

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
