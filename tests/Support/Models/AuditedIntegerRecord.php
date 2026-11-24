<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Qiick\Audit\Concerns\LogsActivity;

/** A bigint-keyed model that logs through the package trait. */
final class AuditedIntegerRecord extends Model
{
    use LogsActivity;

    protected $table = 'integer_keyed_records';

    protected $guarded = [];
}
