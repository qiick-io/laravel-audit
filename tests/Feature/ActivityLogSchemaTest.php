<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Design §3: the activity_log table, final for 1.0, on every engine the fleet
 * supports. The migration is loaded by the provider (audit.migrations.load).
 */

uses(DatabaseMigrations::class);

/**
 * @return array<string, array<string, mixed>>
 */
function activityLogColumns(): array
{
    return collect(Schema::getColumns('activity_log'))->keyBy('name')->all();
}

/**
 * @return array<string, array<string, mixed>>
 */
function activityLogIndexes(): array
{
    return collect(Schema::getIndexes('activity_log'))->keyBy('name')->all();
}

it('has exactly the design columns', function (): void {
    expect(array_keys(activityLogColumns()))->toEqualCanonicalizing([
        'id',
        'public_id',
        'log_name',
        'description',
        'event',
        'subject_type',
        'subject_id',
        'causer_type',
        'causer_id',
        'organisation_id',
        'attribute_changes',
        'properties',
        'schema_version',
        'request_id',
        'anonymised_at',
        'created_at',
        'updated_at',
    ]);
});

it('makes only id, public_id, description, schema_version and created_at not nullable', function (): void {
    $notNullable = collect(activityLogColumns())
        ->reject(fn (array $column): bool => $column['nullable'])
        ->keys()
        ->all();

    expect($notNullable)->toEqualCanonicalizing(['id', 'public_id', 'description', 'schema_version', 'created_at']);
});

it('stores morph ids as strings of 64 characters for bigint, uuid and ulid keys', function (): void {
    $columns = activityLogColumns();

    foreach (['subject_id', 'causer_id'] as $columnName) {
        expect($columns[$columnName]['type_name'])->toBeIn(['varchar', 'character varying', 'text'])
            ->and($columns[$columnName]['type'])->toContain('64');
    }

    // http-basics accepts request ids of up to 128 characters.
    expect($columns['request_id']['type'])->toContain('128');
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite has no varchar length');

it('defaults schema_version to 1', function (): void {
    expect((string) activityLogColumns()['schema_version']['default'])->toContain('1');

    DB::table('activity_log')->insert([
        'public_id' => (string) Str::uuid7(),
        'description' => 'inserted without a version',
        'created_at' => now('UTC'),
    ]);

    expect(DB::table('activity_log')->value('schema_version'))->toEqual(1);
});

it('types organisation_id and public_id as uuid so they join a uuid organisation key', function (): void {
    $columns = activityLogColumns();

    expect($columns['organisation_id']['type_name'])->toBe('uuid')
        ->and($columns['public_id']['type_name'])->toBe('uuid');

    Schema::create('organisations', function (Blueprint $table): void {
        $table->uuid('id')->primary();
    });

    $organisationId = (string) Str::uuid7();
    DB::table('organisations')->insert(['id' => $organisationId]);
    DB::table('activity_log')->insert([
        'public_id' => (string) Str::uuid7(),
        'description' => 'joined',
        'organisation_id' => $organisationId,
        'created_at' => now('UTC'),
    ]);

    $joinedCount = DB::table('activity_log')
        ->join('organisations', 'organisations.id', '=', 'activity_log.organisation_id')
        ->count();

    expect($joinedCount)->toBe(1);

    Schema::drop('organisations');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'only PostgreSQL has a native uuid type');

it('has the design indexes', function (): void {
    $indexes = activityLogIndexes();

    $columnsByIndex = collect($indexes)
        ->reject(fn (array $index): bool => $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    expect($columnsByIndex)->toEqualCanonicalizing([
        ['public_id'],
        ['event'],
        ['subject_type', 'subject_id'],
        ['causer_type', 'causer_id'],
        ['organisation_id', 'created_at'],
        ['log_name', 'created_at'],
        ['request_id'],
        ['created_at'],
    ]);

    // The tenant page (organisation, newest first) and the two purge passes
    // (log name, created_at) read these; the names are stable for 1.0.
    expect($indexes)->toHaveKeys(['subject', 'causer', 'activity_log_organisation_created_at', 'activity_log_log_name_created_at'])
        ->and($indexes['activity_log_organisation_created_at']['columns'])->toBe(['organisation_id', 'created_at'])
        ->and($indexes['activity_log_log_name_created_at']['columns'])->toBe(['log_name', 'created_at']);

    $uniqueIndexes = collect($indexes)
        ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    expect($uniqueIndexes)->toBe([['public_id']]);
});

it('stores a request id of 128 characters', function (): void {
    DB::table('activity_log')->insert([
        'public_id' => (string) Str::uuid7(),
        'description' => 'long request id',
        'request_id' => str_repeat('r', 128),
        'created_at' => now('UTC'),
    ]);

    expect(DB::table('activity_log')->value('request_id'))->toBe(str_repeat('r', 128));
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite has no varchar length');

it('refuses a duplicate public_id', function (): void {
    $publicId = (string) Str::uuid7();
    DB::table('activity_log')->insert(['public_id' => $publicId, 'description' => 'first', 'created_at' => now('UTC')]);

    expect(fn () => DB::table('activity_log')->insert(['public_id' => $publicId, 'description' => 'second', 'created_at' => now('UTC')]))
        ->toThrow(QueryException::class);
});

it('has no foreign keys, so activity morphs never cascade', function (): void {
    expect(Schema::getForeignKeys('activity_log'))->toBe([]);
});

it('stores the timestamps as DATETIME, never a MySQL TIMESTAMP that ends in 2038', function (): void {
    $columns = activityLogColumns();

    foreach (['created_at', 'updated_at', 'anonymised_at'] as $columnName) {
        expect($columns[$columnName]['type_name'])->toBeIn(['datetime', 'timestamp']);

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            expect($columns[$columnName]['type_name'])->toBe('datetime');
        }
    }

    DB::table('activity_log')->insert([
        'public_id' => (string) Str::uuid7(),
        'description' => 'far future',
        'created_at' => '2040-06-01 12:00:00',
    ]);

    expect((string) DB::table('activity_log')->value('created_at'))->toStartWith('2040-06-01 12:00:00');
});

it('refuses an insert without created_at instead of taking the database session clock', function (): void {
    expect(fn () => DB::table('activity_log')->insert(['public_id' => (string) Str::uuid7(), 'description' => 'no timestamp']))
        ->toThrow(QueryException::class);
});

it('refuses to roll back over audit rows in production', function (): void {
    DB::table('activity_log')->insert(['public_id' => (string) Str::uuid7(), 'description' => 'evidence', 'created_at' => now('UTC')]);
    app()->detectEnvironment(fn (): string => 'production');

    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_24_000000_create_activity_log_table.php';

    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'activity_log has rows: rolling back its migration in production would delete audit evidence.')
        ->and(Schema::hasTable('activity_log'))->toBeTrue();

    DB::table('activity_log')->delete();
    $migration->down();

    expect(Schema::hasTable('activity_log'))->toBeFalse();

    app()->detectEnvironment(fn (): string => 'testing');
    $migration->up();
});

it('drops the table on rollback', function (): void {
    $this->artisan('migrate:rollback')->assertSuccessful();

    expect(Schema::hasTable('activity_log'))->toBeFalse();
});
