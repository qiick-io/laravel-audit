<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The organisation perimeter of an activity row (design §4). Products bind
 * their domain logic here, taking the organisation from the current team
 * context, never from a user's "first" membership (A4). Asked only when the
 * row has no explicit organisation (Audit::inOrganisation()).
 *
 * @api
 */
interface OrganisationResolver
{
    /** The organisation UUID, or null for an instance-level row. */
    public function resolve(?Model $subject, ?Model $causer): ?string;
}
