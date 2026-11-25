<?php

declare(strict_types=1);

namespace Qiick\Audit\Actions;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Qiick\Audit\Support\RetentionPurger;
use Spatie\Activitylog\Actions\CleanActivityLogAction as SpatieCleanActivityLogAction;

/**
 * Spatie's clean action, configured as `activitylog.actions.clean_log`, so
 * Spatie's activitylog:clean goes through the two retention floors
 * (analysis AU-l). Spatie's command takes one cutoff: on its own, a product
 * that still schedules it would delete the security log after 365 days, or
 * after one with `--days=1`. Here the cutoff is never below the floor of the
 * log it applies to.
 *
 * @api A product may extend it; boot fails unless the configured action does.
 */
class CleanActivityLogAction extends SpatieCleanActivityLogAction
{
    public function execute(int $maxAgeInDays, ?string $logName = null): int
    {
        $deletedRows = RetentionPurger::fromConfig(app(ConfigRepository::class))->purge($maxAgeInDays, $logName);

        return $deletedRows['general'] + $deletedRows['security'];
    }
}
