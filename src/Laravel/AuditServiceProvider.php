<?php

declare(strict_types=1);

namespace Qiick\Audit\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use Qiick\Audit\Actions\CleanActivityLogAction;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Anonymisation\ActivityAnonymiser;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Console\Commands\PurgeActivityLogCommand;
use Qiick\Audit\Contracts\AuditActorResolver;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\OrganisationResolver;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Resolvers\NullOrganisationResolver;
use Qiick\Audit\Resolvers\RequestAttributeActorResolver;
use Qiick\Audit\Support\ActivityLogger;
use Spatie\Activitylog\Actions\CleanActivityLogAction as SpatieCleanActivityLogAction;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Spatie\Activitylog\Support\ActivityLogger as SpatieActivityLogger;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Merges the `audit` config, makes the package Activity model Spatie's
 * activity model, publishes it under the `audit-config` tag and
 * loads the activity_log migration unless `audit.migrations.load` is false
 * (externa 1.x adopts the table through its own additive migrations).
 *
 * @api
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/audit.php', 'audit');

        $this->app->scoped(AuditContext::class);
        // bindIf: a product provider that bound its own resolver keeps it.
        $this->app->bindIf(AuditActorResolver::class, RequestAttributeActorResolver::class);
        $this->app->bindIf(OrganisationResolver::class, NullOrganisationResolver::class);
        $this->app->bindIf(AuditAnonymiser::class, ActivityAnonymiser::class);
    }

    /**
     * F1: mergeConfigFrom() merges only the top level, so a config/audit.php
     * published before a nested key existed would lose it, and a missing
     * `purge.schedule` or `migrations.load` would silently turn the purge or
     * the table off. Each nested block gets the package default for every key
     * the product left out. Only these associative blocks are filled: lists
     * such as `security_log_names` stay exactly as the product wrote them.
     *
     * @throws InvalidArgumentException when a nested block is not an array, or
     *                                  a switch of it is not a boolean.
     */
    private function fillTheNestedDefaults(): void
    {
        $config = $this->app->make('config');
        $packageDefaults = require __DIR__.'/../../config/audit.php';

        foreach (['purge', 'migrations', 'columns'] as $block) {
            $configuredBlock = $config->get("audit.{$block}");

            if (! is_array($configuredBlock)) {
                throw new InvalidArgumentException("audit.{$block} must be an array.");
            }

            $defaultBlock = is_array($packageDefaults) ? $packageDefaults[$block] : null;

            if (! is_array($defaultBlock)) {
                throw new LogicException("The package config has no audit.{$block} block.");
            }

            $config->set("audit.{$block}", array_merge($defaultBlock, $configuredBlock));
        }

        // A string 'true' would leave the purge unscheduled or the table
        // missing without a word: the switches take booleans only.
        foreach (['audit.purge.schedule', 'audit.migrations.load'] as $switch) {
            if (! is_bool($config->get($switch))) {
                throw new InvalidArgumentException("{$switch} must be true or false.");
            }
        }
    }

    /**
     * Retention is a privacy obligation (design §10), so the purge does not
     * depend on a product remembering to schedule it: it runs daily unless
     * the product turns `audit.purge.schedule` off and runs it itself.
     * Spatie's activitylog:clean goes through the package's clean action, so
     * it applies the same two floors.
     */
    private function scheduleTheRetentionPurge(): void
    {
        $config = $this->app->make('config');
        $config->set('activitylog.clean_after_days', $config->get('audit.retention_days'));

        $this->commands([PurgeActivityLogCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) use ($config): void {
            if ($config->get('audit.purge.schedule') !== true) {
                return;
            }

            $purgeTime = $config->get('audit.purge.time');

            if (! is_string($purgeTime) || preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/', $purgeTime) !== 1) {
                throw new InvalidArgumentException('audit.purge.time must be a time of day as HH:MM.');
            }

            // One run at a time across servers (needs a cache store with locks,
            // shared by the servers, as Laravel's scheduler always does).
            $schedule->command(PurgeActivityLogCommand::class)
                ->dailyAt($purgeTime)
                ->timezone('UTC')
                ->withoutOverlapping()
                ->onOneServer();
        });
    }

    /**
     * Point Spatie at the package model and logging action unless the product
     * configured its own (which must extend the package's). Done at boot, after Spatie's
     * provider has merged its defaults.
     */
    private function useThePackageModelAndAction(): void
    {
        $config = $this->app->make('config');
        $configuredModel = $config->get('activitylog.activity_model');

        if ($configuredModel === null || $configuredModel === SpatieActivity::class) {
            $config->set('activitylog.activity_model', Activity::class);
        }

        $configuredAction = $config->get('activitylog.actions.log_activity');

        if ($configuredAction === null || $configuredAction === SpatieLogActivityAction::class) {
            $config->set('activitylog.actions.log_activity', LogActivityAction::class);
        }

        $configuredCleanAction = $config->get('activitylog.actions.clean_log');

        if ($configuredCleanAction === null || $configuredCleanAction === SpatieCleanActivityLogAction::class) {
            $config->set('activitylog.actions.clean_log', CleanActivityLogAction::class);
        }
    }

    public function boot(): void
    {
        $this->fillTheNestedDefaults();
        $this->useThePackageModelAndAction();

        (new AuditBootChecks($this->app->make('config'), $this->app->isProduction()))->assert();

        $this->scheduleTheRetentionPurge();

        // Spatie binds its logger in register(); binding at boot replaces it
        // whatever order the providers registered in.
        $this->app->bind(SpatieActivityLogger::class, ActivityLogger::class);

        $this->app->make('events')->listen(
            RouteMatched::class,
            fn (RouteMatched $routeMatched) => AuditContext::rememberHttpRequest($routeMatched->request),
        );

        $this->publishes([
            __DIR__.'/../../config/audit.php' => $this->app->configPath('audit.php'),
        ], 'audit-config');

        if ($this->app->make('config')->get('audit.migrations.load') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
    }
}
