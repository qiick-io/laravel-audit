<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Model;
use Qiick\Audit\Concerns\LogsActivity;

/** A model with JSON columns logged whole by the trait's defaults. */
final class AuditedAccountWithJson extends Model
{
    use LogsActivity;

    protected $table = 'audited_accounts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'options' => AsCollection::class,
            'flags' => AsArrayObject::class,
        ];
    }
}
