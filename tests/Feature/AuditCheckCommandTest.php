<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Schema\Blueprint;
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
        ->expectsOutput('The activity_log table is missing or lacks the columns id, public_id, log_name, description, event, subject_type, subject_id, causer_type, causer_id, organisation_id, attribute_changes, properties, schema_version, request_id, anonymised_at, created_at, updated_at.')
        ->assertExitCode(1);
});

it('fails when a configured morph column does not exist', function (): void {
    config()->set('audit.columns.subject_id', 'subject_ref');

    $this->artisan('audit:check')
        ->expectsOutput('The activity_log table is missing or lacks the columns id, public_id, log_name, description, event, subject_type, subject_ref, causer_type, causer_id, organisation_id, attribute_changes, properties, schema_version, request_id, anonymised_at, created_at, updated_at.')
        ->assertExitCode(1);
});

it('fails when a column the first write needs is missing', function (): void {
    Schema::drop('activity_log');
    Schema::create('activity_log', function (Blueprint $table): void {
        $table->id();
        $table->uuid('public_id');
        $table->uuid('organisation_id')->nullable();
        $table->string('subject_id', 64)->nullable();
        $table->string('causer_id', 64)->nullable();
    });

    $this->artisan('audit:check')->assertExitCode(1);
});

it('ignores the purge time when the product schedules the purge itself', function (): void {
    config()->set('audit.purge.schedule', false);
    config()->set('audit.purge.time', '3am');
    $this->app->forgetInstance(Schedule::class);

    $this->artisan('audit:check')->assertExitCode(0);
});
