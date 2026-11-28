<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Audit\Erasure\AuditEraser;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;

/*
 * Erasure of a person who only caused a row (they renamed a project, assigned
 * a ticket): the row is evidence about another subject. Their request data and
 * their own identifiers go wherever they appear, the other subject's attributes
 * stay (decision 2026-11-27, docs/decisions). The row's `properties` are the
 * request's context and lose every personal key; the subject's
 * `attribute_changes` lose personal keys only where the person is the subject or
 * the credential, or where the row has no other subject.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('auth.providers.users.model', AuthenticatableRecord::class);
    Relation::morphMap(['user' => AuthenticatableRecord::class]);
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

/**
 * @param  array<string, mixed>  $properties
 * @param  array<string, mixed>  $attributeChanges
 */
function seedCausedActivity(string $subjectType, string $subjectId, string $causerId, array $properties, array $attributeChanges): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'log_name' => 'default',
        'event' => 'updated',
        'description' => 'Project updated',
        'subject_type' => $subjectType,
        'subject_id' => $subjectId,
        'causer_type' => 'user',
        'causer_id' => $causerId,
        'properties' => json_encode($properties),
        'attribute_changes' => json_encode($attributeChanges),
        'created_at' => '2026-11-25 12:00:00',
    ]);
}

/** @return array<string, mixed> */
// toEqual, not toBe: MySQL's JSON type reorders object keys.
function erasedActivityJson(int $activityId, string $column): array
{
    return json_decode((string) DB::table('activity_log')->where('id', $activityId)->value($column), true);
}

function eraseAda(string $userId): void
{
    app(AuditEraser::class)->erase(
        userId: $userId,
        userPublicId: (string) Str::uuid(),
        mode: AuditEraser::ANONYMISE,
        identifiers: ['Ada Lovelace', 'ada@example.test', 'ada.l'],
    );
}

it('keeps the other subject\'s attributes in a row the person only caused', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', ['ip' => '203.0.113.9', 'user_agent' => 'Firefox', 'email' => 'ada@work.test', 'source' => 'settings page'], [
        'attributes' => ['name' => 'Shop', 'username' => 'shop-bot'],
        'old' => ['name' => 'Store'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => ['name' => 'Shop', 'username' => 'shop-bot'],
        'old' => ['name' => 'Store'],
    ])->and(erasedActivityJson($activityId, 'properties'))->toEqual(['ip' => 'erased', 'user_agent' => 'erased', 'email' => 'erased', 'source' => 'settings page']);
});

it('scrubs the person\'s identifiers inside the other subject\'s attributes', function (): void {
    $activityId = seedCausedActivity('ticket', '9', '42', [], [
        'attributes' => ['title' => 'Login broken', 'assigned_to' => 'Ada Lovelace', 'reporter_email' => 'ada@example.test', 'owner' => 'ada.l'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => ['title' => 'Login broken', 'assigned_to' => 'erased', 'reporter_email' => 'erased', 'owner' => 'erased'],
    ]);
});

it('scrubs every personal key where the person is the subject', function (): void {
    $activityId = seedCausedActivity('user', '42', '1', ['ip' => '198.51.100.4'], [
        'attributes' => ['name' => 'Countess', 'username' => 'lovelace', 'role' => 'admin'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => ['name' => 'erased', 'username' => 'erased', 'role' => 'admin'],
    ])->and(erasedActivityJson($activityId, 'properties'))->toEqual(['ip' => 'erased']);
});

it('keeps the other subject\'s attributes when the person only acted through their API key', function (): void {
    $keyPublicId = (string) Str::uuid();
    // Causer is someone else; only api_key_id links the erased person — still
    // evidence about the project, not about the key holder as subject.
    $activityId = seedCausedActivity('project', '7', '1', ['api_key_id' => $keyPublicId, 'ip' => '203.0.113.9'], [
        'attributes' => ['name' => 'Shop', 'username' => 'shop-bot'],
    ]);

    app(AuditEraser::class)->erase(userId: '42', userPublicId: (string) Str::uuid(), mode: AuditEraser::ANONYMISE, publicIds: [$keyPublicId]);

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => ['name' => 'Shop', 'username' => 'shop-bot'],
    ])->and(erasedActivityJson($activityId, 'properties'))->toEqual([
        'api_key_id' => 'erased',
        'ip' => 'erased',
    ]);
});

it('scrubs every personal key in a row the person caused about no other subject', function (): void {
    $activityId = (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'description' => 'Profile exported',
        'causer_type' => 'user',
        'causer_id' => '42',
        'attribute_changes' => json_encode(['attributes' => ['name' => 'Countess']]),
        'created_at' => '2026-11-25 12:00:00',
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual(['attributes' => ['name' => 'erased']]);
});

it('scrubs the person\'s encoded email and numeric phone in old without wiping the subject\'s contacts', function (): void {
    config()->set('audit.personal_keys', ['phone_number']);

    $activityId = seedCausedActivity('project', '7', '42', [], [
        'attributes' => [
            'name' => 'Shop',
            'email' => 'shop@example.test',
            'phone_number' => '390212345678',
            'order_reference' => '12345678',
        ],
        'old' => [
            'email' => 'ada%40example.test',
            'phone_number' => 393331234567,
            'name' => 'Store',
        ],
    ]);

    app(AuditEraser::class)->erase(
        userId: '42',
        userPublicId: (string) Str::uuid(),
        mode: AuditEraser::ANONYMISE,
        identifiers: ['Ada Lovelace', 'ada@example.test', 'ada.l', '393331234567'],
    );

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => [
            'name' => 'Shop',
            'email' => 'shop@example.test',
            'phone_number' => '390212345678',
            'order_reference' => '12345678',
        ],
        'old' => [
            'email' => 'erased',
            'phone_number' => 'erased',
            'name' => 'Store',
        ],
    ]);
});

it('scrubs a person UUID map key and a unicode-escaped identifier in causer-only attributes', function (): void {
    $personPublicId = (string) Str::uuid();
    $activityId = seedCausedActivity('project', '7', '42', [], [
        'attributes' => [
            'name' => 'Shop',
            $personPublicId => ['role' => 'owner'],
            'note' => 'contact: ada\u0040example.test',
        ],
    ]);

    app(AuditEraser::class)->erase(
        userId: '42',
        userPublicId: $personPublicId,
        mode: AuditEraser::ANONYMISE,
        identifiers: ['ada@example.test'],
    );

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => [
            'name' => 'Shop',
            'note' => 'contact: erased',
        ],
    ])->and(erasedActivityJson($activityId, 'attribute_changes')['attributes'])->not->toHaveKey($personPublicId);
});

it('scrubs numeric JSON object keys without aborting the erasure transaction', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', [], [
        'attributes' => ['name' => 'Shop'],
    ]);

    // Object key "42" becomes an int via get_object_vars; must not TypeError.
    DB::table('activity_log')->where('id', $activityId)->update([
        'attribute_changes' => json_encode([
            'attributes' => (object) ['42' => ['name' => 'Ada'], 'name' => 'Shop'],
        ], JSON_THROW_ON_ERROR),
    ]);

    eraseAda('42');

    expect(DB::table('activity_log')->where('id', $activityId)->value('anonymised_at'))->not->toBeNull()
        ->and(erasedActivityJson($activityId, 'attribute_changes')['attributes']['name'])->toBe('Shop');
});

it('scrubs a unicode-escaped space inside an identifier on a causer-only row', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', [], [
        'attributes' => [
            'name' => 'Shop',
            'note' => 'signed by Ada\\u0020Lovelace',
        ],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => [
            'name' => 'Shop',
            'note' => 'signed by erased',
        ],
    ]);
});

it('scrubs api_key_prefix on a causer-only row even without a matching credential UUID', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', [
        'ip' => '203.0.113.9',
        'user_agent' => 'Firefox',
        'api_key_prefix' => 'exc_live_ada',
    ], [
        'attributes' => ['name' => 'Shop'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'properties'))->toEqual([
        'ip' => 'erased',
        'user_agent' => 'erased',
        'api_key_prefix' => 'erased',
    ])->and(erasedActivityJson($activityId, 'attribute_changes'))->toEqual([
        'attributes' => ['name' => 'Shop'],
    ]);
});

it('does not rewrite a causer-only row on a second erasure run', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', [], [
        'attributes' => ['title' => 'Ticket for ada.l.net'],
    ]);

    $first = app(AuditEraser::class)->erase(
        userId: '42',
        userPublicId: (string) Str::uuid(),
        mode: AuditEraser::ANONYMISE,
        identifiers: ['erased.net', 'ada.l'],
    );
    $afterFirst = erasedActivityJson($activityId, 'attribute_changes');
    $anonymisedAt = DB::table('activity_log')->where('id', $activityId)->value('anonymised_at');

    $second = app(AuditEraser::class)->erase(
        userId: '42',
        userPublicId: (string) Str::uuid(),
        mode: AuditEraser::ANONYMISE,
        identifiers: ['erased.net', 'ada.l'],
    );

    expect($first->changed())->toBe(1)
        ->and($second->changed())->toBe(0)
        ->and(erasedActivityJson($activityId, 'attribute_changes'))->toEqual($afterFirst)
        ->and(DB::table('activity_log')->where('id', $activityId)->value('anonymised_at'))->toBe($anonymisedAt);
});

it('keeps empty JSON objects as objects when scrubbing another value in the same column', function (): void {
    $activityId = seedCausedActivity('project', '7', '42', [
        'ip' => '203.0.113.9',
        'meta' => new stdClass,
    ], [
        'attributes' => ['name' => 'Shop', 'options' => new stdClass],
    ]);

    // Seed with real empty objects (json_encode of stdClass), not PHP arrays.
    DB::table('activity_log')->where('id', $activityId)->update([
        'properties' => json_encode(['ip' => '203.0.113.9', 'meta' => new stdClass], JSON_THROW_ON_ERROR),
        'attribute_changes' => json_encode(['attributes' => ['name' => 'Shop', 'options' => new stdClass]], JSON_THROW_ON_ERROR),
    ]);

    eraseAda('42');

    // Drivers disagree on JSON whitespace (MySQL stores `"meta": {}`); assert
    // the decoded shape so empty objects stay objects, never lists.
    $properties = json_decode((string) DB::table('activity_log')->where('id', $activityId)->value('properties'), false, 512, JSON_THROW_ON_ERROR);
    $changes = json_decode((string) DB::table('activity_log')->where('id', $activityId)->value('attribute_changes'), false, 512, JSON_THROW_ON_ERROR);

    expect($properties->meta)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($properties->meta))->toBe([])
        ->and($changes->attributes->options)->toBeInstanceOf(stdClass::class)
        ->and(get_object_vars($changes->attributes->options))->toBe([]);
});
