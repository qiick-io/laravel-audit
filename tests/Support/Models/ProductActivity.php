<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Qiick\Audit\Models\Activity;

/** A product's own activity model, which must keep extending the package's. */
final class ProductActivity extends Activity {}
