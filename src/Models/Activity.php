<?php

declare(strict_types=1);

namespace Qiick\Audit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An audit row of the shared activity_log table (design §3).
 *
 * Extends Spatie's model, so Spatie's logger, relations and scopes keep
 * working. The bigint `id` stays internal; `public_id` (UUID v7, RFC 9562) is
 * the id resources and exports expose. Products may extend this class and
 * must keep extending it.
 *
 * @property string $public_id
 * @property string|null $organisation_id
 * @property int $schema_version
 * @property string|null $request_id
 * @property CarbonImmutable|null $anonymised_at
 *
 * @api
 */
class Activity extends SpatieActivity
{
    use HasUuids;

    /**
     * The row format this package writes (design §3.1). `0` is the legacy
     * externa shape; readers branch on the column before reading api_key_id.
     */
    public static function currentSchemaVersion(): int
    {
        return 1;
    }

    /**
     * Only public_id is generated: the primary key stays an auto-increment.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function newUniqueId(): string
    {
        return (string) Str::uuid7();
    }

    /**
     * @return array<string, \Stringable|string>
     */
    protected function casts(): array
    {
        return [
            ...parent::casts(),
            'anonymised_at' => 'immutable_datetime',
        ];
    }
}
