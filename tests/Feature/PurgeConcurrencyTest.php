<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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

it('reads the ids to delete from the write connection, so a stale replica cannot stall the purge (Codex review)', function (): void {
    $readerPath = tempnam(sys_get_temp_dir(), 'audit-reader');
    $writerPath = tempnam(sys_get_temp_dir(), 'audit-writer');
    config()->set('database.connections.split', [
        'driver' => 'sqlite',
        'read' => ['database' => $readerPath],
        'write' => ['database' => $writerPath],
        'sticky' => false,
        'prefix' => '',
    ]);

    // The replica still holds rows the writer has already deleted.
    foreach (['reader' => $readerPath, 'writer' => $writerPath] as $endpoint => $path) {
        config()->set("database.connections.split_{$endpoint}", ['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
        Schema::connection("split_{$endpoint}")->create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable();
            $table->dateTime('created_at');
        });
    }

    foreach (range(1, 4) as $row) {
        DB::connection('split_reader')->table('activity_log')->insert(['log_name' => 'default', 'created_at' => now('UTC')->subDays(400)]);
    }

    DB::connection('split_writer')->table('activity_log')->insert(['log_name' => 'default', 'created_at' => now('UTC')->subDays(400)]);

    $selects = 0;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$selects): void {
        if ($query->connectionName === 'split' && str_starts_with(strtolower($query->sql), 'select') && ++$selects > 10) {
            throw new RuntimeException('The purge kept reading rows that were already deleted.');
        }
    });

    $defaultConnection = DB::getDefaultConnection();
    DB::setDefaultConnection('split');

    try {
        $purge = RetentionPurger::fromConfig(config())->purge(365);
    } finally {
        DB::setDefaultConnection($defaultConnection);
        @unlink($readerPath);
        @unlink($writerPath);
    }

    expect($purge['general'])->toBe(1)
        ->and(DB::connection('split_writer')->table('activity_log')->count())->toBe(0);
});

it('honours both floors and finishes when two sessions purge the same rows (Codex review)', function (): void {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Needs two sessions on a server engine.');
    }

    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.second_session', config("database.connections.{$defaultConnection}"));
    DB::purge('second_session');

    $generalOld = [];
    $securityOld = [];

    foreach (range(1, 7) as $row) {
        $generalOld[] = insertConcurrencyRow('default', 400);
        $securityOld[] = insertConcurrencyRow('auth', 800);
    }

    $kept = [insertConcurrencyRow('default', 10), insertConcurrencyRow('auth', 400), insertConcurrencyRow('security', 700)];
    $secondSession = null;
    $hasInterleaved = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$hasInterleaved, &$secondSession, $defaultConnection): void {
        if ($hasInterleaved || $query->connectionName !== $defaultConnection || ! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        $hasInterleaved = true;

        // The first session has read its first ids; another session purges everything now.
        DB::setDefaultConnection('second_session');

        try {
            $secondSession = RetentionPurger::fromConfig(config())->purge(365);
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }
    });

    $firstSession = RetentionPurger::fromConfig(config())->purge(365);

    expect(DB::table('activity_log')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe($kept)
        ->and($firstSession['general'] + $secondSession['general'])->toBe(count($generalOld))
        ->and($firstSession['security'] + $secondSession['security'])->toBe(count($securityOld))
        ->and($secondSession['general'])->toBe(count($generalOld));
});

it('finishes when the purge runs inside a repeatable-read transaction and another session deletes its rows (Codex r2)', function (): void {
    // InnoDB consistent reads: the snapshot keeps showing rows another session
    // deleted, while the delete sees they are gone. PostgreSQL raises a
    // serialization error in that case instead, and SQLite has one writer.
    if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Needs InnoDB repeatable-read snapshots.');
    }

    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.second_session', config("database.connections.{$defaultConnection}"));
    DB::purge('second_session');

    foreach (range(1, 7) as $row) {
        insertConcurrencyRow('default', 400);
        insertConcurrencyRow('auth', 800);
    }

    $kept = [insertConcurrencyRow('default', 10), insertConcurrencyRow('auth', 400)];
    $secondSession = null;
    $selects = 0;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$selects, &$secondSession, $defaultConnection): void {
        if ($query->connectionName !== $defaultConnection || ! str_starts_with(strtolower($query->sql), 'select')) {
            return;
        }

        if (++$selects > 20) {
            throw new RuntimeException('The purge kept reading rows its snapshot still shows.');
        }

        if ($selects === 1) {
            // The first session's snapshot is taken; another session purges everything now.
            DB::setDefaultConnection('second_session');

            try {
                $secondSession = RetentionPurger::fromConfig(config())->purge(365);
            } finally {
                DB::setDefaultConnection($defaultConnection);
            }
        }
    });

    // A product job calling the clean action inside its own transaction (InnoDB default: REPEATABLE READ).
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $firstSession = DB::transaction(fn (): array => RetentionPurger::fromConfig(config())->purge(365));

    expect($firstSession)->toBe(['general' => 0, 'security' => 0])
        ->and($secondSession)->toBe(['general' => 7, 'security' => 7])
        ->and(DB::table('activity_log')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all())->toBe($kept);
});
