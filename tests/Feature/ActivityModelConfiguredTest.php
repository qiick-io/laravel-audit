<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Qiick\Audit\Tests\Support\Models\ProductActivity;
use Qiick\Audit\Tests\Support\PackageTestCase;
use Qiick\Audit\Tests\Support\ProductLogActivityAction;

/**
 * A product that extends the package model or action and configures its
 * subclass keeps it: the provider only replaces Spatie's own defaults.
 */
final class ActivityModelConfiguredTest extends PackageTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('activitylog.activity_model', ProductActivity::class);
        $app['config']->set('activitylog.actions.log_activity', ProductLogActivityAction::class);
    }

    public function test_it_keeps_the_product_model_and_action(): void
    {
        $this->assertSame(ProductActivity::class, config('activitylog.activity_model'));
        $this->assertSame(ProductLogActivityAction::class, config('activitylog.actions.log_activity'));
    }
}
