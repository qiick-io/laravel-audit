<?php

declare(strict_types=1);

use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * AuditActor carries server-verified attribution only (design §3.1, §4).
 * A6 / C13: api_key_id and oauth_client_id are public UUIDs, and
 * api_key_created_by is a public id, never a bigint.
 */

const KEY_UUID = '0193a3b0-7c1d-7000-8000-000000000001';
const CLIENT_UUID = '0193a3b0-7c1d-7000-8000-000000000002';

it('lists the ActorKind values of laravel-access', function (): void {
    expect(AuditActor::actorKinds())->toBe(['web_session', 'api_key', 'oauth_grant', 'public_api', 'unattended']);
});

it('accepts every actor kind', function (string $actorKind): void {
    expect((new AuditActor(null, $actorKind))->actorKind)->toBe($actorKind);
})->with(['web_session', 'api_key', 'oauth_grant', 'public_api', 'unattended']);

it('refuses an unknown actor kind', function (string $actorKind): void {
    expect(fn () => new AuditActor(null, $actorKind))
        ->toThrow(InvalidArgumentException::class, "Unknown actor kind [{$actorKind}].");
})->with(['', 'WEB_SESSION', 'service', 'apikey']);

it('keeps the credential attribution', function (): void {
    $owner = new IntegerKeyedRecord;
    $actor = new AuditActor($owner, 'api_key', strtoupper(KEY_UUID), 'qk_live_ab12', 'usr_01JDZ', CLIENT_UUID);

    expect($actor->causer)->toBe($owner)
        ->and($actor->apiKeyId)->toBe(KEY_UUID)
        ->and($actor->apiKeyPrefix)->toBe('qk_live_ab12')
        ->and($actor->apiKeyCreatedBy)->toBe('usr_01JDZ')
        ->and($actor->oauthClientId)->toBe(CLIENT_UUID);
});

it('refuses an api_key_id that is not a UUID (A6)', function (string $apiKeyId): void {
    expect(fn () => new AuditActor(null, 'api_key', $apiKeyId))
        ->toThrow(InvalidArgumentException::class, 'api_key_id must be the key\'s public UUID.');
})->with(['42', '', 'not-a-uuid', '00000000-0000-0000-0000-000000000000']);

it('refuses an oauth_client_id that is not a UUID', function (string $oauthClientId): void {
    expect(fn () => new AuditActor(null, 'oauth_grant', oauthClientId: $oauthClientId))
        ->toThrow(InvalidArgumentException::class, 'oauth_client_id must be the client\'s UUID.');
})->with(['7', '', '00000000-0000-0000-0000-000000000000']);

it('refuses an api_key_created_by that is a bigint or empty (A6)', function (string $apiKeyCreatedBy): void {
    expect(fn () => new AuditActor(null, 'api_key', apiKeyCreatedBy: $apiKeyCreatedBy))
        ->toThrow(InvalidArgumentException::class, 'api_key_created_by must be the creator\'s public id, never an internal id.');
})->with(['42', '0', '9223372036854775807', '', ' ']);

it('refuses an empty api_key_prefix', function (): void {
    expect(fn () => new AuditActor(null, 'api_key', apiKeyPrefix: ''))
        ->toThrow(InvalidArgumentException::class, 'api_key_prefix must not be empty.');
});

it('tells a credential actor apart', function (string $actorKind, bool $isCredential): void {
    expect((new AuditActor(null, $actorKind))->isCredential())->toBe($isCredential);
})->with([
    ['api_key', true],
    ['oauth_grant', true],
    ['web_session', false],
    ['public_api', false],
    ['unattended', false],
]);
