<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support;

use Qiick\Audit\Actions\LogActivityAction;

/** A product's own logging action, which must keep extending the package's. */
final class ProductLogActivityAction extends LogActivityAction {}
