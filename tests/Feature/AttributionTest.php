<?php

declare(strict_types=1);

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Qiick\Access\Contracts\ActorContext;
use Qiick\Access\Contracts\ActorKind;
use Qiick\Access\Contracts\ActorResolver;
use Qiick\Audit\Audit;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Contracts\OrganisationResolver;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Resolvers\NullOrganisationResolver;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;
use Qiick\Audit\Tests\Support\Models\UuidKeyedRecord;
use Spatie\Activitylog\Actions\LogActivityAction;
use Spatie\Activitylog\Support\ActivityBuffer;
use Spatie\Activitylog\Support\PendingActivityLog;

/*
 * Server-side attribution on every logged row (design §3.1, §4): the
 * reserved property keys, the causer rule, the organisation stamp and the
 * request id. The package writes them after every product beforeLogging
 * callback, so the server always wins.
 */

const API_KEY_UUID = '0193a3b0-7c1d-7000-8000-000000000001';
const OAUTH_CLIENT_UUID = '0193a3b0-7c1d-7000-8000-000000000002';

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

afterEach(function (): void {
    LogActivityAction::clearBeforeLoggingCallbacks();
});

/**
 * @return array<string, mixed>
 */
function loggedProperties(): array
{
    return Activity::query()->sole()->properties?->toArray() ?? [];
}

function bindApiKey(IntegerKeyedRecord $owner): void
{
    AuditContext::bindCredential(new AuditActor($owner, 'api_key', API_KEY_UUID, 'qk_live_ab12', 'usr_01JDZCREATOR'));
}

function registerLoggingRoute(Closure $logActivity): void
{
    Route::post('/audit-test', function () use ($logActivity) {
        $logActivity();

        return response()->noContent();
    });
}

it('records the request ip, the user agent and the actor kind of a web request', function (): void {
    $user = AuthenticatableRecord::query()->create(['name' => 'web']);
    registerLoggingRoute(fn () => activity()->log('web action'));

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'Browser/1.0')
        ->post('/audit-test')
        ->assertNoContent();

    expect(loggedProperties())->toEqual([
        'ip' => '203.0.113.9',
        'user_agent' => 'Browser/1.0',
        'actor_kind' => 'web_session',
    ]);

    $activity = Activity::query()->sole();
    expect($activity->causer?->is($user))->toBeTrue();
});

it('truncates the user agent to audit.user_agent_max characters', function (): void {
    config()->set('audit.user_agent_max', 10);
    registerLoggingRoute(fn () => activity()->log('long agent'));

    $this->withHeader('User-Agent', 'é'.str_repeat('a', 600))->post('/audit-test');

    expect(loggedProperties()['user_agent'])->toBe('é'.str_repeat('a', 9));
});

it('cuts the user agent at 512 characters by default', function (): void {
    registerLoggingRoute(fn () => activity()->log('long agent'));

    $this->withHeader('User-Agent', str_repeat('b', 600))->post('/audit-test');

    expect(loggedProperties()['user_agent'])->toBe(str_repeat('b', 512));
});

it('omits request data outside a request', function (): void {
    activity()->log('console');

    expect(loggedProperties())->toEqual(['actor_kind' => 'unattended']);
});

it('attributes a bound API key to its owner principal and the key (causer rule)', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'service principal']);
    $this->actingAs(AuthenticatableRecord::query()->create(['name' => 'someone else']));
    bindApiKey($owner);

    activity()->log('api action');

    $activity = Activity::query()->sole();

    expect($activity->causer?->is($owner))->toBeTrue()
        ->and(loggedProperties())->toEqual([
            'actor_kind' => 'api_key',
            'api_key_id' => API_KEY_UUID,
            'api_key_prefix' => 'qk_live_ab12',
            'api_key_created_by' => 'usr_01JDZCREATOR',
        ]);
});

it('records the OAuth client of a bound grant', function (): void {
    $owner = UuidKeyedRecord::query()->create(['name' => 'grant owner']);
    AuditContext::bindCredential(new AuditActor($owner, 'oauth_grant', oauthClientId: OAUTH_CLIENT_UUID));

    activity()->log('grant action');

    expect(Activity::query()->sole()->causer?->is($owner))->toBeTrue()
        ->and(loggedProperties())->toEqual(['actor_kind' => 'oauth_grant', 'oauth_client_id' => OAUTH_CLIENT_UUID]);
});

it('overrides a causer the caller chose when a credential acts', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'owner']);
    $impostor = IntegerKeyedRecord::query()->create(['name' => 'impostor']);
    bindApiKey($owner);

    activity()->causedBy($impostor)->log('forged causer');

    expect(Activity::query()->sole()->causer?->is($owner))->toBeTrue();
});

it('keeps the causer an unattended job set, or none', function (): void {
    $jobCauser = IntegerKeyedRecord::query()->create(['name' => 'job causer']);

    activity()->causedBy($jobCauser)->log('job with causer');
    activity()->log('job without causer');

    $activities = Activity::query()->orderBy('id')->get();

    expect($activities[0]->causer?->is($jobCauser))->toBeTrue()
        ->and($activities[1]->causer)->toBeNull();
});

it('overwrites reserved keys a caller forged (A2)', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'owner']);
    bindApiKey($owner);
    registerLoggingRoute(fn () => activity()->withProperties([
        'ip' => '10.0.0.1',
        'user_agent' => 'forged',
        'actor_kind' => 'unattended',
        'api_key_id' => '0193a3b0-7c1d-7000-8000-00000000dead',
        'api_key_prefix' => 'forged',
        'api_key_created_by' => '1',
        'oauth_client_id' => OAUTH_CLIENT_UUID,
        'reason' => 'kept',
    ])->log('forged properties'));

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->withHeader('User-Agent', 'Real/1.0')
        ->post('/audit-test');

    expect(loggedProperties())->toEqual([
        'reason' => 'kept',
        'ip' => '203.0.113.9',
        'user_agent' => 'Real/1.0',
        'actor_kind' => 'api_key',
        'api_key_id' => API_KEY_UUID,
        'api_key_prefix' => 'qk_live_ab12',
        'api_key_created_by' => 'usr_01JDZCREATOR',
    ]);
});

it('removes forged credential keys when no credential acts (A2)', function (): void {
    activity()->withProperties(['api_key_id' => API_KEY_UUID, 'ip' => '10.0.0.1', 'oauth_client_id' => OAUTH_CLIENT_UUID])->log('forged');

    expect(loggedProperties())->toEqual(['actor_kind' => 'unattended']);
});

it('wins over a product beforeLogging callback (A2)', function (): void {
    PendingActivityLog::beforeLogging(function (Activity $activity): void {
        $activity->properties = $activity->properties?->put('actor_kind', 'web_session')->put('api_key_id', '42');
    });

    activity()->log('callback');

    expect(loggedProperties())->toEqual(['actor_kind' => 'unattended']);
});

it('never takes the organisation from properties (A3)', function (): void {
    activity()->withProperties(['organisation_id' => (string) Str::uuid7()])->log('forged tenant');

    expect(Activity::query()->sole()->organisation_id)->toBeNull();
});

it('stamps an explicit organisation (A3)', function (): void {
    $organisationId = (string) Str::uuid7();

    activity()->tap(Audit::inOrganisation($organisationId))->log('explicit tenant');

    expect(Activity::query()->sole()->organisation_id)->toBe($organisationId);
});

it('asks the bound OrganisationResolver with the subject and the causer (A4)', function (): void {
    $activeOrganisation = (string) Str::uuid7();
    $subject = IntegerKeyedRecord::query()->create(['name' => 'subject']);
    $owner = IntegerKeyedRecord::query()->create(['name' => 'owner']);
    bindApiKey($owner);

    $resolver = new class($activeOrganisation) implements OrganisationResolver
    {
        /** @var list<array{?Model, ?Model}> */
        public array $calls = [];

        public function __construct(private string $activeOrganisation) {}

        public function resolve(?Model $subject, ?Model $causer): ?string
        {
            $this->calls[] = [$subject, $causer];

            return strtoupper($this->activeOrganisation);
        }
    };
    app()->instance(OrganisationResolver::class, $resolver);

    activity()->performedOn($subject)->log('resolved tenant');

    expect(Activity::query()->sole()->organisation_id)->toBe($activeOrganisation)
        ->and($resolver->calls)->toHaveCount(1)
        ->and($resolver->calls[0][0]?->is($subject))->toBeTrue()
        ->and($resolver->calls[0][1]?->is($owner))->toBeTrue();
});

it('prefers the explicit organisation over the resolver', function (): void {
    $explicit = (string) Str::uuid7();
    app()->instance(OrganisationResolver::class, new class implements OrganisationResolver
    {
        public function resolve(?Model $subject, ?Model $causer): ?string
        {
            throw new LogicException('not asked when the organisation is explicit');
        }
    });

    activity()->tap(Audit::inOrganisation($explicit))->log('explicit');

    expect(Activity::query()->sole()->organisation_id)->toBe($explicit);
});

it('refuses an organisation that is not a UUID, wherever it comes from', function (): void {
    app()->instance(OrganisationResolver::class, new class implements OrganisationResolver
    {
        public function resolve(?Model $subject, ?Model $causer): ?string
        {
            return '42';
        }
    });

    expect(fn () => activity()->log('bad resolver'))->toThrow(InvalidArgumentException::class, 'organisation_id must be a UUID.');

    expect(fn () => activity()->tap(fn (Activity $activity) => $activity->organisation_id = 'acme')->log('bad tap'))
        ->toThrow(InvalidArgumentException::class, 'organisation_id must be a UUID.');

    expect(Activity::query()->count())->toBe(0);
});

it('defaults to the NullOrganisationResolver', function (): void {
    expect(app(OrganisationResolver::class))->toBeInstanceOf(NullOrganisationResolver::class);

    activity()->log('no tenant');

    expect(Activity::query()->sole()->organisation_id)->toBeNull();
});

it('records the request id from the Laravel context', function (): void {
    Context::add('request_id', 'req-01JDZ.abc:9_x');

    activity()->tap(fn (Activity $activity) => $activity->request_id = 'forged')->log('correlated');

    expect(Activity::query()->sole()->request_id)->toBe('req-01JDZ.abc:9_x');
});

it('records no request id when the context has none or an unusable one', function (mixed $requestId): void {
    if ($requestId !== null) {
        Context::add('request_id', $requestId);
    }

    activity()->tap(fn (Activity $activity) => $activity->request_id = 'forged')->log('uncorrelated');

    expect(Activity::query()->sole()->request_id)->toBeNull();
})->with([
    'none' => [null],
    'too long' => [str_repeat('a', 65)],
    'not a string' => [42],
    'empty' => [''],
    'line break' => ["abc\r\ndef"],
    'space' => ['abc def'],
]);

it('accepts a 64-character request id', function (): void {
    Context::add('request_id', str_repeat('f', 64));

    activity()->log('long id');

    expect(Activity::query()->sole()->request_id)->toBe(str_repeat('f', 64));
});

it('attributes buffered rows the same way', function (): void {
    config()->set('activitylog.buffer.enabled', true);
    $owner = IntegerKeyedRecord::query()->create(['name' => 'owner']);
    bindApiKey($owner);

    activity()->withProperties(['api_key_id' => '42'])->log('buffered');
    app(ActivityBuffer::class)->flush();

    expect(loggedProperties()['api_key_id'])->toBe(API_KEY_UUID)
        ->and(Activity::query()->sole()->causer?->is($owner))->toBeTrue();
});

it('keeps the caller\'s causer when the actor has none', function (): void {
    $this->actingAs(new GenericUser(['id' => 5]));
    $chosenCauser = IntegerKeyedRecord::query()->create(['name' => 'chosen']);

    activity()->causedBy($chosenCauser)->log('web session without a model user');

    expect(Activity::query()->sole()->causer?->is($chosenCauser))->toBeTrue();
});

it('keeps the job\'s causer when laravel-access reports an unattended actor with a user', function (): void {
    $accessUser = AuthenticatableRecord::query()->create(['name' => 'access user']);
    $jobCauser = IntegerKeyedRecord::query()->create(['name' => 'job causer']);
    app()->instance(ActorResolver::class, new readonly class($accessUser) implements ActorResolver
    {
        public function __construct(private AuthenticatableRecord $user) {}

        public function current(): ActorContext
        {
            return new ActorContext(ActorKind::Unattended, $this->user);
        }
    });

    activity()->causedBy($jobCauser)->log('job');

    expect(Activity::query()->sole()->causer?->is($jobCauser))->toBeTrue();
});

it('accepts a user agent limit of one character', function (): void {
    config()->set('audit.user_agent_max', 1);
    registerLoggingRoute(fn () => activity()->log('short agent'));

    $this->withHeader('User-Agent', 'Browser')->post('/audit-test');

    expect(loggedProperties()['user_agent'])->toBe('B');
});

it('refuses a user agent limit that is not a positive integer', function (mixed $userAgentMax): void {
    config()->set('audit.user_agent_max', $userAgentMax);
    registerLoggingRoute(fn () => activity()->log('bad limit'));

    $this->withoutExceptionHandling();

    expect(fn () => $this->withHeader('User-Agent', 'Browser')->post('/audit-test'))
        ->toThrow(InvalidArgumentException::class, 'audit.user_agent_max must be a positive integer.');
})->with(['zero' => [0], 'negative' => [-1], 'string' => ['512'], 'null' => [null]]);
