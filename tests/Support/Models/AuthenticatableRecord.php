<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;

/** A bigint-keyed user for the default causer and the web session actor. */
final class AuthenticatableRecord extends Model implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'integer_keyed_records';

    protected $guarded = [];
}
