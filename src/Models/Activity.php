<?php

declare(strict_types=1);

namespace Qiick\Audit\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * An audit row of the shared activity_log table (design §3).
 *
 * Extends Spatie's model, so Spatie's logger, relations and scopes keep
 * working. The bigint `id` stays internal; `public_id` (UUID v7, RFC 9562) is
 * the id resources and exports expose; serialisation hides `id` and writes
 * timestamps as RFC 3339 UTC (`2026-11-24T10:30:15.000000Z`). Products may extend this class and
 * must keep extending it.
 *
 * The morph relations and scopes read their id columns from `audit.columns`
 * (design §6.3: externa 1.x points them at `subject_ref` / `causer_ref`), and
 * the scopes bind ids as strings: the columns are string(64), and an integer
 * binding makes PostgreSQL refuse `varchar = integer` and MySQL compare the
 * column as a number (`'01' = 1`).
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
     * The bigint never leaves the database (design §3): serialised rows,
     * resources and exports carry public_id. Kept out even after
     * makeVisible('id').
     *
     * @return array<string>
     */
    public function getHidden(): array
    {
        return [...parent::getHidden(), 'id'];
    }

    /**
     * The package's columns are written by the package (the logging action,
     * the attributor, the anonymiser), never by mass assignment, even inside
     * Model::unguarded().
     *
     * @param  string  $key
     */
    public function isFillable($key): bool
    {
        return ! in_array($key, self::packageColumns(), true) && parent::isFillable($key);
    }

    /**
     * An audit row is written once. The refusal sits where Eloquent writes
     * an update or a delete, so it holds with or without model events
     * (saveQuietly(), deleteQuietly(), Model::withoutEvents()). Only the
     * anonymiser and the retention purge change rows, through the query
     * builder, which is outside the model: product code that runs
     * Activity::query()->update() or ->delete() is not stopped here.
     *
     * @param  Builder<static>  $query
     */
    protected function performUpdate(Builder $query): bool
    {
        self::refuseChange();
    }

    protected function performDeleteOnModel(): void
    {
        self::refuseChange();
    }

    private static function refuseChange(): never
    {
        throw new LogicException('Activity rows are written once: only the anonymiser and the retention purge change them.');
    }

    /**
     * @return list<string>
     */
    private static function packageColumns(): array
    {
        return ['id', 'public_id', 'organisation_id', 'schema_version', 'request_id', 'anonymised_at'];
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
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        $relation = $this->morphTo('subject', 'subject_type', self::idColumn('subject_id'));

        if (config('activitylog.include_soft_deleted_subjects') === true) {
            return $relation->withoutGlobalScope(SoftDeletingScope::class);
        }

        return $relation;
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function causer(): MorphTo
    {
        return $this->morphTo('causer', 'causer_type', self::idColumn('causer_id'));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCausedBy(Builder $query, Model $causer): Builder
    {
        return $query
            ->where('causer_type', $causer->getMorphClass())
            ->where(self::idColumn('causer_id'), self::stringKey($causer));
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject->getMorphClass())
            ->where(self::idColumn('subject_id'), self::stringKey($subject));
    }

    /**
     * Rows of one organisation (A5). Rows without an organisation never match:
     * only the instance super-admin sees them, through an unscoped query the
     * product guards.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForOrganisation(Builder $query, string $organisationId): Builder
    {
        if (! Str::isUuid($organisationId)) {
            throw new InvalidArgumentException('organisation_id must be a UUID.');
        }

        return $query->where('organisation_id', strtolower($organisationId));
    }

    /**
     * A new instance of `activitylog.activity_model`, which must be this class
     * or extend it: every guarantee of the package (public_id, string-safe
     * scopes, configured columns) lives here.
     */
    public static function newConfiguredModel(): self
    {
        $modelClass = config('activitylog.activity_model');

        if (! is_string($modelClass) || ! is_a($modelClass, self::class, true)) {
            throw new LogicException('activitylog.activity_model must extend '.self::class.'.');
        }

        return new $modelClass;
    }

    /**
     * The configured id column of a morph (design §6.3).
     *
     * @param  'subject_id'|'causer_id'  $defaultColumn
     */
    public static function idColumn(string $defaultColumn): string
    {
        $column = config("audit.columns.{$defaultColumn}");

        if (! is_string($column) || $column === '') {
            throw new InvalidArgumentException("audit.columns.{$defaultColumn} must name a column.");
        }

        return $column;
    }

    private static function stringKey(Model $model): string
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new InvalidArgumentException('An activity morph needs a model with an integer or string key.');
        }

        return (string) $key;
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
