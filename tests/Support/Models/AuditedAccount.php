<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Audit\Concerns\LogsActivity;

/**
 * A model with every kind of secret the floor must keep out of the log:
 * an encrypted cast, an encrypted:array cast, a hashed cast and floor names.
 */
final class AuditedAccount extends Model
{
    use LogsActivity;

    protected $table = 'audited_accounts';

    protected $guarded = [];

    /**
     * @return BelongsTo<IntegerKeyedRecord, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(IntegerKeyedRecord::class, 'owner_id');
    }

    /**
     * @return BelongsTo<AuditedAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_account_id');
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'recovery_settings' => 'encrypted:array',
            'pin' => 'hashed',
        ];
    }
}
