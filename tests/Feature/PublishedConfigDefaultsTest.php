<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Qiick\Audit\Tests\Support\PackageTestCase;

/**
 * F1 (#11): Laravel's mergeConfigFrom() merges only the top level, so a
 * config/audit.php published before a nested key existed would drop it. A
 * missing `purge.schedule` would silently stop the retention purge and a
 * missing `migrations.load` would silently skip the table, so the package
 * fills every missing nested key with its default and keeps what the product set.
 */
final class PublishedConfigDefaultsTest extends PackageTestCase
{
    private mixed $publishedColumns = ['subject_id' => 'subject_id'];

    /** @var array<string, mixed> */
    private array $publishedPurge = ['time' => '04:15'];

    /** @var array<string, mixed> */
    private array $publishedMigrations = [];

    public function test_a_missing_nested_key_takes_the_package_default(): void
    {
        $this->assertTrue(config('audit.purge.schedule'));
        $this->assertSame(1000, config('audit.purge.chunk_size'));
        $this->assertTrue(config('audit.migrations.load'));
        $this->assertSame('causer_id', config('audit.columns.causer_id'));
    }

    public function test_a_nested_key_the_product_set_is_kept(): void
    {
        $this->assertSame('04:15', config('audit.purge.time'));
        $this->assertSame(['auth'], config('audit.security_log_names'));
    }

    public function test_the_purge_stays_scheduled_and_the_table_is_created(): void
    {
        $purgeEvents = array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'audit:purge'),
        );

        $this->assertSame(['15 4 * * *'], array_values(array_map(fn (ScheduledEvent $event): string => $event->expression, $purgeEvents)));

        $this->artisan('migrate')->assertSuccessful();

        $this->assertTrue(Schema::hasTable('activity_log'));
    }

    public function test_a_block_that_is_not_an_array_fails_with_its_key(): void
    {
        $this->publishedColumns = 'subject_ref';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit.columns must be an array.');

        $this->refreshApplication();
    }

    public function test_a_schedule_flag_that_is_not_a_boolean_fails_with_its_key(): void
    {
        $this->publishedPurge = ['schedule' => 'true'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit.purge.schedule must be true or false.');

        $this->refreshApplication();
    }

    public function test_a_migrations_flag_that_is_not_a_boolean_fails_with_its_key(): void
    {
        $this->publishedMigrations = ['load' => 'true'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audit.migrations.load must be true or false.');

        $this->refreshApplication();
    }

    public function test_an_explicit_false_is_kept(): void
    {
        $this->publishedPurge = ['schedule' => false];
        $this->publishedMigrations = ['load' => false];
        $this->refreshApplication();

        $this->assertFalse(config('audit.purge.schedule'));
        $this->assertFalse(config('audit.migrations.load'));
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // An older published file: whole blocks and nested keys are missing.
        $app['config']->set('audit', [
            'security_log_names' => ['auth'],
            'purge' => $this->publishedPurge,
            'migrations' => $this->publishedMigrations,
            'columns' => $this->publishedColumns,
        ]);
    }
}
