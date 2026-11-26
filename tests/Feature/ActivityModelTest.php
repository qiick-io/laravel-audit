<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;
use Qiick\Audit\Tests\Support\Models\ProductActivity;
use Ramsey\Uuid\Uuid;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/*
 * Design §3 / §4: the package model extends Spatie's, is the configured
 * activity model, and generates public_id (UUID v7, RFC 9562) itself.
 */

uses(DatabaseMigrations::class);

it('extends the Spatie model and keeps its table', function (): void {
    expect(new Activity)->toBeInstanceOf(SpatieActivity::class)
        ->and((new Activity)->getTable())->toBe('activity_log');
});

it('is the configured activity model', function (): void {
    expect(config('activitylog.activity_model'))->toBe(Activity::class);
});

it('generates a UUID v7 public_id and keeps the bigint primary key', function (): void {
    $activity = activity()->log('created');

    expect($activity)->toBeInstanceOf(Activity::class);

    $publicId = DB::table('activity_log')->value('public_id');

    expect(Uuid::isValid($publicId))->toBeTrue()
        ->and(Uuid::fromString($publicId)->getFields()->getVersion())->toBe(7)
        ->and($activity->getKey())->toBeInt()
        ->and($activity->getKeyName())->toBe('id')
        ->and($activity->getIncrementing())->toBeTrue();
});

it('gives every row its own public_id', function (): void {
    activity()->log('first');
    activity()->log('second');

    expect(DB::table('activity_log')->distinct()->count('public_id'))->toBe(2);
});

it('writes schema_version 1 and casts it and anonymised_at', function (): void {
    activity()->log('current format');

    $activity = Activity::query()->sole();

    expect($activity->schema_version)->toBe(1)
        ->and($activity->anonymised_at)->toBeNull();

    DB::table('activity_log')->update(['anonymised_at' => '2026-11-24 10:00:00']);

    expect(Activity::query()->sole()->anonymised_at?->toIso8601ZuluString())->toBe('2026-11-24T10:00:00Z');
});

it('is logged through the package action', function (): void {
    expect(config('activitylog.actions.log_activity'))->toBe(LogActivityAction::class);
});

it('reads anonymised_at as an immutable UTC instant', function (): void {
    $activity = (new Activity)->setRawAttributes(['anonymised_at' => '2026-11-24 10:00:00']);

    expect($activity->anonymised_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($activity->anonymised_at?->toIso8601ZuluString())->toBe('2026-11-24T10:00:00Z');
});

it('keeps the Spatie casts of properties and attribute_changes', function (): void {
    $activity = (new Activity)->setRawAttributes([
        'properties' => '{"ip":"192.0.2.1"}',
        'attribute_changes' => '{"attributes":{"name":"new"}}',
    ]);

    expect($activity->properties)->toBeInstanceOf(Collection::class)
        ->and($activity->properties?->get('ip'))->toBe('192.0.2.1')
        ->and($activity->attribute_changes)->toBeInstanceOf(Collection::class);
});

it('builds the configured activity model', function (): void {
    config()->set('activitylog.activity_model', ProductActivity::class);

    expect(Activity::newConfiguredModel())->toBeInstanceOf(ProductActivity::class);
});

it('refuses an activity model that does not extend the package model', function (mixed $modelClass): void {
    config()->set('activitylog.activity_model', $modelClass);

    expect(fn () => Activity::newConfiguredModel())
        ->toThrow(LogicException::class, 'activitylog.activity_model must extend '.Activity::class.'.');
})->with([
    'spatie model' => [SpatieActivity::class],
    'null' => [null],
    'not a class' => ['nope'],
]);

it('refuses an empty or non-string morph column', function (mixed $column): void {
    config()->set('audit.columns.causer_id', $column);

    expect(fn () => Activity::idColumn('causer_id'))
        ->toThrow(InvalidArgumentException::class, 'audit.columns.causer_id must name a column.');
})->with(['empty' => [''], 'null' => [null], 'array' => [['causer_ref']]]);

it('refuses a morph model without an integer or string key', function (): void {
    $record = new IntegerKeyedRecord;

    expect(fn () => Activity::query()->causedBy($record))
        ->toThrow(InvalidArgumentException::class, 'An activity morph needs a model with an integer or string key.');
});

it('includes soft-deleted subjects only when Spatie is told to', function (): void {
    expect((new Activity)->subject()->removedScopes())->toBe([]);

    config()->set('activitylog.include_soft_deleted_subjects', true);

    expect((new Activity)->subject()->removedScopes())->toBe([SoftDeletingScope::class]);
});
