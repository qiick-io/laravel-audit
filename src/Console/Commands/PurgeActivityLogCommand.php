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
 * 1. rows outside the security log (`log_name IS NULL OR log_name <> 'auth'`)
 *    older than `audit.retention_days` (365);
 * 2. rows of the security log older than `audit.security_retention_days`
 *    (730).
 */
final class PurgeActivityLogCommand extends Command
{
    protected $signature = 'audit:purge';

    protected $description = 'Delete activity rows past their retention: 365 days, 730 for the security log';

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

        $this->info("Purged {$deletedRows['general']} rows older than {$purger->retentionDays} days and {$deletedRows['security']} {$purger->securityLogName} rows older than {$purger->securityRetentionDays} days.");

        return self::SUCCESS;
    }
}
