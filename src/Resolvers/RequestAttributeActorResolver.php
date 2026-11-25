<?php

declare(strict_types=1);

namespace Qiick\Audit\Resolvers;

use BackedEnum;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Contracts\AuditActorResolver;

/**
 * The default AuditActorResolver (design §4).
 *
 * 1. A credential bound through AuditContext::bindCredential() wins.
 * 2. With qiick/laravel-access installed (a suggest, not a dependency), the
 *    actor kind and the user come from its ActorResolver.
 * 3. Otherwise the signed-in user is a web session causer, and no user is
 *    unattended.
 *
 * A signed-in user that is not an Eloquent model cannot be a morph causer,
 * so it is recorded as the actor kind only.
 *
 * @api
 */
final class RequestAttributeActorResolver implements AuditActorResolver
{
    /** laravel-access's resolver, referenced by name so it stays optional. */
    private const string ACCESS_ACTOR_RESOLVER = 'Qiick\Access\Contracts\ActorResolver';

    public function __construct(
        private readonly Container $container,
        private readonly AuthFactory $auth,
    ) {}

    public function current(): AuditActor
    {
        $credential = AuditContext::credential();

        if ($credential !== null) {
            return $credential;
        }

        if ($this->container->bound(self::ACCESS_ACTOR_RESOLVER)) {
            return $this->fromAccessContext();
        }

        $user = $this->auth->guard()->user();

        if ($user === null) {
            return new AuditActor(null, 'unattended');
        }

        return new AuditActor($user instanceof Model ? $user : null, 'web_session');
    }

    private function fromAccessContext(): AuditActor
    {
        $accessResolver = $this->container->make(self::ACCESS_ACTOR_RESOLVER);

        if (! is_object($accessResolver) || ! method_exists($accessResolver, 'current')) {
            throw new LogicException('The laravel-access ActorResolver must return an ActorContext with an ActorKind.');
        }

        $actorContext = $accessResolver->current();

        if (! is_object($actorContext)
            || ! property_exists($actorContext, 'kind')
            || ! $actorContext->kind instanceof BackedEnum
            || ! is_string($actorContext->kind->value)) {
            throw new LogicException('The laravel-access ActorResolver must return an ActorContext with an ActorKind.');
        }

        $user = property_exists($actorContext, 'user') ? $actorContext->user : null;

        return new AuditActor($user instanceof Model ? $user : null, $actorContext->kind->value);
    }
}
