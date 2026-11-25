<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support;

use Qiick\Audit\Actions\CleanActivityLogAction;

/** A product's own clean action, which must keep extending the package's. */
final class ProductCleanActivityLogAction extends CleanActivityLogAction {}
