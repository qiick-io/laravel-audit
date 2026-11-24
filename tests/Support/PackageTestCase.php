<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Qiick\Audit\Laravel\AuditServiceProvider;
use Spatie\Activitylog\ActivitylogServiceProvider;

/**
 * Boots a Testbench Laravel app with Spatie's provider and the package
 * provider. CI runs the suite on Laravel 12 and 13, and on SQLite /
 * PostgreSQL / MySQL / MariaDB (DB_CONNECTION and friends).
 */
abstract class PackageTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ActivitylogServiceProvider::class,
            AuditServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('app.timezone', 'UTC');

        $connection = getenv('DB_CONNECTION') ?: 'testing';
        $app['config']->set('database.default', $connection);

        if (in_array($connection, ['sqlite', 'testing'], true)) {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => 'sqlite',
                'database' => getenv('DB_DATABASE') ?: ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);
        }

        if (in_array($connection, ['mysql', 'mariadb', 'pgsql'], true)) {
            $app['config']->set("database.connections.{$connection}", [
                'driver' => $connection,
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => getenv('DB_PORT') ?: ($connection === 'pgsql' ? '5432' : '3306'),
                'database' => getenv('DB_DATABASE') ?: 'laravel_audit',
                'username' => getenv('DB_USERNAME') ?: ($connection === 'pgsql' ? 'postgres' : 'root'),
                'password' => getenv('DB_PASSWORD') !== false ? (string) getenv('DB_PASSWORD') : '',
                'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
                'prefix' => '',
                'strict' => true,
            ]);
        }
    }
}
