<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Qiick\Audit\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** A model that logs an ordinary attribute of a related model. */
final class AuditedAccountWithOwner extends Model
{
    use LogsActivity;

    protected $table = 'audited_accounts';

    protected $guarded = [];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['name', 'owner.name']);
    }

    /**
     * @return BelongsTo<IntegerKeyedRecord, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(IntegerKeyedRecord::class, 'owner_id');
    }
}
