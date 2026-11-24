<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;
use Qiick\Audit\Tests\Support\Models\UlidKeyedRecord;
use Qiick\Audit\Tests\Support\Models\UuidKeyedRecord;
use Spatie\Activitylog\Support\ActivityBuffer;

/*
 * Design §6.3 spike, run on SQLite, PostgreSQL, MySQL and MariaDB: Spatie v5
 * writes the morph ids only through the subject() / causer() relations
 * (associate()), on the direct path and on the buffered path, so string(64)
 * columns hold bigint, UUID and ULID keys unchanged. The one hard-coded read
 * (Support/ActivityLogger.php:229, getAttribute('causer_id')) matters only
 * when audit.columns renames the columns; that override lands with the model
 * API.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

dataset('keyed records', [
    'bigint key' => fn (): Model => IntegerKeyedRecord::query()->create(['name' => 'bigint']),
    'uuid key' => fn (): Model => UuidKeyedRecord::query()->create(['name' => 'uuid']),
    'ulid key' => fn (): Model => UlidKeyedRecord::query()->create(['name' => 'ulid']),
]);

it('writes subject and causer ids as strings through associate()', function (Model $record): void {
    activity()->performedOn($record)->causedBy($record)->log('direct');

    $row = DB::table('activity_log')->sole();

    expect($row->subject_type)->toBe($record->getMorphClass())
        ->and($row->subject_id)->toBe((string) $record->getKey())
        ->and($row->causer_type)->toBe($record->getMorphClass())
        ->and($row->causer_id)->toBe((string) $record->getKey());

    $activity = Activity::query()->sole();

    expect($activity->subject?->is($record))->toBeTrue()
        ->and($activity->causer?->is($record))->toBeTrue();
})->with('keyed records');

it('writes the same ids through the buffer', function (Model $record): void {
    config()->set('activitylog.buffer.enabled', true);

    activity()->performedOn($record)->causedBy($record)->log('buffered');

    expect(DB::table('activity_log')->count())->toBe(0);

    app(ActivityBuffer::class)->flush();

    $row = DB::table('activity_log')->sole();

    expect($row->subject_id)->toBe((string) $record->getKey())
        ->and($row->causer_id)->toBe((string) $record->getKey())
        ->and($row->public_id)->toBeString()->not->toBeEmpty();
})->with('keyed records');
