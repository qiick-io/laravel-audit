<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Audit\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A model that overrides the options: it names its log, adds an exclude and
 * tries to log the floor explicitly. The floor still wins.
 */
final class AuditedAccountWithOptions extends Model
{
    use LogsActivity;

    protected $table = 'audited_accounts';

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'password', 'secret', 'api_token', 'settings->secret', 'parentAccount.password'])
            ->logExcept(['email'])
            ->useLogName('accounts');
    }

    /**
     * @return BelongsTo<AuditedAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(AuditedAccount::class, 'parent_account_id');
    }

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'settings' => 'array',
        ];
    }
}
