<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
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
use Qiick\Audit\Events\ActivityAnonymisedEvent;
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
    $unscoped = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => $userId,
        'description' => 'Ada Lovelace signed in without an organisation',
    ]);
    $otherType = seedFleetActivity(['causer_type' => 'document', 'causer_id' => $userId, 'organisation_id' => $tenantId]);
    $eraser = app(AuditEraser::class);

    Event::fake([SubjectErasedEvent::class, ActivityAnonymisedEvent::class]);
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
        ->and(DB::table('activity_log')->where('id', $unscoped)->value('description'))->toBe('Ada Lovelace signed in without an organisation')
        ->and(DB::table('activity_log')->where('id', $otherType)->exists())->toBeTrue()
        ->and($eraser->erase($userId, $publicId, AuditEraser::DELETE, [$principalId], ['Ada Lovelace'], $tenantId)->changed())->toBe(0);

    Event::assertDispatched(SubjectErasedEvent::class, fn (SubjectErasedEvent $event): bool => $event->report === $report);
    Event::assertNotDispatched(ActivityAnonymisedEvent::class);
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

it('erases an identifier that overlaps the shared tombstone', function (string $identifier): void {
    $activityId = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => '420000',
        'description' => "{$identifier} changed settings for Edmund",
    ]);
    $eraser = app(AuditEraser::class);
    $publicId = (string) Str::uuid();

    expect($eraser->erase('420000', $publicId, AuditEraser::ANONYMISE, identifiers: [$identifier])->changed())->toBe(1)
        ->and(DB::table('activity_log')->where('id', $activityId)->value('description'))->toBe('erased changed settings for Edmund')
        ->and($eraser->erase('420000', $publicId, AuditEraser::ANONYMISE, identifiers: [$identifier])->changed())->toBe(0);
})->with(['Ed', 'ERASED']);

it('scrubs all personal data when the API key is the only link to the person', function (): void {
    $credentialId = (string) Str::uuid();
    $activityId = seedFleetActivity([
        'subject_type' => 'invoice',
        'subject_id' => '77',
        'description' => 'Invoice updated by ada@example.test or +39 333 123 4567',
        'properties' => json_encode([
            'api_key_id' => $credentialId,
            'api_key_prefix' => 'KEY-DETAIL',
            'ip' => '203.0.113.9',
            'user_agent' => 'AdaBot/1.0',
        ], JSON_THROW_ON_ERROR),
        'attribute_changes' => json_encode(['old' => ['email' => 'ada@example.test']], JSON_THROW_ON_ERROR),
    ]);
    $eraser = app(AuditEraser::class);
    $publicId = (string) Str::uuid();

    $report = $eraser->erase('420000', $publicId, AuditEraser::ANONYMISE, [$credentialId]);
    expect($report->stores['activity_log'])->toBe([
        'deleted' => 0,
        'anonymised' => 1,
        'kept' => 1,
        'reason' => 'legal_obligation',
    ]);

    $row = DB::table('activity_log')->where('id', $activityId)->first();
    expect($row->description)->toBe('Invoice updated by erased or erased')
        ->and(json_decode($row->properties, true, flags: JSON_THROW_ON_ERROR))
        ->toMatchArray(['api_key_id' => 'erased', 'api_key_prefix' => 'erased', 'ip' => 'erased', 'user_agent' => 'erased'])
        ->and(json_decode($row->attribute_changes, true, flags: JSON_THROW_ON_ERROR))->toBe(['old' => ['email' => 'erased']])
        ->and($eraser->erase('420000', $publicId, AuditEraser::ANONYMISE, [$credentialId])->changed())->toBe(0);
});

it('allows other InnoDB sessions to append and update unrelated activity during erasure', function (): void {
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Requires two InnoDB sessions in REPEATABLE READ.');
    }

    $credentialId = (string) Str::uuid();
    $unrelatedId = seedFleetActivity(['description' => 'Unrelated event']);
    $activityId = seedFleetActivity([
        'description' => 'Invoice updated by ada@example.test',
        'properties' => json_encode(['api_key_id' => $credentialId], JSON_THROW_ON_ERROR),
    ]);
    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.eraser_writer', config("database.connections.{$defaultConnection}"));
    $eraserConnection = DB::connection();
    $writer = DB::connection('eraser_writer');
    $eraserConnection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
    $eraserConnection->beginTransaction();

    try {
        expect(app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::DELETE, [$credentialId])->changed())->toBe(1);

        expect($writer->table('activity_log')->where('id', $unrelatedId)->update(['description' => 'Unrelated writer']))
            ->toBe(1)
            ->and($writer->table('activity_log')->insert([
                'public_id' => (string) Str::uuid7(),
                'description' => 'A new audit event',
                'created_at' => '2026-11-25 12:00:00',
            ]))->toBeTrue();
        expect(fn () => $writer->table('activity_log')->where('id', $activityId)->update(['description' => 'Conflicting writer']))
            ->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        $eraserConnection->rollBack();
        DB::purge('eraser_writer');
    }
});

it('continues to the next candidate when retention removes a row before it is locked', function (): void {
    $attributes = ['causer_type' => 'user', 'causer_id' => '420000', 'description' => 'Ed changed settings'];
    $purgedId = seedFleetActivity($attributes);
    $remainingId = seedFleetActivity($attributes);
    $hasPurged = false;
    DB::listen(function (QueryExecuted $query) use (&$hasPurged, $purgedId): void {
        if (! $hasPurged && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'activity_log')) {
            $hasPurged = true;
            $query->connection->table('activity_log')->where('id', $purgedId)->delete();
        }
    });

    $report = app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::DELETE);

    expect($hasPurged)->toBeTrue()
        ->and($report->changed())->toBe(1)
        ->and(DB::table('activity_log')->where('id', $remainingId)->whereNotNull('anonymised_at')->exists())->toBeTrue();
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

it('writes the shared tombstone literally in text and JSON', function (): void {
    config()->set('audit.erasure_tombstone', '[erased-$1]');
    $activityId = seedFleetActivity([
        'causer_type' => 'user',
        'causer_id' => '420000',
        'description' => 'Ada Lovelace used ada@example.test',
        'properties' => json_encode(['first_name' => 'Ada'], JSON_THROW_ON_ERROR),
    ]);

    app(AuditEraser::class)->erase('420000', (string) Str::uuid(), AuditEraser::DELETE, identifiers: ['Ada Lovelace']);

    $activity = DB::table('activity_log')->where('id', $activityId)->first();
    expect($activity->description)->toBe('[erased-$1] used [erased-$1]')
        ->and(json_decode($activity->properties, true, flags: JSON_THROW_ON_ERROR))->toBe(['first_name' => '[erased-$1]']);
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
