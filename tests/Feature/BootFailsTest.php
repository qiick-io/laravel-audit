<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Qiick\Audit\Tests\Support\PackageTestCase;
use RuntimeException;

/**
 * The provider runs the boot checks: an application with a non-UTC timezone
 * does not boot (A13).
 */
final class BootFailsTest extends PackageTestCase
{
    private bool $shouldMisconfigure = false;

    public function test_the_application_does_not_boot(): void
    {
        $this->shouldMisconfigure = true;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('app.timezone must be UTC: audit timestamps are stored and exported in UTC.');

        $this->refreshApplication();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (! $this->shouldMisconfigure) {
            return;
        }

        $app['config']->set('app.timezone', 'Europe/Rome');
    }
}
