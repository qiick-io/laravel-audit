<?php

declare(strict_types=1);

use Qiick\Audit\Audit;
use Qiick\Audit\Models\Activity;

/*
 * Audit::inOrganisation() is the explicit organisation stamp (design §4),
 * which replaces the "organisation_id in properties wins" path.
 */

it('stamps the organisation on the activity, lower-cased', function (): void {
    $activity = new Activity;

    (Audit::inOrganisation('0193A3B0-7C1D-7000-8000-0000000000AA'))($activity);

    expect($activity->organisation_id)->toBe('0193a3b0-7c1d-7000-8000-0000000000aa');
});

it('refuses an organisation that is not a UUID', function (string $organisationId): void {
    expect(fn () => Audit::inOrganisation($organisationId))
        ->toThrow(InvalidArgumentException::class, 'organisation_id must be a UUID.');
})->with(['', '12', 'acme']);
