<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Qiick\Audit\Concerns\HasActivity;

/** A bigint-keyed model that logs and causes activity through HasActivity. */
final class ActiveIntegerRecord extends Model
{
    use HasActivity;

    protected $table = 'integer_keyed_records';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['name' => 'string'];
    }
}
