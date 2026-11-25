<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Qiick\Audit\Tests\Support\PackageTestCase;
use RuntimeException;

/**
 * In production the provider refuses a log that one env variable turned off
 * (ACTIVITYLOG_ENABLED=false, A9).
 */
final class ProductionBootFailsTest extends PackageTestCase
{
    private bool $shouldMisconfigure = false;

    public function test_the_application_does_not_boot(): void
    {
        $this->shouldMisconfigure = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('activitylog.enabled must be true in production: security events must not be turned off.');

        $this->refreshApplication();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! $this->shouldMisconfigure) {
            return;
        }

        $app['env'] = 'production';
        $app['config']->set('app.env', 'production');
        $app['config']->set('activitylog.enabled', false);
    }
}
