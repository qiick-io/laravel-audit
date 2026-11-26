<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Contracts\AuditActorResolver;
use Qiick\Audit\Resolvers\RequestAttributeActorResolver;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;

/*
 * The default AuditActorResolver (design §4): a bound credential wins; else
 * the signed-in user is the causer, and actor_kind comes from laravel-access's
 * ActorContext when that package is installed (a suggest).
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

function accessResolverReturning(ActorContext $context): ActorResolver
{
    return new readonly class($context) implements ActorResolver
    {
        public function __construct(private ActorContext $context) {}

        public function current(): ActorContext
        {
            return $this->context;
        }
    };
}

it('is the default AuditActorResolver', function (): void {
    expect(app(AuditActorResolver::class))->toBeInstanceOf(RequestAttributeActorResolver::class);
});

it('is unattended without a user, a credential or laravel-access', function (): void {
    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe('unattended')
        ->and($actor->causer)->toBeNull();
});

it('makes the signed-in user a web session causer without laravel-access', function (): void {
    $user = AuthenticatableRecord::query()->create(['name' => 'web']);
    $this->actingAs($user);

    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe('web_session')
        ->and($actor->causer?->is($user))->toBeTrue();
});

it('returns the bound credential first', function (): void {
    $this->actingAs(AuthenticatableRecord::query()->create(['name' => 'web']));
    $credential = new AuditActor(new AuthenticatableRecord, 'api_key', '0193a3b0-7c1d-7000-8000-000000000001');
    AuditContext::bindCredential($credential);

    expect(app(AuditActorResolver::class)->current())->toBe($credential);
});

it('takes actor_kind and the causer from laravel-access when it is installed', function (ActorKind $actorKind): void {
    $accessUser = AuthenticatableRecord::query()->create(['name' => 'access user']);
    $this->actingAs(AuthenticatableRecord::query()->create(['name' => 'guard user']));
    app()->instance(ActorResolver::class, accessResolverReturning(new ActorContext($actorKind, $accessUser)));

    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe($actorKind->value)
        ->and($actor->causer?->is($accessUser))->toBeTrue();
})->with(ActorKind::cases());

it('has no causer when laravel-access has no user', function (): void {
    $this->actingAs(AuthenticatableRecord::query()->create(['name' => 'guard user']));
    app()->instance(ActorResolver::class, accessResolverReturning(new ActorContext(ActorKind::Unattended, null)));

    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe('unattended')
        ->and($actor->causer)->toBeNull();
});

it('refuses a laravel-access resolver that does not return an ActorContext', function (mixed $context): void {
    app()->instance(ActorResolver::class, new readonly class($context)
    {
        public function __construct(private mixed $context) {}

        public function current(): mixed
        {
            return $this->context;
        }
    });

    expect(fn () => app(AuditActorResolver::class)->current())
        ->toThrow(LogicException::class, 'The laravel-access ActorResolver must return an ActorContext with an ActorKind.');
})->with([
    'null' => [null],
    'object without kind' => [new stdClass],
    'string kind' => [(object) ['kind' => 'web_session', 'user' => null]],
]);

it('ignores a signed-in user that is not an Eloquent model', function (): void {
    $this->actingAs(new GenericUser(['id' => 5]));

    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe('web_session')
        ->and($actor->causer)->toBeNull();
});

it('refuses a laravel-access binding that is not a resolver', function (mixed $binding): void {
    app()->instance(ActorResolver::class, $binding);

    expect(fn () => app(AuditActorResolver::class)->current())
        ->toThrow(LogicException::class, 'The laravel-access ActorResolver must return an ActorContext with an ActorKind.');
})->with([
    'object without current()' => [new stdClass],
    'string' => ['resolver'],
]);

it('ignores a laravel-access user that is not an Eloquent model', function (): void {
    app()->instance(ActorResolver::class, accessResolverReturning(
        new ActorContext(ActorKind::WebSession, new GenericUser(['id' => 5])),
    ));

    $actor = app(AuditActorResolver::class)->current();

    expect($actor->actorKind)->toBe('web_session')
        ->and($actor->causer)->toBeNull();
});
