<?php

declare(strict_types=1);

namespace Qiick\Audit\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Relations\StringKeyedMorphMany;

/**
 * Spatie's CausesActivity, string-safe: activitiesAsCauser() reads the
 * configured causer id column (audit.columns) and binds the model key as a
 * string, so a bigint-keyed causer works on PostgreSQL (A11), where Spatie's
 * morphMany fails with `character varying = integer`.
 *
 * @api
 */
trait CausesActivity
{
    /**
     * @return MorphMany<Activity, $this>
     */
    public function activitiesAsCauser(): MorphMany
    {
        return StringKeyedMorphMany::ofActivities($this, 'causer');
    }
}
