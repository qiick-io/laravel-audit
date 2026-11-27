<?php

declare(strict_types=1);

namespace Qiick\Audit\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Support\RetentionPurger;

/**
 * The deploy check (fleet convention F2). The boot checks already stop a
 * misconfigured model, timezone or production switch; this command covers what
 * only fails later: the retention floors (first purge), the purge time when
 * the package schedules the purge (first `schedule:run`), and the activity_log
 * table with every column the package writes, purges or erases by, under the
 * configured morph names (first logged write).
 *
 * @internal The command name `audit:check` is the public surface, not the class.
 */
final class CheckAuditCommand extends Command
{
    protected $signature = 'audit:check';

    protected $description = 'Check the audit package for deployment';

    public function handle(ConfigRepository $config): int
    {
        try {
            RetentionPurger::fromConfig($config);
            // Resolving the schedule runs the provider's purge time check.
            $this->laravel->make(Schedule::class);
            $activity = Activity::newConfiguredModel();
            // Every column the package writes, purges or erases by (docs/reference-schema.md).
            $requiredColumns = [
                'id', 'public_id', 'log_name', 'description', 'event',
                'subject_type', Activity::idColumn('subject_id'), 'causer_type', Activity::idColumn('causer_id'),
                'organisation_id', 'attribute_changes', 'properties', 'schema_version', 'request_id',
                'anonymised_at', 'created_at', 'updated_at',
            ];
        } catch (InvalidArgumentException $invalidConfiguration) {
            $this->error($invalidConfiguration->getMessage());

            return self::FAILURE;
        }

        // An unreachable database throws, which also fails the deploy.
        if (! Schema::connection($activity->getConnectionName())->hasColumns($activity->getTable(), $requiredColumns)) {
            $this->error("The {$activity->getTable()} table is missing or lacks the columns ".implode(', ', $requiredColumns).'.');

            return self::FAILURE;
        }

        $this->info('Audit configuration and table are ready.');

        return self::SUCCESS;
    }
}
