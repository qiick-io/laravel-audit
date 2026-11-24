<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Models\Activity;
use Spatie\Activitylog\Models\Activity as SpatieActivity;
use Spatie\Activitylog\Support\ActivityBuffer;

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    config()->set('activitylog.buffer.enabled', true);
});

it('fills public_id before the buffer takes the row', function (): void {
    $activity = new Activity;

    app(LogActivityAction::class)->execute($activity, 'buffered');

    expect($activity->public_id)->toBeString()->toHaveLength(36)
        ->and($activity->schema_version)->toBe(1)
        ->and(app(ActivityBuffer::class)->count())->toBe(1);
});

it('leaves a model without unique ids to Spatie', function (): void {
    app(LogActivityAction::class)->execute(new SpatieActivity, 'buffered');

    expect(app(ActivityBuffer::class)->count())->toBe(1);
});

it('writes the current schema_version over a caller value and the column default', function (): void {
    config()->set('activitylog.buffer.enabled', false);

    activity()->tap(function (Activity $activity): void {
        $activity->schema_version = 0;
    })->log('forged legacy shape');

    expect(Activity::query()->sole()->schema_version)->toBe(1);
});

it('replaces a public_id the caller set', function (): void {
    config()->set('activitylog.buffer.enabled', false);

    activity()->tap(function (Activity $activity): void {
        $activity->public_id = '0193a3b0-7c1d-7000-8000-00000000beef';
    })->log('forged id');

    expect(Activity::query()->sole()->public_id)->not->toBe('0193a3b0-7c1d-7000-8000-00000000beef');
});
