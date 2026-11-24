<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\ActiveIntegerRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * A11 for the causer side: activitiesAsCauser() on a bigint-keyed model
 * works on PostgreSQL (Spatie's morphMany fails there), lazily and eagerly,
 * and only for the model's own morph type. Runs on all four engines in CI.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

it('reads the activity a model caused (A11)', function (): void {
    $causer = ActiveIntegerRecord::query()->create(['name' => 'causer']);
    $other = ActiveIntegerRecord::query()->create(['name' => 'other']);

    activity()->causedBy($causer)->log('first');
    activity()->causedBy($causer)->log('second');
    activity()->causedBy($other)->log('theirs');

    expect($causer->activitiesAsCauser()->orderBy('id')->pluck('description')->all())->toBe(['first', 'second']);
});

it('eager-loads only the rows of the causer\'s own morph type (A11)', function (): void {
    $causer = ActiveIntegerRecord::query()->create(['name' => 'causer']);

    activity()->causedBy($causer)->log('mine');
    activity()->causedBy(IntegerKeyedRecord::query()->findOrFail($causer->getKey()))->log('other type, same id');

    $loaded = ActiveIntegerRecord::query()->with('activitiesAsCauser')->sole();

    expect($loaded->activitiesAsCauser->pluck('description')->all())->toBe(['mine']);
});

it('reads the activity about a HasActivity model through activities()', function (): void {
    $record = ActiveIntegerRecord::query()->create(['name' => 'first']);
    $record->update(['name' => 'second']);

    expect($record->activities()->orderBy('id')->pluck('event')->all())->toBe(['created', 'updated']);
});
