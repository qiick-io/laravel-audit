<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Qiick\Audit\Support\RetentionPurger;

/*
 * RC review M5: two purges can overlap (two servers, or a slow run and the
 * next daily tick). A purge reads ids, then deletes them; these tests run a
 * second purge, or change a row, between the two steps of the first, and
 * check that the floors still hold and every old row goes exactly once.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 11, 25)->setTime(12, 0));
    config()->set('audit.purge.chunk_size', 3);
});

function insertConcurrencyRow(string $logName, int $ageInDays): int
{
    return (int) DB::table('activity_log')->insertGetId([
        'public_id' => (string) Str::uuid7(),
        'log_name' => $logName,
        'description' => "{$logName} {$ageInDays}",
        'created_at' => now('UTC')->subDays($ageInDays),
    ]);
}

/**
 * Runs $interleave once, right after the first purge has read its first
 * chunk of ids and before it deletes them.
 */
function afterFirstIdSelect(Closure $interleave): void
{
    $hasInterleaved = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$hasInterleaved, $interleave): void {
        if ($hasInterleaved || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'activity_log')) {
            return;
        }

        $hasInterleaved = true;
        $interleave();
    });
}

it('deletes every old row once when a second purge runs inside the first', function (): void {
    $oldRows = [];

    foreach (range(1, 7) as $row) {
        $oldRows[] = insertConcurrencyRow('default', 400);
    }

    $youngRows = [insertConcurrencyRow('default', 10), insertConcurrencyRow('auth', 400)];
    $secondPurge = null;

    afterFirstIdSelect(function () use (&$secondPurge): void {
        $secondPurge = RetentionPurger::fromConfig(config())->purge(365);
    });

    $firstPurge = RetentionPurger::fromConfig(config())->purge(365);

    expect(DB::table('activity_log')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe($youngRows)
        ->and($firstPurge['general'] + $secondPurge['general'])->toBe(count($oldRows))
        ->and($firstPurge['security'] + $secondPurge['security'])->toBe(0);
});

it('never deletes a row that is no longer past its floor when the delete runs', function (): void {
    $rowMadeYoung = insertConcurrencyRow('default', 400);
    $oldRow = insertConcurrencyRow('default', 400);

    afterFirstIdSelect(function () use ($rowMadeYoung): void {
        DB::table('activity_log')->where('id', $rowMadeYoung)->update(['created_at' => now('UTC')]);
    });

    $purge = RetentionPurger::fromConfig(config())->purge(365);

    expect($purge['general'])->toBe(1)
        ->and(DB::table('activity_log')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe([$rowMadeYoung])
        ->and(DB::table('activity_log')->where('id', $oldRow)->exists())->toBeFalse();
});

it('schedules the purge on one server and without overlapping runs', function (): void {
    $purgeEvent = collect(app(Schedule::class)->events())
        ->first(fn (ScheduledEvent $event): bool => str_contains((string) $event->command, 'audit:purge'));

    expect($purgeEvent)->not->toBeNull()
        ->and($purgeEvent->withoutOverlapping)->toBeTrue()
        ->and($purgeEvent->onOneServer)->toBeTrue();
});
