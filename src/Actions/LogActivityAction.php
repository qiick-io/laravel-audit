<?php

declare(strict_types=1);

namespace Qiick\Audit\Actions;

use Illuminate\Database\Eloquent\Model;
use Qiick\Audit\Models\Activity;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;

/**
 * Spatie's logging action, configured as `activitylog.actions.log_activity`.
 *
 * Spatie's buffer inserts the raw attributes of the activity without firing
 * model events, so the `creating` hook that fills `public_id` never runs on
 * that path (design §6.3 spike). The action fills the model's unique ids
 * before either path stores the row.
 *
 * It also writes `schema_version` itself rather than relying on the column
 * default: externa's adopted table defaults to `0` (legacy rows), and every
 * row this package writes is the current format (design §3.1, §6.2).
 */
class LogActivityAction extends SpatieLogActivityAction
{
    protected function save(Model $activity): void
    {
        $activity->setUniqueIds();
        $activity->setAttribute('schema_version', Activity::currentSchemaVersion());

        parent::save($activity);
    }
}
