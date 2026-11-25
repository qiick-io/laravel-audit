<?php

declare(strict_types=1);

namespace Qiick\Audit\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LogicException;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Support\ActivityAttributor;
use Qiick\Audit\Support\RetentionPurger;
use Qiick\Audit\Support\SecretAttributeFloor;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;

/**
 * Spatie's logging action, configured as `activitylog.actions.log_activity`.
 *
 * Spatie's buffer inserts the raw attributes of the activity without firing
 * model events, so the `creating` hook that fills `public_id` never runs on
 * that path (design §6.3 spike). The action fills the model's unique ids
 * before either path stores the row.
 *
 * It writes the server-side attribution last, after every product
 * beforeLogging callback (ActivityAttributor), so the server always wins. It
 * redacts secret values in attribute_changes, resolves description
 * placeholders through the secret floor, and replaces any public_id a
 * caller set.
 *
 * It also writes `schema_version` itself rather than relying on the column
 * default: externa's adopted table defaults to `0` (legacy rows), and every
 * row this package writes is the current format (design §3.1, §6.2).
 *
 * @api A product may extend it; boot fails unless the configured action does.
 */
class LogActivityAction extends SpatieLogActivityAction
{
    protected function save(Model $activity): void
    {
        if (! $activity instanceof Activity) {
            throw new LogicException('activitylog.activity_model must extend '.Activity::class.'.');
        }

        app(ActivityAttributor::class)->attribute($activity);

        // Server wins: a public_id a caller set through tap() is replaced.
        $activity->setAttribute('public_id', null);
        $activity->setUniqueIds();
        $activity->setAttribute('schema_version', Activity::currentSchemaVersion());

        // One spelling per log, so the retention floors match on every engine.
        $logName = $activity->getAttribute('log_name');

        if (is_string($logName)) {
            $activity->setAttribute('log_name', RetentionPurger::normaliseLogName($logName));
        }
        $this->redactSecretChanges($activity);

        parent::save($activity);
    }

    /**
     * The last step before a row is stored, after every product beforeLogging
     * callback: secret values in attribute_changes become `[redacted]`, so
     * the change is recorded without its value (SecretAttributeFloor). The
     * subject is read only when it is already set on the row.
     */
    private function redactSecretChanges(Model $activity): void
    {
        $attributeChanges = $activity->getAttribute('attribute_changes');

        if (! $attributeChanges instanceof Collection) {
            return;
        }

        $activity->setAttribute('attribute_changes', collect(SecretAttributeFloor::configured()->redactChanges(
            $attributeChanges->all(),
            $activity->relationLoaded('subject') ? $activity->getRelation('subject') : null,
        )));
    }

    /**
     * Spatie's `:subject.x`, `:causer.x` and `:properties.x` placeholders,
     * read safely. A description can carry text a user typed, so a
     * placeholder never calls a model method (review B3): it reads only
     * attributes the model holds and relations already loaded on it. A
     * placeholder that names a secret (SecretAttributeFloor), goes through a
     * relation that is not loaded, or resolves to anything but a scalar is
     * left as written.
     */
    protected function replacePlaceholders(string $description, Model $activity): string
    {
        $secretFloor = SecretAttributeFloor::configured();

        return preg_replace_callback('/:[a-z0-9._-]+(?<![.])/i', function (array $match) use ($activity, $secretFloor): string {
            $placeholder = $match[0];
            $source = Str::before(substr($placeholder, 1), '.');
            $path = Str::after($placeholder, '.');

            if (! in_array($source, ['subject', 'causer', 'properties'], true)) {
                return $placeholder;
            }

            $target = $activity->getAttribute($source);
            $value = $target instanceof Model
                ? self::loadedAttribute($target, $path, $secretFloor)
                : ($secretFloor->namesAFloorKey(explode('.', $path)) ? null : data_get($target, $path));

            return is_scalar($value) ? (string) $value : $placeholder;
        }, $description) ?? $description;
    }

    /**
     * @param  string  $path  loaded relation names, then the attribute, dot-separated
     */
    private static function loadedAttribute(Model $model, string $path, SecretAttributeFloor $secretFloor): mixed
    {
        $segments = explode('.', $path);
        $attribute = array_pop($segments);

        foreach ($segments as $relationName) {
            $model = $model->relationLoaded($relationName) ? $model->getRelation($relationName) : null;

            if (! $model instanceof Model) {
                return null;
            }
        }

        if (! array_key_exists($attribute, $model->getAttributes()) || $secretFloor->isSecret($model, $attribute)) {
            return null;
        }

        return $model->getAttribute($attribute);
    }
}
