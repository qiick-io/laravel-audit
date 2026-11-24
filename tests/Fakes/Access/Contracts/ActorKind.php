<?php

declare(strict_types=1);

namespace Qiick\Access\Contracts;

/**
 * Test double of qiick/laravel-access's ActorKind (a `suggest` of this
 * package, not installed in its CI). Same cases and values as
 * laravel-access `src/Contracts/ActorKind.php`.
 */
enum ActorKind: string
{
    case WebSession = 'web_session';
    case ApiKey = 'api_key';
    case OAuthGrant = 'oauth_grant';
    case PublicApi = 'public_api';
    case Unattended = 'unattended';
}
