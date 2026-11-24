<?php

declare(strict_types=1);

namespace Qiick\Audit\Support;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Contracts\AuditActorResolver;
use Qiick\Audit\Contracts\OrganisationResolver;
use Qiick\Audit\Models\Activity;

/**
 * Writes the server-side attribution of a row just before it is stored
 * (design §3.1, §4), after every product beforeLogging callback, so the
 * server always wins:
 *
 * - the reserved property keys, with the ip and user agent of the routed
 *   HTTP request only (none in a console command or a queued job);
 *   whatever the caller put there is removed, and only the server's values
 *   are written (A2);
 * - the causer rule: when a credential, a web session or another actor with
 *   a causer acts, that causer replaces the caller's; an unattended job keeps
 *   the causer it set, or none;
 * - the organisation: the explicit one (Audit::inOrganisation()) or the
 *   OrganisationResolver's, never properties (A3), always a UUID;
 * - the request id, from the `request_id` key of Laravel's context (the key
 *   qiick/laravel-http-basics sets), when it is a safe token of 64
 *   characters at most.
 *
 * @internal
 */
final class ActivityAttributor
{
    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config,
        private readonly AuditActorResolver $actorResolver,
        private readonly OrganisationResolver $organisationResolver,
    ) {}

    public function attribute(Activity $activity): void
    {
        $actor = $this->actorResolver->current();

        if ($actor->actorKind !== 'unattended' && $actor->causer !== null) {
            $activity->causer()->associate($actor->causer);
        }

        $activity->properties = $this->propertiesWithAttribution($activity->properties, $actor);
        $activity->organisation_id = $this->organisationId($activity);
        $activity->request_id = $this->requestId();
    }

    /**
     * @param  Collection<array-key, mixed>|null  $properties
     * @return Collection<array-key, mixed>
     */
    private function propertiesWithAttribution(?Collection $properties, AuditActor $actor): Collection
    {
        $request = AuditContext::httpRequest();
        $userAgent = $request?->userAgent();

        // Every reserved key (design §3.1) with the server's value, or null
        // when the server has none: the key is removed either way.
        $reservedValues = [
            'ip' => $request?->ip(),
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, $this->userAgentMax()),
            'actor_kind' => $actor->actorKind,
            'api_key_id' => $actor->apiKeyId,
            'api_key_prefix' => $actor->apiKeyPrefix,
            'api_key_created_by' => $actor->apiKeyCreatedBy,
            'oauth_client_id' => $actor->oauthClientId,
        ];

        return collect($properties ?? [])
            ->except(array_keys($reservedValues))
            ->merge(array_filter($reservedValues, fn (?string $value): bool => $value !== null));
    }

    private function organisationId(Activity $activity): ?string
    {
        $organisationId = $activity->organisation_id
            ?? $this->organisationResolver->resolve($activity->subject, $activity->causer);

        if ($organisationId === null) {
            return null;
        }

        if (! Str::isUuid($organisationId)) {
            throw new InvalidArgumentException('organisation_id must be a UUID.');
        }

        return strtolower($organisationId);
    }

    private function requestId(): ?string
    {
        $requestId = $this->container->make(ContextRepository::class)->get('request_id');

        if (! is_string($requestId) || preg_match('/\A[A-Za-z0-9._:-]{1,64}\z/', $requestId) !== 1) {
            return null;
        }

        return $requestId;
    }

    private function userAgentMax(): int
    {
        $userAgentMax = $this->config->get('audit.user_agent_max');

        if (! is_int($userAgentMax) || $userAgentMax < 1) {
            throw new InvalidArgumentException('audit.user_agent_max must be a positive integer.');
        }

        return $userAgentMax;
    }
}
