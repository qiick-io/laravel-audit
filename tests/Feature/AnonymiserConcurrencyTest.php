<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\MorphRef;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;

/*
 * Codex review, concurrent erasures: one row can belong to two people (one
 * causer, the other subject). Two erasures in independent database sessions
 * must not overwrite each other: the second one to write would otherwise put
 * back the name the first one scrubbed. The anonymiser locks the rows it
 * reads, so the other session waits for its commit and reads the scrubbed row.
 *
 * Runs on PostgreSQL, MySQL and MariaDB with a second connection to the same
 * database (SQLite has no row locks and serialises writers).
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    if (in_array(DB::getDriverName(), ['sqlite'], true)) {
        $this->markTestSkipped('Needs two sessions on a server engine.');
    }

    CreatesKeyedRecordTables::create();
    Relation::morphMap(['user' => AuthenticatableRecord::class]);

    $defaultConnection = DB::getDefaultConnection();
    config()->set('database.connections.second_session', config("database.connections.{$defaultConnection}"));
    DB::purge('second_session');

    // The second session gives up on a lock after a second instead of waiting.
    DB::connection('second_session')->statement(DB::getDriverName() === 'pgsql'
        ? "SET lock_timeout = '1s'"
        : 'SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function (): void {
    Relation::morphMap([], false);
});

it('never restores a name another erasure scrubbed while both run in separate sessions', function (): void {
    $ada = AuthenticatableRecord::query()->create(['name' => 'Ada Lovelace']);
    $grace = AuthenticatableRecord::query()->create(['name' => 'Grace Hopper']);
    activity()->causedBy($ada)->performedOn($grace)->log('Ada Lovelace reset the password of Grace Hopper');

    $defaultConnection = DB::getDefaultConnection();
    $eraseGrace = function () use ($grace, $defaultConnection): void {
        DB::setDefaultConnection('second_session');

        try {
            app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($grace)], 'erased-grace', ['Grace Hopper']));
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }
    };

    $secondSessionOutcome = null;
    $hasInterleaved = false;

    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$hasInterleaved, &$secondSessionOutcome, $eraseGrace, $defaultConnection): void {
        if ($hasInterleaved || $query->connectionName !== $defaultConnection || ! str_contains($query->sql, 'for update') || ! str_contains($query->sql, 'activity_log')) {
            return;
        }

        $hasInterleaved = true;

        // Ada's erasure has read the row; Grace's erasure runs in another session now.
        try {
            $eraseGrace();
            $secondSessionOutcome = 'committed';
        } catch (QueryException $exception) {
            // Only a lock wait timeout proves the row lock: PostgreSQL 55P03, MySQL/MariaDB 1205.
            $isLockWait = DB::getDriverName() === 'pgsql'
                ? $exception->getCode() === '55P03'
                : ($exception->errorInfo[1] ?? null) === 1205;

            if (! $isLockWait) {
                throw $exception;
            }

            $secondSessionOutcome = 'waited for the lock';
        }
    });

    app(AuditAnonymiser::class)->anonymise(new ErasureSubject([MorphRef::of($ada)], 'erased-ada', ['Ada Lovelace']));

    expect($secondSessionOutcome)->toBe('waited for the lock');

    // Grace's erasure is retried after Ada's committed, as a failed job is.
    $eraseGrace();

    expect(DB::table('activity_log')->value('description'))->toBe('erased-ada reset the password of erased-grace');
});
