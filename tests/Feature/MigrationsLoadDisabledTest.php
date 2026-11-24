<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Qiick\Audit\Tests\Support\PackageTestCase;

/**
 * externa 1.x sets audit.migrations.load = false and adopts the table through
 * its own additive migrations (design §6.2).
 */
final class MigrationsLoadDisabledTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('audit.migrations.load', false);
    }

    public function test_it_does_not_load_the_package_migration(): void
    {
        $loadedPaths = array_map(fn (string $path): string|false => realpath($path), app('migrator')->paths());

        $this->assertNotContains(realpath(dirname(__DIR__, 2).'/database/migrations'), $loadedPaths);

        $this->artisan('migrate')->assertSuccessful();

        $this->assertFalse(Schema::hasTable('activity_log'));
    }
}
