<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;

/*
 * F2: `audit:check` is the deploy step that proves the retention config, the
 * purge schedule and the activity_log table before traffic arrives, instead of
 * the first purge or the first logged write failing in production.
 */

uses(DatabaseMigrations::class);

it('passes on a migrated app with the package defaults', function (): void {
    $this->artisan('audit:check')
        ->expectsOutput('Audit configuration and table are ready.')
        ->assertExitCode(0);
});

it('fails when the security floor is shorter than the general one', function (): void {
    config()->set('audit.security_retention_days', 30);

    $this->artisan('audit:check')
        ->expectsOutput('audit.security_retention_days must not be shorter than audit.retention_days.')
        ->assertExitCode(1);
});

it('fails when the scheduled purge time is not HH:MM', function (): void {
    config()->set('audit.purge.time', '3am');
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('audit:check')
        ->expectsOutput('audit.purge.time must be a time of day as HH:MM.')
        ->assertExitCode(1);
});

it('fails when the activity_log table is missing', function (): void {
    Schema::drop('activity_log');

    $this->artisan('audit:check')
        ->expectsOutput('The activity_log table is missing or lacks the columns public_id, organisation_id, subject_id, causer_id.')
        ->assertExitCode(1);
});

it('fails when a configured morph column does not exist', function (): void {
    config()->set('audit.columns.subject_id', 'subject_ref');

    $this->artisan('audit:check')
        ->expectsOutput('The activity_log table is missing or lacks the columns public_id, organisation_id, subject_ref, causer_id.')
        ->assertExitCode(1);
});
