<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A model with a UUID primary key, like an OAuth client or a principal. */
final class UuidKeyedRecord extends Model
{
    use HasUuids;

    protected $table = 'uuid_keyed_records';

    protected $guarded = [];
}
