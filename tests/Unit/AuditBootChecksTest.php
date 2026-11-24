<?php

declare(strict_types=1);

use Qiick\Audit\Actions\LogActivityAction;
use Qiick\Audit\Laravel\AuditBootChecks;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\Models\ProductActivity;
use Qiick\Audit\Tests\Support\ProductLogActivityAction;
use Spatie\Activitylog\Actions\LogActivityAction as SpatieLogActivityAction;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/*
 * Boot checks (design §4): the package model and action are in use, time is
 * UTC (A13), and in production the log can be neither turned off nor
 * buffered after the response (A9, OWASP: security events must not be lost
 * or turned off by one env variable).
 */

function bootChecks(string $environment = 'testing'): AuditBootChecks
{
    return new AuditBootChecks(config(), $environment === 'production');
}

it('passes with the package defaults', function (): void {
    bootChecks('production')->assert();
    bootChecks()->assert();

    expect(true)->toBeTrue();
});

it('accepts a product model and action that extend the package ones', function (): void {
    config()->set('activitylog.activity_model', ProductActivity::class);
    config()->set('activitylog.actions.log_activity', ProductLogActivityAction::class);

    bootChecks('production')->assert();

    expect(true)->toBeTrue();
});

it('fails when the activity model does not extend the package model', function (mixed $modelClass): void {
    config()->set('activitylog.activity_model', $modelClass);

    expect(fn () => bootChecks()->assert())
        ->toThrow(RuntimeException::class, 'activitylog.activity_model must extend '.Activity::class.'.');
})->with(['spatie model' => [SpatieActivity::class], 'null' => [null], 'unknown' => ['App\\Models\\Missing']]);

it('fails when the logging action does not extend the package action', function (mixed $actionClass): void {
    config()->set('activitylog.actions.log_activity', $actionClass);

    expect(fn () => bootChecks()->assert())
        ->toThrow(RuntimeException::class, 'activitylog.actions.log_activity must extend '.LogActivityAction::class.': it writes the server-side attribution.');
})->with(['spatie action' => [SpatieLogActivityAction::class], 'null' => [null]]);

it('fails when the application timezone is not UTC (A13)', function (mixed $timezone): void {
    config()->set('app.timezone', $timezone);

    expect(fn () => bootChecks()->assert())
        ->toThrow(RuntimeException::class, 'app.timezone must be UTC: audit timestamps are stored and exported in UTC.');
})->with(['Europe/Rome', 'utc', 'Etc/UTC', null]);

it('fails in production when the log is turned off (A9)', function (mixed $enabled): void {
    config()->set('activitylog.enabled', $enabled);

    expect(fn () => bootChecks('production')->assert())
        ->toThrow(RuntimeException::class, 'activitylog.enabled must be true in production: security events must not be turned off.');
})->with(['false' => [false], 'null' => [null], 'string' => ['true'], 'zero' => [0]]);

it('fails in production when the log is buffered after the response (A9)', function (mixed $bufferEnabled): void {
    config()->set('activitylog.buffer.enabled', $bufferEnabled);

    expect(fn () => bootChecks('production')->assert())
        ->toThrow(RuntimeException::class, 'activitylog.buffer.enabled must be false in production: buffered security events are lost when the process dies.');
})->with(['true' => [true], 'string' => ['1'], 'one' => [1]]);

it('allows a disabled or buffered log outside production', function (): void {
    config()->set('activitylog.enabled', false);
    config()->set('activitylog.buffer.enabled', true);

    bootChecks()->assert();

    expect(true)->toBeTrue();
});
