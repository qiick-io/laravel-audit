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
 * §10): rows outside the security logs after `audit.retention_days` (365),
 * rows of the security logs (`audit.security_log_names`: auth, security,
 * access, api-keys) after `audit.security_retention_days` (730).
 *
 * Every deleting path goes through here (audit:purge and Spatie's
 * activitylog:clean, through the package's clean action), so no path deletes
 * a row younger than its floor: a shorter age asked by a caller is raised to
 * the floor. Each pass deletes in chunks of `audit.purge.chunk_size` ids, so
 * no statement locks the whole table; the ids are read first because MySQL
 * refuses a DELETE whose subquery reads the same table. Two purges may
 * overlap (the scheduler prevents it, a manual run may not): deletes are
 * idempotent and recheck the cutoff, so the floors hold either way.
 *
 * @internal
 */
final readonly class RetentionPurger
{
    private function __construct(
        public int $retentionDays,
        public int $securityRetentionDays,
        /** @var non-empty-list<string> lower-cased */
        public array $securityLogNames,
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
        $securityLogNames = self::logNames($config->get('audit.security_log_names'));

        if ($securityRetentionDays < $retentionDays) {
            throw new InvalidArgumentException('audit.security_retention_days must not be shorter than audit.retention_days.');
        }

        return new self($retentionDays, $securityRetentionDays, $securityLogNames, $chunkSize);
    }

    /**
     * A log name as the package stores it (LogActivityAction): trimmed and
     * lower-cased, so `Auth` and `auth` are one log on every engine.
     */
    public static function normaliseLogName(string $logName): string
    {
        return strtolower(trim($logName));
    }

    /**
     * @return non-empty-list<string>
     */
    private static function logNames(mixed $configuredNames): array
    {
        $message = 'audit.security_log_names must be a non-empty list of log names.';

        if (! is_array($configuredNames) || $configuredNames === []) {
            throw new InvalidArgumentException($message);
        }

        $logNames = [];

        foreach ($configuredNames as $configuredName) {
            if (! is_string($configuredName) || self::normaliseLogName($configuredName) === '') {
                throw new InvalidArgumentException($message);
            }

            $logNames[] = self::normaliseLogName($configuredName);
        }

        return $logNames;
    }

    /**
     * @param  int  $minimumAgeInDays  a caller's own cutoff, never below the floors
     * @param  string|null  $onlyLogName  restrict the purge to one log (any case)
     * @return array{general: int, security: int} the rows deleted by each pass
     */
    public function purge(int $minimumAgeInDays, ?string $onlyLogName = null): array
    {
        $now = Carbon::now('UTC');
        $deletedRows = ['general' => 0, 'security' => 0];

        $onlyLogName = $onlyLogName === null ? null : self::normaliseLogName($onlyLogName);
        $isSecurityLog = in_array($onlyLogName, $this->securityLogNames, true);

        if (! $isSecurityLog) {
            $deletedRows['general'] = $this->deleteOlderThan(
                fn (Builder $query) => $onlyLogName === null
                    ? $query->where(fn (Builder $logQuery) => $logQuery->whereNull('log_name')->orWhereNotIn('log_name', $this->securityLogNames))
                    : $query->where('log_name', $onlyLogName),
                $now->copy()->subDays(max($minimumAgeInDays, $this->retentionDays)),
            );
        }

        if ($onlyLogName === null || $isSecurityLog) {
            $deletedRows['security'] = $this->deleteOlderThan(
                fn (Builder $query) => $query->whereIn('log_name', $onlyLogName === null ? $this->securityLogNames : [$onlyLogName]),
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
            // From the writer: a lagging read replica would keep returning rows
            // already deleted, and the loop would never finish.
            $query = $connection->table($table)->useWritePdo()->where('created_at', '<', $storedCutoff);
            $scopeToLogs($query);
            $ids = $query->orderBy('id')->limit($this->chunkSize)->pluck('id')->all();

            if ($ids !== []) {
                // The cutoff is checked again in the delete itself, so a row that
                // stopped being past its floor since the ids were read stays.
                $deletedRows += $connection->table($table)
                    ->whereIn('id', $ids)
                    ->where('created_at', '<', $storedCutoff)
                    ->delete();
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
