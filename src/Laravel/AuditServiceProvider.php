<?php

declare(strict_types=1);

namespace Qiick\Audit\Laravel;

use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\ServiceProvider;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActorResolver;
use Qiick\Audit\Contracts\OrganisationResolver;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Resolvers\NullOrganisationResolver;
use Qiick\Audit\Resolvers\RequestAttributeActorResolver;
use Qiick\Audit\Support\ActivityLogger;
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
