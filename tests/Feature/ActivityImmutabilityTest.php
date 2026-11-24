<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\Models\GuardedProductActivity;

/*
 * An audit row is written once (review of #2): the model refuses to update
 * or delete it, and the package's own columns are not mass-assignable. Only
 * the anonymiser and the retention purge change rows, through the query
 * builder.
 */

uses(DatabaseMigrations::class);

it('refuses to update a stored row through the model', function (): void {
    $activity = activity()->log('original');
    assert($activity instanceof Activity);

    $activity->description = 'tampered';

    expect(fn () => $activity->save())
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(Activity::query()->sole()->description)->toBe('original');
});

it('refuses to delete a stored row through the model', function (): void {
    $activity = activity()->log('evidence');
    assert($activity instanceof Activity);

    expect(fn () => $activity->delete())
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(Activity::query()->count())->toBe(1);
});

it('does not mass-assign the package columns', function (): void {
    $activity = new Activity([
        'id' => 99,
        'description' => 'filled',
        'public_id' => '0193a3b0-7c1d-7000-8000-00000000beef',
        'organisation_id' => '0193a3b0-7c1d-7000-8000-00000000cafe',
        'schema_version' => 0,
        'request_id' => 'forged',
        'anonymised_at' => '2026-11-24 10:00:00',
    ]);

    expect($activity->getAttributes())->toBe(['description' => 'filled']);
});

it('keeps the guards a product model adds', function (): void {
    $activity = new GuardedProductActivity(['description' => 'filled', 'log_name' => 'forged', 'public_id' => 'forged']);

    expect($activity->getAttributes())->toBe(['description' => 'filled']);
});

it('refuses the quiet save and delete that skip model events', function (): void {
    $activity = activity()->log('evidence');
    assert($activity instanceof Activity);

    $activity->description = 'tampered';

    expect(fn () => $activity->saveQuietly())
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(fn () => $activity->deleteQuietly())
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(Activity::query()->sole()->description)->toBe('evidence');
});

it('still saves a new row quietly', function (): void {
    $activity = new Activity(['description' => 'quiet insert']);

    expect($activity->saveQuietly())->toBeTrue()
        ->and(Activity::query()->sole()->description)->toBe('quiet insert');
});

it('does not mass-assign the package columns even when unguarded', function (): void {
    $activity = Activity::unguarded(fn () => new Activity([
        'description' => 'filled',
        'public_id' => '0193a3b0-7c1d-7000-8000-00000000beef',
        'organisation_id' => '0193a3b0-7c1d-7000-8000-00000000cafe',
    ]));

    expect($activity->getAttributes())->toBe(['description' => 'filled']);
});

it('refuses an update or a delete without model events', function (): void {
    $activity = activity()->log('evidence');
    assert($activity instanceof Activity);

    expect(fn () => Activity::withoutEvents(fn () => $activity->forceFill(['description' => 'tampered'])->save()))
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(fn () => Activity::withoutEvents(fn () => $activity->delete()))
        ->toThrow(LogicException::class, 'Activity rows are written once: only the anonymiser and the retention purge change them.')
        ->and(Activity::query()->sole()->description)->toBe('evidence');
});

it('saves a stored row that has not changed without complaint', function (): void {
    $activity = activity()->log('evidence');
    assert($activity instanceof Activity);

    expect($activity->save())->toBeTrue();
});
