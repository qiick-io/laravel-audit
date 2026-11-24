<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;

/** A model with an auto-increment bigint key, like a product's users row. */
final class IntegerKeyedRecord extends Model
{
    protected $table = 'integer_keyed_records';

    protected $guarded = [];
}
