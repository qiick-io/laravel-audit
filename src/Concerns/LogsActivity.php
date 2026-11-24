<?php

declare(strict_types=1);

namespace Qiick\Audit\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Relations\StringKeyedMorphMany;
use Qiick\Audit\Support\SecretAttributeFloor;
use Spatie\Activitylog\Models\Concerns\LogsActivity as SpatieLogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Spatie's LogsActivity with the fleet defaults and a secret floor (design
 * §4). Replaces each product's LogsApplicationActivityTrait.
 *
 * Defaults: every attribute, only the dirty ones, no empty changes, the
 * `default` log. A model overrides getActivitylogOptions() for its log name
 * and extra excludes; whatever it returns, the floor drops every attribute
 * cast as encrypted* or hashed and every `audit.sensitive_attributes` name
 * (SecretAttributeFloor), so the old per-model denylist fails closed.
 *
 * activitiesAsSubject() reads the configured subject id column and binds the
 * model key as a string, so it works on PostgreSQL for bigint keys (A11).
 *
 * @api
 */
trait LogsActivity
{
    use SpatieLogsActivity {
        attributesToBeLogged as spatieAttributesToBeLogged;
        extractChanges as spatieExtractChanges;
    }

    /**
     * Spatie's resolved values for create, update (new and old) and delete,
     * without related models and without floor keys nested in JSON values.
     *
     * @return array<array-key, mixed>
     */
    public static function extractChanges(Model $model): array
    {
        return SecretAttributeFloor::fromConfig(config('audit.sensitive_attributes'))
            ->withoutSecretValues(static::spatieExtractChanges($model));
    }

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activitiesAsSubject(): MorphMany
    {
        $activity = Activity::newConfiguredModel();

        // As Laravel's own relations do: the activity row follows the model's
        // connection unless the activity model names its own.
        if ($activity->getConnectionName() === null) {
            $activity->setConnection($this->getConnectionName());
        }

        return new StringKeyedMorphMany(
            $activity->newQuery(),
            $this,
            $activity->qualifyColumn('subject_type'),
            $activity->qualifyColumn(Activity::idColumn('subject_id')),
            $this->getKeyName(),
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return $this->auditActivityLogOptions();
    }

    /**
     * The fleet defaults, for a model that overrides getActivitylogOptions()
     * to add a log name or excludes:
     * `return $this->auditActivityLogOptions()->logExcept(['notes']);`
     */
    protected function auditActivityLogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('default');
    }

    /**
     * @return list<string>
     */
    public function attributesToBeLogged(): array
    {
        return SecretAttributeFloor::fromConfig(config('audit.sensitive_attributes'))
            ->withoutSecrets($this, $this->spatieAttributesToBeLogged());
    }
}
