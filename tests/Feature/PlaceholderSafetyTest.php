<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * Review B3: a description placeholder reads only attributes and relations
 * already loaded on the model, and never calls a model method. Text a user
 * typed into a description (`:subject.delete.x`) must not delete anything.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

it('never runs a model method named in a placeholder', function (string $placeholder): void {
    $record = IntegerKeyedRecord::query()->create(['name' => 'victim']);

    activity()->performedOn($record)->causedBy($record)->log("renamed to {$placeholder}");

    expect(IntegerKeyedRecord::query()->whereKey($record->getKey())->exists())->toBeTrue()
        ->and(Activity::query()->sole()->description)->toBe("renamed to {$placeholder}");
})->with([':subject.delete.x', ':causer.delete.x', ':subject.forceDelete.x', ':subject.touch.x', ':subject.replicate.name', ':subject.delete']);

it('resolves an attribute of the subject and of a loaded relation', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'Owner']);
    $record = IntegerKeyedRecord::query()->create(['name' => 'Child']);
    $record->setRelation('ownerRecord', $owner);

    activity()->performedOn($record)->log(':subject.name of :subject.ownerRecord.name');

    expect(Activity::query()->sole()->description)->toBe('Child of Owner');
});

it('leaves a placeholder through a relation that is not loaded', function (): void {
    $record = IntegerKeyedRecord::query()->create(['name' => 'Child']);

    activity()->performedOn($record)->log(':subject.ownerRecord.name');

    expect(Activity::query()->sole()->description)->toBe(':subject.ownerRecord.name');
});

it('matches property keys to the floor whatever their spelling (review B4)', function (): void {
    activity()->withProperties(['apiSecret' => 'AS', 'note' => 'ok'])->log(':properties.apiSecret :properties.note');

    expect(Activity::query()->sole()->description)->toBe(':properties.apiSecret ok');
});

it('reads only the subject, the causer and the properties', function (): void {
    activity()->withChanges(['attributes' => ['name' => 'changed']])->log(':attribute_changes.attributes.name');

    expect(Activity::query()->sole()->description)->toBe(':attribute_changes.attributes.name');
});
