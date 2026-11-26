<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Qiick\Audit\Models\Activity;

/** A product activity model that guards one more column. */
final class GuardedProductActivity extends Activity
{
    public $guarded = ['log_name'];
}
