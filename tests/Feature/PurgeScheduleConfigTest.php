<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Qiick\Audit\Tests\Support\PackageTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * The purge schedule follows audit.purge.time, and a product that runs the
 * purge itself turns the package schedule off with audit.purge.schedule.
 */
final class PurgeScheduleConfigTest extends PackageTestCase
{
    public function test_it_schedules_at_the_configured_time(): void
    {
        $this->assertSame(['15 4 * * *'], $this->purgeExpressions());
    }

    public function test_it_does_not_schedule_when_the_product_turns_it_off(): void
    {
        config()->set('audit.purge.schedule', false);
        $this->app->forgetInstance(Schedule::class);

        $this->assertSame([], $this->purgeExpressions());
    }

    #[DataProvider('invalidPurgeTimes')]
    public function test_it_refuses_a_purge_time_that_is_not_hh_mm(mixed $purgeTime): void
    {
        config()->set('audit.purge.time', $purgeTime);
        $this->app->forgetInstance(Schedule::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('audit.purge.time must be a time of day as HH:MM.');

        $this->app->make(Schedule::class);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidPurgeTimes(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'hour only' => ['3'],
            'hour 24' => ['24:00'],
            'minute 60' => ['03:60'],
            'seconds' => ['03:00:00'],
            'trailing text' => ['03:00 '],
            'leading text' => ['x03:00'],
        ];
    }

    public function test_it_accepts_the_edges_of_the_day(): void
    {
        foreach (['00:00' => '0 0 * * *', '23:59' => '59 23 * * *', '19:30' => '30 19 * * *'] as $purgeTime => $expression) {
            config()->set('audit.purge.time', $purgeTime);
            $this->app->forgetInstance(Schedule::class);

            $this->assertSame([$expression], $this->purgeExpressions());
        }
    }

    public function test_the_activitylog_clean_warning_names_the_configured_floor(): void
    {
        config()->set('audit.security_log_name', 'security');
        config()->set('audit.security_retention_days', 1000);
        $log = Log::spy();

        event(new CommandStarting('activitylog:clean', new ArrayInput([]), new NullOutput));

        $log->shouldHaveReceived('warning')->once()->with(
            'activitylog:clean ignores the 1000-day floor of the security log: remove it from the schedule, audit:purge applies both retention floors.',
        );
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('audit.purge.time', '04:15');
        $app['config']->set('audit.retention_days', 30);
    }

    public function test_it_feeds_audit_retention_days_to_spatie(): void
    {
        $this->assertSame(30, config('activitylog.clean_after_days'));
    }

    /**
     * @return list<string>
     */
    private function purgeExpressions(): array
    {
        return collect($this->app->make(Schedule::class)->events())
            ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'audit:purge'))
            ->map(fn (ScheduledEvent $event): string => $event->expression)
            ->values()
            ->all();
    }
}
