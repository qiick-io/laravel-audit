<?php

declare(strict_types=1);

namespace Qiick\Audit\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Qiick\Audit\Models\Activity;
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
 * It resolves description placeholders through the secret floor, and it
 * replaces any public_id a caller set.
 *
 * It also writes `schema_version` itself rather than relying on the column
 * default: externa's adopted table defaults to `0` (legacy rows), and every
 * row this package writes is the current format (design §3.1, §6.2).
 */
class LogActivityAction extends SpatieLogActivityAction
{
    protected function save(Model $activity): void
    {
        // Server wins: a public_id a caller set through tap() is replaced.
        $activity->setAttribute('public_id', null);
        $activity->setUniqueIds();
        $activity->setAttribute('schema_version', Activity::currentSchemaVersion());

        parent::save($activity);
    }

    /**
     * Spatie's `:subject.x`, `:causer.x` and `:properties.x` placeholders,
     * minus what the secret floor forbids: a placeholder whose path is secret
     * for the model (SecretAttributeFloor), names a floor key in properties,
     * or resolves to anything but a scalar (a related model, a JSON value) is
     * left as written, so a description never carries a decrypted secret.
     */
    protected function replacePlaceholders(string $description, Model $activity): string
    {
        $secretFloor = SecretAttributeFloor::fromConfig(config('audit.sensitive_attributes'));

        return preg_replace_callback('/:[a-z0-9._-]+(?<![.])/i', function (array $match) use ($activity, $secretFloor): string {
            $placeholder = $match[0];
            $source = Str::before(Str::after($placeholder, ':'), '.');
            $path = Str::after($placeholder, '.');

            if (! in_array($source, ['subject', 'causer', 'properties'], true)) {
                return $placeholder;
            }

            $target = $activity->getAttribute($source);
            $isSecret = $target instanceof Model
                ? $secretFloor->isSecret($target, $path)
                : $secretFloor->namesAFloorKey(explode('.', $path));

            $value = $isSecret ? null : data_get($target, $path);

            return is_scalar($value) ? (string) $value : $placeholder;
        }, $description) ?? $description;
    }
}
