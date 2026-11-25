<?php

declare(strict_types=1);

namespace Qiick\Audit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;
use Qiick\Audit\Support\RetentionPurger;

/**
 * The retention purge (Luca, 2026-11-23, design §10), scheduled daily by the
 * provider: two passes on `created_at`, so the two floors can never be
 * conflated (RetentionPurger):
 * 1. rows outside the security logs (`log_name IS NULL OR log_name NOT IN
 *    audit.security_log_names`) older than `audit.retention_days` (365);
 * 2. rows of the security logs older than `audit.security_retention_days`
 *    (730).
 */
final class PurgeActivityLogCommand extends Command
{
    protected $signature = 'audit:purge';

    protected $description = 'Delete activity rows past their retention: 365 days, 730 for the security logs';

    public function handle(ConfigRepository $config): int
    {
        try {
            $purger = RetentionPurger::fromConfig($config);
        } catch (InvalidArgumentException $invalidConfiguration) {
            $this->error($invalidConfiguration->getMessage());

            return self::FAILURE;
        }

        // The general floor is the shortest age; the security pass raises it
        // to its own floor.
        $deletedRows = $purger->purge($purger->retentionDays);

        $securityLogs = implode(', ', $purger->securityLogNames);

        $this->info("Purged {$deletedRows['general']} rows older than {$purger->retentionDays} days and {$deletedRows['security']} security log rows ({$securityLogs}) older than {$purger->securityRetentionDays} days.");

        return self::SUCCESS;
    }
}
