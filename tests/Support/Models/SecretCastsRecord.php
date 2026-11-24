<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Casts\AsEncryptedCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Every secret cast Laravel offers, next to ordinary ones. */
final class SecretCastsRecord extends Model
{
    public static int $untypedMethodCalls = 0;

    public function untypedMethod()
    {
        self::$untypedMethodCalls++;

        return null;
    }

    /**
     * @return BelongsTo<IntegerKeyedRecord, $this>|null
     */
    public function maybeRelation(): ?BelongsTo
    {
        return null;
    }

    public function notARelation(): string
    {
        return 'not a relation';
    }

    protected function casts(): array
    {
        return [
            'encrypted_value' => 'encrypted',
            'encrypted_array' => 'encrypted:array',
            'encrypted_collection' => 'encrypted:collection',
            'encrypted_object' => 'encrypted:object',
            'hashed_value' => 'hashed',
            'Mixed_Case_Value' => 'encrypted',
            'encrypted_array_object' => AsEncryptedArrayObject::class,
            'encrypted_collection_class' => AsEncryptedCollection::class,
            'plain_array' => 'array',
            'session_started' => 'datetime',
            'password_reset_day' => 'date:Y-m-d',
            'two_factor_enabled' => 'boolean',
            'token_count' => 'integer',
            'secret_version' => 'int',
            'secret_label' => 'string',
            'secret_shared' => 'bool',
            'token_issued' => 'timestamp',
            'secret_day' => 'immutable_date',
            'session_closed' => 'immutable_datetime',
            'pin' => 'integer',
        ];
    }
}
