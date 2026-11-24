<?php

declare(strict_types=1);

namespace Qiick\Audit;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Qiick\Audit\Models\Activity;

/**
 * Helpers for products that log through Spatie's `activity()`.
 *
 * @api
 */
final class Audit
{
    /**
     * The explicit organisation of a row (design §4):
     * `activity()->tap(Audit::inOrganisation($uuid))->log(...)`. It wins over
     * the OrganisationResolver; properties never set the column (A3).
     *
     * @return Closure(Activity): void
     */
    public static function inOrganisation(string $organisationId): Closure
    {
        if (! Str::isUuid($organisationId)) {
            throw new InvalidArgumentException('organisation_id must be a UUID.');
        }

        $organisationId = strtolower($organisationId);

        return function (Activity $activity) use ($organisationId): void {
            $activity->organisation_id = $organisationId;
        };
    }
}
