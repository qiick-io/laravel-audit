<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Audit\Actions\CleanActivityLogAction;
use Spatie\Activitylog\Actions\CleanActivityLogAction as SpatieCleanActivityLogAction;

/*
 * Analysis AU-l: Spatie's activitylog:clean takes one cutoff, so a product
 * that still schedules it would delete the auth log after 365 days, or
 * after one with --days=1. The package's clean action applies the two
 * retention floors, so no path deletes a row younger than its floor.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 11, 24)->setTime(12, 0));
});

function insertCleanRow(?string $logName, int $ageInDays): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'log_name' => $logName,
        'description' => "{$logName} {$ageInDays}",
        'created_at' => now('UTC')->subDays($ageInDays),
    ]);
}

/**
 * @return list<int>
 */
function rowsLeft(): array
{
    return DB::table('activity_log')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
}

it('is the configured clean action', function (): void {
    expect(config('activitylog.actions.clean_log'))->toBe(CleanActivityLogAction::class)
        ->and(new CleanActivityLogAction)->toBeInstanceOf(SpatieCleanActivityLogAction::class);
});

it('never deletes rows younger than their floor, even with --days=1', function (): void {
    $general10 = insertCleanRow('default', 10);
    $general400 = insertCleanRow('default', 400);
    $unnamed400 = insertCleanRow(null, 400);
    $auth400 = insertCleanRow('auth', 400);
    $auth800 = insertCleanRow('auth', 800);

    $this->artisan('activitylog:clean', ['--days' => 1, '--force' => true])
        ->expectsOutputToContain('Deleted 3 record(s) from the activity log.')
        ->assertSuccessful();

    expect(rowsLeft())->toBe([$general10, $auth400])
        ->and(rowsLeft())->not->toContain($general400, $unnamed400, $auth800);
});

it('keeps the auth log 730 days with Spatie\'s default cutoff', function (): void {
    $auth400 = insertCleanRow('auth', 400);
    insertCleanRow('default', 400);

    $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

    expect(rowsLeft())->toBe([$auth400]);
});

it('honours a longer --days for both logs', function (): void {
    $general400 = insertCleanRow('default', 400);
    $auth800 = insertCleanRow('auth', 800);
    insertCleanRow('default', 1000);
    insertCleanRow('auth', 1000);

    $this->artisan('activitylog:clean', ['--days' => 900, '--force' => true])->assertSuccessful();

    expect(rowsLeft())->toBe([$general400, $auth800]);
});

it('cleans only the named log, with that log\'s floor', function (): void {
    $auth400 = insertCleanRow('auth', 400);
    $auth800 = insertCleanRow('auth', 800);
    $projects400 = insertCleanRow('projects', 400);
    $default400 = insertCleanRow('default', 400);

    $this->artisan('activitylog:clean', ['log' => 'auth', '--days' => 1, '--force' => true])
        ->expectsOutputToContain('Deleted 1 record(s) from the activity log.')
        ->assertSuccessful();

    expect(rowsLeft())->toBe([$auth400, $projects400, $default400]);

    $this->artisan('activitylog:clean', ['log' => 'projects', '--days' => 1, '--force' => true])
        ->expectsOutputToContain('Deleted 1 record(s) from the activity log.')
        ->assertSuccessful();

    expect(rowsLeft())->toBe([$auth400, $default400]);
});

it('deletes nothing when the retention config is invalid', function (): void {
    config()->set('audit.security_retention_days', 10);
    $row = insertCleanRow('auth', 400);

    expect(fn () => app(CleanActivityLogAction::class)->execute(1))
        ->toThrow(InvalidArgumentException::class, 'audit.security_retention_days must not be shorter than audit.retention_days.')
        ->and(rowsLeft())->toBe([$row]);
});

it('cleans a named security log with the security floor', function (): void {
    $apiKeys400 = insertCleanRow('api-keys', 400);
    insertCleanRow('api-keys', 800);

    $this->artisan('activitylog:clean', ['log' => 'API-Keys', '--days' => 1, '--force' => true])
        ->expectsOutputToContain('Deleted 1 record(s) from the activity log.')
        ->assertSuccessful();

    expect(rowsLeft())->toBe([$apiKeys400]);
});
