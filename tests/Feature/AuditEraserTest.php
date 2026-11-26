<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Erasure\AuditEraser;
use Qiick\Audit\Erasure\ErasureReport;
use Qiick\Audit\Erasure\OrganisationScopeUnsupportedException;
use Qiick\Audit\Events\ActivityAnonymised;
use Qiick\Audit\Events\SubjectErasedEvent;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('auth.providers.users.model', AuthenticatableRecord::class);
    Relation::morphMap(['user' => AuthenticatableRecord::class]);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function seedFleetActivity(array $attributes): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'log_name' => 'auth',
        'event' => 'updated',
        'description' => 'Account settings changed',
        'created_at' => '2026-11-25 12:00:00',
        ...$attributes,
    ]);
}

it('reports exactly which evidence it touched and which cleartext references it kept', function (): void {
    $userId = '420000';
    $publicId = (string) Str::uuid();
    $principalId = 'adadefad-cafe-401a-8cfa-00badca11ded';
    $tenantId = (string) Str::uuid();
    $historical = seedFleetActivity([
        'subject_type' => AuthenticatableRecord::class,
        'subject_id' => $userId,
        'causer_type' => 'principal',
        'causer_id' => strtoupper($principalId),
        'organisation_id' => $tenantId,
        'description' => "Ada Lovelace used {$principalId}",
    ]);
    $current = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => $userId,
        'organisation_id' => $tenantId,
        'description' => 'Ada Lovelace changed settings',
    ]);
    $principalOnly = seedFleetActivity([
        'causer_type' => 'principal',
        'causer_id' => strtoupper($principalId),
        'organisation_id' => $tenantId,
        'description' => 'Ada Lovelace signed in',
    ]);
    $subjectOnly = seedFleetActivity([
        'subject_type' => 'principal',
        'subject_id' => 'ADADEFAD-CAFE-401a-8cfa-00badca11ded',
        'organisation_id' => $tenantId,
        'description' => 'Ada Lovelace signed in again',
    ]);
    $unscoped = seedFleetActivity(['causer_type' => 'user', 'causer_id' => $userId]);
    $otherType = seedFleetActivity(['causer_type' => 'document', 'causer_id' => $userId, 'organisation_id' => $tenantId]);
    $eraser = app(AuditEraser::class);

    Event::fake([SubjectErasedEvent::class, ActivityAnonymised::class]);
    $predicted = $eraser->erase($userId, $publicId, AuditEraser::DELETE, [$principalId], ['Ada Lovelace'], $tenantId, dryRun: true);
    Event::assertNothingDispatched();
    expect(DB::table('activity_log')->whereNotNull('anonymised_at')->count())->toBe(0);

    $report = $eraser->erase($userId, $publicId, AuditEraser::DELETE, [strtoupper($principalId)], ['Ada Lovelace'], $tenantId);

    expect($report->changed())->toBe(4)
        ->and($report->stores)->toBe($predicted->stores)
        ->and($report->stores)->toBe([
            'activity_log' => ['deleted' => 0, 'anonymised' => 4, 'kept' => 4, 'reason' => 'legal_obligation'],
            'cleartext:activity_log.subject_id' => ['deleted' => 0, 'anonymised' => 0, 'kept' => 2, 'reason' => 'legal_obligation'],
            'cleartext:activity_log.causer_id' => ['deleted' => 0, 'anonymised' => 0, 'kept' => 3, 'reason' => 'legal_obligation'],
        ])
        ->and(DB::table('activity_log')->whereNotNull('anonymised_at')->orderBy('id')->pluck('id')->all())->toBe([$historical, $current, $principalOnly, $subjectOnly])
        ->and(DB::table('activity_log')->where('id', $historical)->value('description'))->toBe('erased used erased')
        ->and(DB::table('activity_log')->where('id', $historical)->value('causer_id'))->toBe(strtoupper($principalId))
        ->and(DB::table('activity_log')->whereIn('id', [$unscoped, $otherType])->count())->toBe(2)
        ->and($eraser->erase($userId, $publicId, AuditEraser::DELETE, [$principalId], ['Ada Lovelace'], $tenantId)->changed())->toBe(0);

    Event::assertDispatched(SubjectErasedEvent::class, fn (SubjectErasedEvent $event): bool => $event->report === $report);
    Event::assertNotDispatched(ActivityAnonymised::class);
});

it('unlinks a creator from another person’s activity without redacting the other person', function (): void {
    $creatorPublicId = (string) Str::uuid();
    $credentialId = (string) Str::uuid();
    $creatorOnly = seedFleetActivity([
        'causer_type' => 'principal',
        'causer_id' => (string) Str::uuid(),
        'properties' => json_encode([
            'api_key_created_by' => strtoupper($creatorPublicId),
            'api_key_id' => (string) Str::uuid(),
            'api_key_prefix' => 'OTHER',
            'email' => 'someone-else@example.test',
        ], JSON_THROW_ON_ERROR),
        'description' => 'someone-else@example.test used a key created by Ada Lovelace',
    ]);
    $credentialOnly = seedFleetActivity([
        'properties' => json_encode([
            'api_key_id' => $credentialId,
            'api_key_prefix' => 'KEY-DETAIL',
        ], JSON_THROW_ON_ERROR),
    ]);

    $report = app(AuditEraser::class)->erase('420000', $creatorPublicId, AuditEraser::ANONYMISE, [$credentialId], ['Ada Lovelace']);

    expect($report->changed())->toBe(2)
        ->and(DB::table('activity_log')->where('id', $creatorOnly)->value('description'))
        ->toBe('someone-else@example.test used a key created by erased')
        ->and(json_decode(DB::table('activity_log')->where('id', $creatorOnly)->value('properties'), true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['api_key_created_by' => 'erased', 'api_key_prefix' => 'OTHER', 'email' => 'someone-else@example.test'])
        ->and(json_decode(DB::table('activity_log')->where('id', $credentialOnly)->value('properties'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['api_key_id' => 'erased', 'api_key_prefix' => 'erased']);
});

it('refuses a scoped run if the audit table cannot filter by organisation', function (): void {
    Schema::table('activity_log', function ($table): void {
        $table->dropIndex('activity_log_organisation_created_at');
    });
    Schema::table('activity_log', function ($table): void {
        $table->dropColumn('organisation_id');
    });
    $activityId = seedFleetActivity(['causer_type' => 'user', 'causer_id' => '420000', 'description' => 'Ada Lovelace changed settings']);

    expect(fn () => app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::DELETE, identifiers: ['Ada Lovelace'], organisationId: (string) Str::uuid()))
        ->toThrow(OrganisationScopeUnsupportedException::class);
    expect(DB::table('activity_log')->where('id', $activityId)->value('description'))->toBe('Ada Lovelace changed settings');
});

it('keeps scanning if the first retained row was already scrubbed', function (): void {
    $erasedRow = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => '420000',
        'description' => 'erased changed settings',
        'anonymised_at' => '2026-11-26 07:00:00',
    ]);
    $remainingRow = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => '420000',
        'description' => 'Ada Lovelace signed in',
    ]);

    $report = app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::ANONYMISE, identifiers: ['Ada Lovelace']);

    expect($report->changed())->toBe(1)
        ->and($report->stores['activity_log']['kept'])->toBe(2)
        ->and(DB::table('activity_log')->where('id', $erasedRow)->value('anonymised_at'))->toBe('2026-11-26 07:00:00')
        ->and(DB::table('activity_log')->where('id', $remainingRow)->value('description'))->toBe('erased signed in');
});

it('fails closed with a binding that supports only direct anonymisation', function (): void {
    $anonymiser = Mockery::mock(AuditAnonymiser::class);
    $anonymiser->shouldNotReceive('anonymise');
    app()->instance(AuditAnonymiser::class, $anonymiser);
    $eraser = app(AuditEraser::class);
    $publicId = (string) Str::uuid();

    expect(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE, organisationId: (string) Str::uuid()))
        ->toThrow(OrganisationScopeUnsupportedException::class)
        ->and(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE))
        ->toThrow(LogicException::class, 'The bound audit anonymiser does not support fleet erasure.');
});

it('validates the snapshot and deployment configuration before reading audit rows', function (): void {
    $eraser = app(AuditEraser::class);
    $publicId = (string) Str::uuid();

    expect(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE, [123]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $eraser->erase('  ', $publicId, AuditEraser::DELETE))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE, organisationId: 'wrong-org'))
        ->toThrow(InvalidArgumentException::class);

    config()->set('auth.providers.users.model', stdClass::class);
    expect(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE))
        ->toThrow(LogicException::class, 'Audit erasure requires an Eloquent model');

    config()->set('audit.erasure_tombstone', ['bad-config']);
    expect(fn () => $eraser->erase('420000', $publicId, AuditEraser::DELETE))
        ->toThrow(LogicException::class, 'The audit erasure tombstone must be a string.');
});

it('rethrows a database failure without exposing the statement or bound values', function (): void {
    $publicId = (string) Str::uuid();
    $connection = DB::connection();
    $isArmed = true;
    $connection->beforeExecuting(function (string $sql) use ($connection, $publicId, &$isArmed): void {
        if ($isArmed) {
            $isArmed = false;

            throw new QueryException($connection->getName(), $sql, [$publicId], new RuntimeException('SQL failed'));
        }
    });

    try {
        app(AuditEraser::class)->erase('420000', $publicId, AuditEraser::DELETE);
        test()->fail('The query failure was swallowed.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('The audit erasure could not complete a database operation.')
            ->and($exception->getPrevious())->toBeNull();
    }
});

it('rolls back if the audit connection cannot register its after-commit event', function (): void {
    $activityId = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => '420000',
        'description' => 'Ada Lovelace signed in',
    ]);
    $connection = DB::connection();
    $connection->unsetTransactionManager();

    try {
        expect(fn () => app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::DELETE, identifiers: ['Ada Lovelace']))
            ->toThrow(RuntimeException::class, 'The erasure transaction is not tracked by the transactions manager.');
    } finally {
        $connection->setTransactionManager(app('db.transactions'));
    }

    expect(DB::table('activity_log')->where('id', $activityId)->value('description'))->toBe('Ada Lovelace signed in');
});

it('reports deleted and anonymised counts together', function (): void {
    $report = new ErasureReport('qiick/laravel-audit', AuditEraser::DELETE, false, [
        'first' => ['deleted' => 3, 'anonymised' => 1, 'kept' => 1, 'reason' => 'legal_obligation'],
        'second' => ['deleted' => 2, 'anonymised' => 5, 'kept' => 5, 'reason' => 'legal_obligation'],
    ]);

    expect($report->changed())->toBe(11);
});
