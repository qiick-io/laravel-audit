<?php

declare(strict_types=1);

namespace Qiick\Audit\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the package in a Laravel app. Auto-discovered through
 * `extra.laravel.providers` in composer.json.
 *
 * Merges the `audit` config and publishes it under the `audit-config` tag.
 *
 * @api
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/audit.php', 'audit');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../../config/audit.php' => $this->app->configPath('audit.php'),
        ], 'audit-config');
    }
}
