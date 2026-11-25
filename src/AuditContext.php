<?php

declare(strict_types=1);

namespace Qiick\Audit;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Qiick\Audit\Contracts\AuditActor;

/**
 * The credential the server verified for the current request or job
 * (design §4). The API key or OAuth middleware calls bindCredential() once
 * the credential is verified; every row logged afterwards is attributed to
 * the credential's owner principal and names the credential.
 *
 * It also remembers the routed HTTP request, the only source of the ip and
 * user agent a row records.
 *
 * The state lives in a scoped container instance, so it is forgotten at the
 * end of every request and job (Octane included).
 *
 * @api
 */
final class AuditContext
{
    private ?AuditActor $credential = null;

    private ?Request $httpRequest = null;

    public static function bindCredential(AuditActor $credential): void
    {
        if (! $credential->isCredential()) {
            throw new InvalidArgumentException('Only an api_key or oauth_grant credential can be bound.');
        }

        if ($credential->causer === null) {
            throw new InvalidArgumentException('A bound credential needs its owner principal as causer.');
        }

        if ($credential->actorKind === 'api_key' && $credential->apiKeyId === null) {
            throw new InvalidArgumentException('An api_key credential needs its api_key_id.');
        }

        if ($credential->actorKind === 'oauth_grant' && $credential->oauthClientId === null) {
            throw new InvalidArgumentException('An oauth_grant credential needs its oauth_client_id.');
        }

        self::instance()->credential = $credential;
    }

    public static function credential(): ?AuditActor
    {
        return self::instance()->credential;
    }

    public static function clear(): void
    {
        self::instance()->credential = null;
    }

    /**
     * Remembers the routed HTTP request whose ip and user agent the rows of
     * this request record. Called by the package on RouteMatched, so a
     * console command or a queued job (whose container `request` is a
     * placeholder with 127.0.0.1 and "Symfony") never records request data.
     *
     * @internal
     */
    public static function rememberHttpRequest(Request $request): void
    {
        self::instance()->httpRequest = $request;
    }

    /** @internal */
    public static function httpRequest(): ?Request
    {
        return self::instance()->httpRequest;
    }

    private static function instance(): self
    {
        return Container::getInstance()->make(self::class);
    }
}
