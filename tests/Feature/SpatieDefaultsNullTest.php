<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Qiick\Audit\Actions\CleanActivityLogAction;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\PackageTestCase;

/**
 * A product config that blanks Spatie's model or action still gets the
 * package's, not Spatie's fallback.
 */
final class SpatieDefaultsNullTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('activitylog.activity_model', null);
        $app['config']->set('activitylog.actions.log_activity', null);
        $app['config']->set('activitylog.actions.clean_log', null);
    }

    public function test_it_fills_the_package_model_and_action(): void
    {
        $this->assertSame(Activity::class, config('activitylog.activity_model'));
        $this->assertSame(LogActivityAction::class, config('activitylog.actions.log_activity'));
        $this->assertSame(CleanActivityLogAction::class, config('activitylog.actions.clean_log'));
    }
}
