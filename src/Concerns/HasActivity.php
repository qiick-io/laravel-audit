<?php

declare(strict_types=1);

namespace Qiick\Audit\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Qiick\Audit\Models\Activity;

/**
 * The package's LogsActivity (with the secret floor) and CausesActivity in
 * one trait, in place of Spatie's HasActivity, which would bring Spatie's
 * LogsActivity without the floor.
 *
 * @api
 */
trait HasActivity
{
    use CausesActivity;
    use LogsActivity;

    /**
     * @return MorphMany<Activity, $this>
     */
    public function activities(): MorphMany
    {
        return $this->activitiesAsSubject();
    }
}
