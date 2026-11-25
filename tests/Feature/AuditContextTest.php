<?php

declare(strict_types=1);

use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * The key middleware binds the verified credential through
 * AuditContext::bindCredential() (design §4). The binding is scoped: it never
 * outlives the request or the job.
 */

function apiKeyActor(): AuditActor
{
    return new AuditActor(new IntegerKeyedRecord, 'api_key', '0193a3b0-7c1d-7000-8000-000000000001', 'qk_ab12', 'usr_1');
}

it('starts without a credential', function (): void {
    expect(AuditContext::credential())->toBeNull();
});

it('binds a verified credential', function (): void {
    $actor = apiKeyActor();

    AuditContext::bindCredential($actor);

    expect(AuditContext::credential())->toBe($actor);

    AuditContext::clear();

    expect(AuditContext::credential())->toBeNull();
});

it('forgets the credential when the scoped instances are reset', function (): void {
    AuditContext::bindCredential(apiKeyActor());

    app()->forgetScopedInstances();

    expect(AuditContext::credential())->toBeNull();
});

it('binds only credential actors with an owner principal and their id', function (AuditActor $actor, string $message): void {
    expect(fn () => AuditContext::bindCredential($actor))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'web session' => [fn () => new AuditActor(new IntegerKeyedRecord, 'web_session'), 'Only an api_key or oauth_grant credential can be bound.'],
    'unattended' => [fn () => new AuditActor(null, 'unattended'), 'Only an api_key or oauth_grant credential can be bound.'],
    'no owner' => [fn () => new AuditActor(null, 'api_key', '0193a3b0-7c1d-7000-8000-000000000001'), 'A bound credential needs its owner principal as causer.'],
    'key without id' => [fn () => new AuditActor(new IntegerKeyedRecord, 'api_key'), 'An api_key credential needs its api_key_id.'],
    'grant without client' => [fn () => new AuditActor(new IntegerKeyedRecord, 'oauth_grant'), 'An oauth_grant credential needs its oauth_client_id.'],
]);

it('binds an OAuth grant', function (): void {
    $actor = new AuditActor(new IntegerKeyedRecord, 'oauth_grant', oauthClientId: '0193a3b0-7c1d-7000-8000-000000000002');

    AuditContext::bindCredential($actor);

    expect(AuditContext::credential())->toBe($actor);
});
