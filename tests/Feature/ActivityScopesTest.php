<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuditConnectionActivity;
use Qiick\Audit\Tests\Support\Models\AuditedIntegerRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;
use Qiick\Audit\Tests\Support\Models\UuidKeyedRecord;

/*
 * A11 (design §4 "string-safe scopes"): causedBy / forSubject bind the key as
 * a string, so PostgreSQL never compares varchar with integer. A5: rows of
 * organisation A are not visible through organisation B's scope, and rows
 * without an organisation are not visible through any organisation scope.
 * The suite runs on all four engines in CI.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

it('finds rows caused by a bigint-keyed model (A11)', function (): void {
    $causer = IntegerKeyedRecord::query()->create(['name' => 'causer']);
    $otherCauser = IntegerKeyedRecord::query()->create(['name' => 'other']);

    activity()->causedBy($causer)->log('mine');
    activity()->causedBy($otherCauser)->log('theirs');

    expect(Activity::query()->causedBy($causer)->pluck('description')->all())->toBe(['mine']);
});

it('finds rows about a bigint-keyed subject (A11)', function (): void {
    $subject = IntegerKeyedRecord::query()->create(['name' => 'subject']);
    $otherSubject = IntegerKeyedRecord::query()->create(['name' => 'other']);

    activity()->performedOn($subject)->log('mine');
    activity()->performedOn($otherSubject)->log('theirs');

    expect(Activity::query()->forSubject($subject)->pluck('description')->all())->toBe(['mine']);
});

it('matches the morph type as well as the id', function (): void {
    $integerRecord = IntegerKeyedRecord::query()->create(['name' => 'one']);
    $uuidRecord = UuidKeyedRecord::query()->create(['name' => 'uuid']);

    activity()->performedOn($uuidRecord)->causedBy($uuidRecord)->log('uuid row');
    Activity::query()->update(['subject_id' => '1', 'causer_id' => '1']);

    expect(Activity::query()->forSubject($integerRecord)->count())->toBe(0)
        ->and(Activity::query()->causedBy($integerRecord)->count())->toBe(0);
});

it('finds rows of uuid-keyed models', function (): void {
    $record = UuidKeyedRecord::query()->create(['name' => 'uuid']);

    activity()->performedOn($record)->causedBy($record)->log('uuid row');

    expect(Activity::query()->forSubject($record)->count())->toBe(1)
        ->and(Activity::query()->causedBy($record)->count())->toBe(1);
});

it('scopes rows to one organisation and never shows null-organisation rows (A5)', function (): void {
    $organisationA = (string) Str::uuid7();
    $organisationB = (string) Str::uuid7();

    activity()->log('instance row');
    activity()->tap(fn (Activity $activity) => $activity->organisation_id = $organisationA)->log('row of A');
    activity()->tap(fn (Activity $activity) => $activity->organisation_id = $organisationB)->log('row of B');

    expect(Activity::query()->forOrganisation($organisationA)->pluck('description')->all())->toBe(['row of A'])
        ->and(Activity::query()->forOrganisation($organisationB)->pluck('description')->all())->toBe(['row of B']);
});

it('refuses an organisation scope that is not a UUID', function (string $organisationId): void {
    expect(fn () => Activity::query()->forOrganisation($organisationId))
        ->toThrow(InvalidArgumentException::class, 'organisation_id must be a UUID.');
})->with(['', '42', 'not-a-uuid']);

it('reads a subject\'s activity through the trait relation (A11)', function (): void {
    $subject = AuditedIntegerRecord::query()->create(['name' => 'first']);
    $subject->update(['name' => 'second']);

    expect($subject->activitiesAsSubject()->pluck('event')->all())->toBe(['created', 'updated']);

    $loaded = AuditedIntegerRecord::query()->with('activitiesAsSubject')->get();

    expect($loaded->sole()->activitiesAsSubject)->toHaveCount(2);
});

it('binds the morph ids as strings, so MySQL never casts the column to a number (A11)', function (): void {
    $record = IntegerKeyedRecord::query()->create(['name' => 'one']);

    expect(Activity::query()->causedBy($record)->getBindings())->toBe([$record->getMorphClass(), (string) $record->getKey()])
        ->and(Activity::query()->forSubject($record)->getBindings())->toBe([$record->getMorphClass(), (string) $record->getKey()]);
});

it('does not match an id that only equals the key as a number (A11)', function (): void {
    $record = IntegerKeyedRecord::query()->create(['name' => 'one']);

    activity()->performedOn($record)->causedBy($record)->log('padded');
    Activity::query()->update(['subject_id' => '0'.$record->getKey(), 'causer_id' => $record->getKey().'e0']);

    expect(Activity::query()->forSubject($record)->count())->toBe(0)
        ->and(Activity::query()->causedBy($record)->count())->toBe(0);
});

it('matches an organisation id whatever its letter case', function (): void {
    $organisationId = (string) Str::uuid7();

    activity()->tap(fn (Activity $activity) => $activity->organisation_id = $organisationId)->log('row');

    expect(Activity::query()->forOrganisation(strtoupper($organisationId))->count())->toBe(1);
});

it('eager-loads only the rows of the parent\'s own morph type (A11)', function (): void {
    $record = AuditedIntegerRecord::query()->create(['name' => 'audited']);

    activity()->performedOn(IntegerKeyedRecord::query()->findOrFail($record->getKey()))->log('other type, same id');

    $loaded = AuditedIntegerRecord::query()->with('activitiesAsSubject')->sole();

    expect($loaded->activitiesAsSubject->pluck('description')->all())->toBe(['created']);
});

it('reads the activity from the model\'s connection unless the activity model names one', function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:']);
    config()->set('database.connections.audit', ['driver' => 'sqlite', 'database' => ':memory:']);

    $record = (new AuditedIntegerRecord)->setConnection('secondary');

    expect($record->activitiesAsSubject()->getRelated()->getConnectionName())->toBe('secondary');

    config()->set('activitylog.activity_model', AuditConnectionActivity::class);

    expect($record->activitiesAsSubject()->getRelated()->getConnectionName())->toBe('audit');
});
