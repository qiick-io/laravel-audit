<?php

declare(strict_types=1);

namespace Qiick\Audit\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Who acted, as bound by the server after it verified a credential; never
 * request input (design §4).
 *
 * The ids it carries leave the database in `properties`, so they are public
 * ids only (C13, A6): `apiKeyId` and `oauthClientId` are UUIDs and
 * `apiKeyCreatedBy` is the creator's public id, never an internal bigint.
 *
 * @api
 */
// @pest-arch-ignore-next-line — design §4: readonly value object under Contracts/, not an interface
final readonly class AuditActor
{
    public ?string $apiKeyId;

    public ?string $oauthClientId;

    public function __construct(
        public ?Model $causer,
        public string $actorKind,
        ?string $apiKeyId = null,
        public ?string $apiKeyPrefix = null,
        public ?string $apiKeyCreatedBy = null,
        ?string $oauthClientId = null,
    ) {
        if (! in_array($actorKind, self::actorKinds(), true)) {
            throw new InvalidArgumentException("Unknown actor kind [{$actorKind}].");
        }

        if ($apiKeyPrefix === '') {
            throw new InvalidArgumentException('api_key_prefix must not be empty.');
        }

        if ($apiKeyCreatedBy !== null && (trim($apiKeyCreatedBy) === '' || ctype_digit($apiKeyCreatedBy))) {
            throw new InvalidArgumentException('api_key_created_by must be the creator\'s public id, never an internal id.');
        }

        $this->apiKeyId = self::publicUuid($apiKeyId, 'api_key_id must be the key\'s public UUID.');
        $this->oauthClientId = self::publicUuid($oauthClientId, 'oauth_client_id must be the client\'s UUID.');
    }

    /**
     * The ActorKind values of qiick/laravel-access (design §3.1).
     *
     * @return list<string>
     */
    public static function actorKinds(): array
    {
        return ['web_session', 'api_key', 'oauth_grant', 'public_api', 'unattended'];
    }

    /** A credential acts: an API key or an OAuth grant, whose owner is the causer. */
    public function isCredential(): bool
    {
        return in_array($this->actorKind, ['api_key', 'oauth_grant'], true);
    }

    private static function publicUuid(?string $uuid, string $message): ?string
    {
        if ($uuid === null) {
            return null;
        }

        $uuid = strtolower($uuid);

        if (! Str::isUuid($uuid) || $uuid === '00000000-0000-0000-0000-000000000000') {
            throw new InvalidArgumentException($message);
        }

        return $uuid;
    }
}
