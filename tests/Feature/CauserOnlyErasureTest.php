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

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toBe([
        'attributes' => ['name' => 'Shop', 'username' => 'shop-bot'],
        'old' => ['name' => 'Store'],
    ])->and(erasedActivityJson($activityId, 'properties'))->toBe(['ip' => 'erased', 'user_agent' => 'erased', 'email' => 'erased', 'source' => 'settings page']);
});

it('scrubs the person\'s identifiers inside the other subject\'s attributes', function (): void {
    $activityId = seedCausedActivity('ticket', '9', '42', [], [
        'attributes' => ['title' => 'Login broken', 'assigned_to' => 'Ada Lovelace', 'reporter_email' => 'ada@example.test', 'owner' => 'ada.l'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toBe([
        'attributes' => ['title' => 'Login broken', 'assigned_to' => 'erased', 'reporter_email' => 'erased', 'owner' => 'erased'],
    ]);
});

it('scrubs every personal key where the person is the subject', function (): void {
    $activityId = seedCausedActivity('user', '42', '1', ['ip' => '198.51.100.4'], [
        'attributes' => ['name' => 'Countess', 'username' => 'lovelace', 'role' => 'admin'],
    ]);

    eraseAda('42');

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toBe([
        'attributes' => ['name' => 'erased', 'username' => 'erased', 'role' => 'admin'],
    ])->and(erasedActivityJson($activityId, 'properties'))->toBe(['ip' => 'erased']);
});

it('scrubs every personal key where the person is the credential', function (): void {
    $keyPublicId = (string) Str::uuid();
    $activityId = seedCausedActivity('project', '7', '1', ['api_key_id' => $keyPublicId], [
        'attributes' => ['name' => 'Shop'],
    ]);

    app(AuditEraser::class)->erase(userId: '42', userPublicId: (string) Str::uuid(), mode: AuditEraser::ANONYMISE, publicIds: [$keyPublicId]);

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toBe(['attributes' => ['name' => 'erased']]);
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

    expect(erasedActivityJson($activityId, 'attribute_changes'))->toBe(['attributes' => ['name' => 'erased']]);
});
