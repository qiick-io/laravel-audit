<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use LogicException;
use Qiick\Audit\Models\Activity;
use Spatie\Activitylog\Support\ActivityLogger as SpatieActivityLogger;

/**
 * Spatie's logger, bound in its place by the package provider.
 *
 * Spatie decides whether a causer is already set by reading
 * getAttribute('causer_id') (Support/ActivityLogger.php:229), the one
 * hard-coded morph column on its write path. With `audit.columns` renamed
 * (design §6.3, externa 1.x) that read would always see null and replace a
 * set causer with the default one; this logger reads the configured column.
 *
 * @internal
 */
class ActivityLogger extends SpatieActivityLogger
{
    protected function useDefaultCauserIfNoneWasGiven(): void
    {
        $activity = $this->getActivity();

        if ($this->causerWasSet) {
            return;
        }

        if (! $activity instanceof Activity) {
            throw new LogicException('The activity logger needs an activity model that extends '.Activity::class.'.');
        }

        if ($activity->getAttribute(Activity::idColumn('causer_id')) !== null) {
            return;
        }

        $activity->causer()->associate($this->causerResolver->resolve());
    }
}
