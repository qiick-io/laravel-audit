<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** A model with a ULID primary key. */
final class UlidKeyedRecord extends Model
{
    use HasUlids;

    protected $table = 'ulid_keyed_records';

    protected $guarded = [];
}
