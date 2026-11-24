<?php

declare(strict_types=1);

namespace Qiick\Audit\Laravel;

use Illuminate\Support\ServiceProvider;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

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
    }

    public function boot(): void
    {
        $this->useThePackageModelAndAction();

        $this->publishes([
            __DIR__.'/../../config/audit.php' => $this->app->configPath('audit.php'),
        ], 'audit-config');

        if ($this->app->make('config')->get('audit.migrations.load') === true) {
            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }
    }
}
