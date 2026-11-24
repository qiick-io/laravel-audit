<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Audit\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A model whose logged attribute paths each test chooses. */
final class AuditedAccountLoggingPaths extends Model
{
    use LogsActivity;

    /** @var list<string> */
    public static array $paths = ['name'];

    protected $table = 'audited_accounts';

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(self::$paths);
    }

    /**
     * @return BelongsTo<AuditedAccount, $this>
     */
    public function parentAccount(): BelongsTo
    {
        return $this->belongsTo(AuditedAccount::class, 'parent_account_id');
    }
}
