<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Qiick\Audit\Models\Activity;

/** An activity model kept on its own connection. */
final class AuditConnectionActivity extends Activity
{
    protected $connection = 'audit';
}
