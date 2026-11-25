<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Qiick\Audit\Anonymisation\ActivityAnonymiser;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\MorphRef;
use Qiick\Audit\Events\ActivityAnonymised;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * GDPR erasure of audit rows about one data subject (design §5): A7 (no
 * missed rows, morph map, causer and subject, nested properties, idempotent,
 * anonymised_at, row count unchanged), A8 (no deleted evidence) and A14 (PII
 * in a free-text description).
 */

const TOMBSTONE = 'erased-user-0193a3b0';

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
    Relation::morphMap(['user' => AuthenticatableRecord::class, 'record' => IntegerKeyedRecord::class]);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

function personToErase(): AuthenticatableRecord
{
    return AuthenticatableRecord::query()->create(['name' => 'Ada Lovelace']);
}

function erasureOf(AuthenticatableRecord $person): ErasureSubject
{
    return new ErasureSubject([MorphRef::of($person)], TOMBSTONE, ['ada@example.com', 'ada.l', 'Ada Lovelace']);
}

function anonymise(AuthenticatableRecord $person): int
{
    return app(AuditAnonymiser::class)->anonymise(erasureOf($person))->rows;
}

/**
 * @return array<string, mixed>
 */
function rawRow(int $activityId): array
{
    return (array) DB::table('activity_log')->where('id', $activityId)->first();
}

/**
 * @param  array<string, mixed>  $properties
 * @param  array<string, mixed>  $attributeChanges
 */
function logAbout(?Model $causer, ?Model $subject, string $description, array $properties = [], array $attributeChanges = []): Activity
{
    $logger = activity('auth')->event('updated')->withProperties($properties)->withChanges($attributeChanges);

    if ($causer !== null) {
        $logger->causedBy($causer);
    }

    if ($subject !== null) {
        $logger->performedOn($subject);
    }

    $activity = $logger->log($description);
    assert($activity instanceof Activity);

    return $activity;
}

it('is the default AuditAnonymiser', function (): void {
    expect(app(AuditAnonymiser::class))->toBeInstanceOf(ActivityAnonymiser::class);
});

it('scrubs the personal keys of rows where the person is the causer, at any depth (A7)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'profile changed', [
        'reason' => 'kept',
        'context' => ['Email' => 'see the profile', 'nested' => ['first_name' => 'Ada', 'last_name' => 'Lovelace']],
        'username' => 'countess_99',
    ], [
        'attributes' => ['name' => 'Ada Lovelace', 'email' => 'ada@new.example', 'plan' => 'pro'],
        'old' => ['name' => 'Ada', 'email' => 'ada@example.com', 'plan' => 'free'],
    ]);

    expect(anonymise($person))->toBe(1);

    $anonymised = Activity::query()->findOrFail($activity->getKey());

    expect($anonymised->properties?->toArray())->toEqual([
        'reason' => 'kept',
        'context' => ['Email' => TOMBSTONE, 'nested' => ['first_name' => TOMBSTONE, 'last_name' => TOMBSTONE]],
        'username' => TOMBSTONE,
        'actor_kind' => 'unattended',
    ])->and($anonymised->attribute_changes?->toArray())->toEqual([
        'attributes' => ['name' => TOMBSTONE, 'email' => TOMBSTONE, 'plan' => 'pro'],
        'old' => ['name' => TOMBSTONE, 'email' => TOMBSTONE, 'plan' => 'free'],
    ]);
});

it('scrubs the request data and the personal keys the product configures', function (): void {
    config()->set('audit.personal_keys', ['phone_number', 'Address']);
    $person = personToErase();
    $activity = logAbout($person, null, 'login');
    DB::table('activity_log')->where('id', $activity->getKey())->update(['properties' => json_encode([
        'ip' => '2001:db8::1',
        'user_agent' => 'Browser/1.0',
        'email' => 'not given',
        'address' => ['street' => 'Via Roma 1'],
        'phone_number' => 'n/a',
        'actor_kind' => 'web_session',
    ])]);

    anonymise($person);

    expect(Activity::query()->findOrFail($activity->getKey())->properties?->toArray())->toEqual([
        'ip' => TOMBSTONE,
        'user_agent' => TOMBSTONE,
        'email' => TOMBSTONE,
        'address' => TOMBSTONE,
        'phone_number' => TOMBSTONE,
        'actor_kind' => 'web_session',
    ]);
});

it('scrubs rows where the person is the subject, through the morph map (A7)', function (): void {
    $person = personToErase();
    $activity = logAbout(null, $person, 'account created', ['email' => 'ada@example.com']);

    expect(rawRow($activity->getKey())['subject_type'])->toBe('user')
        ->and(anonymise($person))->toBe(1)
        ->and(Activity::query()->findOrFail($activity->getKey())->getProperty('email'))->toBe(TOMBSTONE);
});

it('scrubs every ref of the person: user, principals and keys', function (): void {
    $person = personToErase();
    $principal = IntegerKeyedRecord::query()->create(['name' => 'principal']);
    logAbout($person, null, 'as user', ['email' => 'ada@example.com']);
    logAbout($principal, null, 'as principal', ['email' => 'ada@example.com']);

    $report = app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($person), MorphRef::of($principal)], TOMBSTONE));

    expect($report->rows)->toBe(2)
        ->and(Activity::query()->pluck('properties')->map(fn ($properties) => $properties['email'])->all())->toBe([TOMBSTONE, TOMBSTONE]);
});

it('leaves other people\'s rows alone, including a same id under another morph type', function (): void {
    $person = personToErase();
    $sameIdOtherType = IntegerKeyedRecord::query()->findOrFail($person->getKey());
    $otherPerson = AuthenticatableRecord::query()->create(['name' => 'Grace']);
    $sameIdRow = logAbout($sameIdOtherType, $sameIdOtherType, 'other type ada@example.com', ['email' => 'grace@example.com']);
    $otherRow = logAbout($otherPerson, null, 'other person', ['email' => 'grace@example.com']);
    $before = [rawRow($sameIdRow->getKey()), rawRow($otherRow->getKey())];

    expect(anonymise($person))->toBe(0)
        ->and([rawRow($sameIdRow->getKey()), rawRow($otherRow->getKey())])->toBe($before);
});

it('never deletes a row or changes its event, log, timestamps or morphs (A8)', function (): void {
    $person = personToErase();
    $subject = IntegerKeyedRecord::query()->create(['name' => 'project']);
    $activity = logAbout($person, $subject, 'Ada Lovelace renamed the project', ['email' => 'ada@example.com']);
    $before = rawRow($activity->getKey());
    $rowCountBefore = DB::table('activity_log')->count();

    $this->travel(5)->minutes();
    anonymise($person);

    $after = rawRow($activity->getKey());

    expect(DB::table('activity_log')->count())->toBe($rowCountBefore);

    foreach (['id', 'public_id', 'log_name', 'event', 'subject_type', 'subject_id', 'causer_type', 'causer_id', 'organisation_id', 'schema_version', 'request_id', 'created_at', 'updated_at'] as $column) {
        expect($after[$column])->toBe($before[$column]);
    }
});

it('scrubs identifiers and contact patterns from the description (A14)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, "User ADA@example.com (ada.l, ada lovelace) wrote to bob@other.example and +39 333 123 4567 / 0612345678.\r\n<script>");

    anonymise($person);

    $description = Activity::query()->findOrFail($activity->getKey())->description;

    expect($description)->toBe('User '.TOMBSTONE.' ('.TOMBSTONE.', '.TOMBSTONE.') wrote to '.TOMBSTONE.' and '.TOMBSTONE.' / '.TOMBSTONE.".\r\n<script>");
});

it('scrubs identifiers and contact patterns in any string value (A14)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'note', [
        'note' => 'Called Ada Lovelace on 0612345678, cc bob@other.example',
        'list' => ['ada.l', 42, null, true],
    ]);

    anonymise($person);

    expect(Activity::query()->findOrFail($activity->getKey())->properties?->toArray())->toMatchArray([
        'note' => 'Called '.TOMBSTONE.' on '.TOMBSTONE.', cc '.TOMBSTONE,
        'list' => [TOMBSTONE, 42, null, true],
    ]);
});

it('keeps UUIDs, timestamps and short numbers that only look like digits', function (): void {
    $person = personToErase();
    $values = [
        'api_key_id' => '0193a3b0-7c1d-7000-8000-000000000001',
        'at' => '2026-11-24 10:00:00',
        'iso' => '2026-11-24T10:00:00.000000Z',
        'day' => '2026/11/24',
        'version' => '1.2.3',
        'count' => '1234567',
        'hash' => 'a1b2c3d4e5f60718293a4b5c6d7e8f90',
        'ulid' => '01JDZ3K4M5N6P7Q8R9S0T1V2W3',
    ];
    $activity = logAbout($person, null, 'values 2026-11-24 10:00:00 0193a3b0-7c1d-7000-8000-000000000001', ['values' => $values]);

    anonymise($person);

    $anonymised = Activity::query()->findOrFail($activity->getKey());

    expect($anonymised->getProperty('values'))->toEqual($values)
        ->and($anonymised->description)->toBe('values 2026-11-24 10:00:00 0193a3b0-7c1d-7000-8000-000000000001');
});

it('sets anonymised_at and a second run changes nothing and reports 0 rows (A7)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'Ada Lovelace logged in', ['ip' => '203.0.113.9']);
    $this->freezeSecond();

    expect(anonymise($person))->toBe(1);

    $afterFirstRun = rawRow($activity->getKey());

    expect($afterFirstRun['anonymised_at'])->not->toBeNull();

    $this->travel(1)->hour();

    expect(anonymise($person))->toBe(0)
        ->and(rawRow($activity->getKey()))->toBe($afterFirstRun);
});

it('marks a matching row without personal data as anonymised once', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'logged in');

    expect(anonymise($person))->toBe(1)
        ->and(rawRow($activity->getKey())['anonymised_at'])->not->toBeNull()
        ->and(anonymise($person))->toBe(0);
});

it('scrubs a row already anonymised for someone else', function (): void {
    $person = personToErase();
    $otherPerson = AuthenticatableRecord::query()->create(['name' => 'Grace Hopper']);
    $activity = logAbout($otherPerson, $person, 'Grace Hopper reset the password of Ada Lovelace');
    app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($otherPerson)], 'erased-user-grace', ['Grace Hopper']));

    expect(anonymise($person))->toBe(1)
        ->and(Activity::query()->findOrFail($activity->getKey())->description)->toBe('erased-user-grace reset the password of '.TOMBSTONE);
});

it('replaces the longest identifier first', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'Ada Lovelace and Ada');

    app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($person)], TOMBSTONE, ['Ada', 'Ada Lovelace']));

    expect(Activity::query()->findOrFail($activity->getKey())->description)->toBe(TOMBSTONE.' and '.TOMBSTONE);
});

it('scrubs attribute_changes when properties are null, and the other way round', function (): void {
    $person = personToErase();
    $withoutProperties = logAbout($person, null, 'no properties');
    $withoutChanges = logAbout($person, null, 'no changes');
    DB::table('activity_log')->where('id', $withoutProperties->getKey())
        ->update(['properties' => null, 'attribute_changes' => json_encode(['attributes' => ['email' => 'x']])]);
    DB::table('activity_log')->where('id', $withoutChanges->getKey())
        ->update(['properties' => json_encode(['email' => 'x']), 'attribute_changes' => null]);

    expect(anonymise($person))->toBe(2);

    $rows = [rawRow($withoutProperties->getKey()), rawRow($withoutChanges->getKey())];

    expect($rows[0]['properties'])->toBeNull()
        ->and(json_decode((string) $rows[0]['attribute_changes'], true))->toBe(['attributes' => ['email' => TOMBSTONE]])
        ->and(json_decode((string) $rows[1]['properties'], true))->toBe(['email' => TOMBSTONE])
        ->and($rows[1]['attribute_changes'])->toBeNull();
});

it('anonymises more rows than one chunk', function (): void {
    $person = personToErase();
    $rows = collect(range(1, 1001))->map(fn (int $rowNumber): array => [
        'public_id' => (string) Str::uuid7(),
        'description' => "row {$rowNumber}",
        'causer_type' => 'user',
        'causer_id' => (string) $person->getKey(),
        'properties' => json_encode(['email' => 'x']),
        'created_at' => now('UTC'),
    ]);
    $rows->chunk(250)->each(fn ($chunk) => DB::table('activity_log')->insert($chunk->values()->all()));

    expect(anonymise($person))->toBe(1001)
        ->and(Activity::query()->whereNull('anonymised_at')->count())->toBe(0);
});

it('follows the configured morph columns', function (): void {
    config()->set('audit.columns', ['subject_id' => 'subject_ref', 'causer_id' => 'causer_ref']);
    Schema::table('activity_log', function (Blueprint $table): void {
        $table->string('subject_ref', 64)->nullable();
        $table->string('causer_ref', 64)->nullable();
    });
    $person = personToErase();
    $activity = logAbout($person, null, 'renamed columns', ['email' => 'ada@example.com']);

    expect(rawRow($activity->getKey())['causer_id'])->toBeNull()
        ->and(anonymise($person))->toBe(1)
        ->and(Activity::query()->findOrFail($activity->getKey())->getProperty('email'))->toBe(TOMBSTONE);
});

it('dispatches ActivityAnonymised with the row count and the refs', function (): void {
    Event::fake([ActivityAnonymised::class]);
    $person = personToErase();
    logAbout($person, null, 'logged in');

    anonymise($person);

    Event::assertDispatched(ActivityAnonymised::class, fn (ActivityAnonymised $event): bool => $event->rows === 1
        && $event->refs == [MorphRef::of($person)]);
});

it('refuses a tombstone that the contact patterns would scrub', function (string $tombstone): void {
    $person = personToErase();

    expect(fn () => app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($person)], $tombstone)))
        ->toThrow(InvalidArgumentException::class, 'The tombstone must not look like an email address or a phone number.');
})->with(['erased@example.com', 'erased 0612345678']);

it('refuses a personal_keys config that is not a list of names', function (mixed $personalKeys): void {
    config()->set('audit.personal_keys', $personalKeys);
    $person = personToErase();
    logAbout($person, null, 'logged in');

    expect(fn () => anonymise($person))->toThrow(InvalidArgumentException::class, 'audit.personal_keys must be a list of property keys.');
})->with(['null' => [null], 'string' => ['phone'], 'nested' => [[['phone']]]]);

it('scrubs personal keys in any spelling (review AU-j)', function (): void {
    config()->set('audit.personal_keys', ['phone_number']);
    $person = personToErase();
    $activity = logAbout($person, null, 'profile', [
        'firstName' => 'Ada',
        'LastName' => 'Lovelace',
        'last-name' => 'Lovelace',
        'phoneNumber' => 'n/a',
        'Phone Number' => 'n/a',
        'user_Agent' => 'Browser',
        'IP' => 'n/a',
        'e-mail' => 'none',
        'nested' => ['first.name' => 'Ada', 'kept' => 'value'],
    ]);

    anonymise($person);

    expect(Activity::query()->findOrFail($activity->getKey())->properties?->toArray())->toEqual([
        'firstName' => TOMBSTONE,
        'LastName' => TOMBSTONE,
        'last-name' => TOMBSTONE,
        'phoneNumber' => TOMBSTONE,
        'Phone Number' => TOMBSTONE,
        'user_Agent' => TOMBSTONE,
        'IP' => TOMBSTONE,
        'e-mail' => TOMBSTONE,
        'nested' => ['first.name' => TOMBSTONE, 'kept' => 'value'],
        'actor_kind' => 'unattended',
    ]);
});

it('replaces an identifier only as a whole word (review)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'Al and Al Bundy: Called Alessandro about the Alarm (Al)', [
        'note' => 'Called Alessandro about Alarm; Al said so',
        'display_name_label' => 'Al B',
    ]);

    app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($person)], TOMBSTONE, ['Al Bundy', 'Al']));

    $anonymised = Activity::query()->findOrFail($activity->getKey());

    expect($anonymised->description)->toBe(TOMBSTONE.' and '.TOMBSTONE.': Called Alessandro about the Alarm ('.TOMBSTONE.')')
        ->and($anonymised->getProperty('note'))->toBe('Called Alessandro about Alarm; '.TOMBSTONE.' said so')
        ->and($anonymised->getProperty('display_name_label'))->toBe(TOMBSTONE.' B');
});

it('replaces identifiers that start or end with a non-letter at their own edges', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'mail ada@example.com, handle @ada.l!, ada.lovelace stays');

    app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($person)], TOMBSTONE, ['@ada.l', 'ADA@example.com']));

    expect(Activity::query()->findOrFail($activity->getKey())->description)
        ->toBe('mail '.TOMBSTONE.', handle '.TOMBSTONE.'!, ada.lovelace stays');
});

it('scrubs ip_address by default (review)', function (): void {
    $person = personToErase();
    $activity = logAbout($person, null, 'login', ['ip_address' => '2001:db8::1', 'ipAddress' => '2001:db8::2']);

    anonymise($person);

    expect(Activity::query()->findOrFail($activity->getKey())->properties?->toArray())->toEqual([
        'ip_address' => TOMBSTONE,
        'ipAddress' => TOMBSTONE,
        'actor_kind' => 'unattended',
    ]);
});

it('reports the erasure only once the caller\'s transaction commits (review M1)', function (): void {
    Event::fake([ActivityAnonymised::class]);
    $person = personToErase();
    logAbout($person, null, 'logged in', ['email' => 'ada@example.com']);

    DB::transaction(function () use ($person): void {
        expect(anonymise($person))->toBe(1);

        Event::assertNotDispatched(ActivityAnonymised::class);
    });

    Event::assertDispatchedTimes(ActivityAnonymised::class, 1);
});

it('never reports an erasure the caller\'s transaction rolled back (review M1)', function (): void {
    Event::fake([ActivityAnonymised::class]);
    $person = personToErase();
    $activity = logAbout($person, null, 'logged in', ['email' => 'ada@example.com']);

    expect(fn () => DB::transaction(function () use ($person): void {
        anonymise($person);

        throw new RuntimeException('the user tombstone failed');
    }))->toThrow(RuntimeException::class, 'the user tombstone failed');

    Event::assertNotDispatched(ActivityAnonymised::class);
    expect(Activity::query()->findOrFail($activity->getKey())->getProperty('email'))->toBe('ada@example.com');
});
