<?php

declare(strict_types=1);

namespace Qiick\Audit\Resolvers;

use Illuminate\Database\Eloquent\Model;
use Qiick\Audit\Contracts\OrganisationResolver;

/**
 * The default OrganisationResolver: every row is instance-level unless the
 * caller stamps an organisation explicitly. Products with organisations bind
 * their own resolver.
 *
 * @api
 */
final class NullOrganisationResolver implements OrganisationResolver
{
    public function resolve(?Model $subject, ?Model $causer): ?string
    {
        return null;
    }
}
