<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
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
