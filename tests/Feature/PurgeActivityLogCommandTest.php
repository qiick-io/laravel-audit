<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Retention (Luca, 2026-11-23, design §10): every log is kept 365 days, the
 * `auth` security log 730 days. audit:purge deletes in two chunked passes on
 * created_at, so a 365-day pass can never purge an auth row early; it
 * is what Spatie's activitylog:clean runs too (CleanActivityLogActionTest).
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 11, 24)->setTime(12, 0));
});

function insertActivityRow(?string $logName, int $ageInDays, int $extraSeconds = 0): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'log_name' => $logName,
        'description' => "{$logName} {$ageInDays}",
        'created_at' => now('UTC')->subDays($ageInDays)->addSeconds($extraSeconds),
        'updated_at' => now('UTC'),
    ]);
}

/**
 * @return list<int>
 */
function remainingActivityIds(): array
{
    return DB::table('activity_log')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

it('deletes non-auth rows older than 365 days and keeps younger ones', function (): void {
    $oldDefault = insertActivityRow('default', 366);
    $oldUnnamed = insertActivityRow(null, 400);
    $oldOther = insertActivityRow('projects', 365, -1);
    $keptDefault = insertActivityRow('default', 365, 1);
    $keptRecent = insertActivityRow('projects', 10);

    $this->artisan('audit:purge')->assertSuccessful();

    expect(remainingActivityIds())->toBe([$keptDefault, $keptRecent])
        ->and(remainingActivityIds())->not->toContain($oldDefault, $oldUnnamed, $oldOther);
});

it('keeps auth rows until 730 days, whatever the general floor (two floors)', function (): void {
    $authAt400 = insertActivityRow('auth', 400);
    $authAt729 = insertActivityRow('auth', 730, 1);
    $authAt731 = insertActivityRow('auth', 730, -1);

    $this->artisan('audit:purge')->assertSuccessful();

    expect(remainingActivityIds())->toBe([$authAt400, $authAt729]);
});

it('keeps every security log 730 days (decision 2026-11-24)', function (): void {
    $kept = [
        insertActivityRow('auth', 400),
        insertActivityRow('security', 400),
        insertActivityRow('access', 400),
        insertActivityRow('api-keys', 400),
    ];
    $purged = [
        insertActivityRow('security', 731),
        insertActivityRow('api-keys', 731),
        insertActivityRow('auth2', 400),
        insertActivityRow('apikeys', 400),
    ];

    $this->artisan('audit:purge')->assertSuccessful();

    expect(remainingActivityIds())->toBe($kept)
        ->and(remainingActivityIds())->not->toContain(...$purged);
});

it('keeps a security log written in another case or with spaces (review)', function (): void {
    activity(' Auth ')->log('login');
    activity('API-Keys')->log('key rotated');
    DB::table('activity_log')->update(['created_at' => now('UTC')->subDays(400)]);

    expect(DB::table('activity_log')->orderBy('id')->pluck('log_name')->all())->toBe(['auth', 'api-keys']);

    $this->artisan('audit:purge')->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe(2);
});

it('reads the floors and the security log name from the config', function (): void {
    config()->set('audit.retention_days', 30);
    config()->set('audit.security_retention_days', 90);
    config()->set('audit.security_log_names', ['Security']);
    $default31 = insertActivityRow('default', 31);
    $default29 = insertActivityRow('default', 29);
    $security89 = insertActivityRow('security', 89);
    $security91 = insertActivityRow('security', 91);
    $auth31 = insertActivityRow('auth', 31);

    $this->artisan('audit:purge')->assertSuccessful();

    expect(remainingActivityIds())->toBe([$default29, $security89]);
});

it('deletes in chunks and reports both passes', function (): void {
    config()->set('audit.purge.chunk_size', 2);

    foreach (range(1, 5) as $row) {
        insertActivityRow('default', 400);
    }

    foreach (range(1, 3) as $row) {
        insertActivityRow('auth', 800);
    }

    $kept = insertActivityRow('auth', 400);

    $this->artisan('audit:purge')
        ->expectsOutput('Purged 5 rows older than 365 days and 3 security log rows (auth, security, access, api-keys) older than 730 days.')
        ->assertSuccessful();

    expect(remainingActivityIds())->toBe([$kept]);
});

it('deletes nothing when nothing is old', function (): void {
    $kept = insertActivityRow('default', 1);

    $this->artisan('audit:purge')
        ->expectsOutput('Purged 0 rows older than 365 days and 0 security log rows (auth, security, access, api-keys) older than 730 days.')
        ->assertSuccessful();

    expect(remainingActivityIds())->toBe([$kept]);
});

it('refuses retention settings that would purge early or never', function (string $key, mixed $value, string $message): void {
    config()->set($key, $value);
    $row = insertActivityRow('default', 400);

    $this->artisan('audit:purge')->expectsOutputToContain($message)->assertFailed();

    expect(remainingActivityIds())->toBe([$row]);
})->with([
    ['audit.retention_days', 0, 'audit.retention_days must be a positive number of days.'],
    ['audit.retention_days', '365', 'audit.retention_days must be a positive number of days.'],
    ['audit.security_retention_days', -1, 'audit.security_retention_days must be a positive number of days.'],
    ['audit.security_retention_days', 300, 'audit.security_retention_days must not be shorter than audit.retention_days.'],
    ['audit.security_log_names', [], 'audit.security_log_names must be a non-empty list of log names.'],
    ['audit.security_log_names', ['auth', ''], 'audit.security_log_names must be a non-empty list of log names.'],
    ['audit.security_log_names', ['auth', 7], 'audit.security_log_names must be a non-empty list of log names.'],
    ['audit.security_log_names', 'auth', 'audit.security_log_names must be a non-empty list of log names.'],
    ['audit.security_log_names', null, 'audit.security_log_names must be a non-empty list of log names.'],
    ['audit.purge.chunk_size', 0, 'audit.purge.chunk_size must be a positive integer.'],
]);

it('accepts equal floors and the smallest values', function (): void {
    config()->set('audit.retention_days', 1);
    config()->set('audit.security_retention_days', 1);
    config()->set('audit.purge.chunk_size', 1);
    $old = insertActivityRow('auth', 2);

    $this->artisan('audit:purge')->assertSuccessful();

    expect(remainingActivityIds())->toBe([]);
});

it('feeds audit.retention_days to Spatie\'s clean_after_days', function (): void {
    expect(config('activitylog.clean_after_days'))->toBe(365);
});

it('schedules the purge daily at 03:00 UTC by default', function (): void {
    $purgeEvents = collect(app(Schedule::class)->events())
        ->filter(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'audit:purge'))
        ->values();

    expect($purgeEvents)->toHaveCount(1)
        ->and($purgeEvents[0]->expression)->toBe('0 3 * * *')
        ->and($purgeEvents[0]->timezone)->toBe('UTC');
});
