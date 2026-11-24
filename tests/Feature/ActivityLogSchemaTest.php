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

it('makes only id, public_id, description and schema_version not nullable', function (): void {
    $notNullable = collect(activityLogColumns())
        ->reject(fn (array $column): bool => $column['nullable'])
        ->keys()
        ->all();

    expect($notNullable)->toEqualCanonicalizing(['id', 'public_id', 'description', 'schema_version']);
});

it('stores morph ids as strings of 64 characters for bigint, uuid and ulid keys', function (): void {
    $columns = activityLogColumns();

    foreach (['subject_id', 'causer_id', 'request_id'] as $columnName) {
        expect($columns[$columnName]['type_name'])->toBeIn(['varchar', 'character varying', 'text'])
            ->and($columns[$columnName]['type'])->toContain('64');
    }
})->skip(fn (): bool => DB::getDriverName() === 'sqlite', 'SQLite has no varchar length');

it('defaults schema_version to 1', function (): void {
    expect((string) activityLogColumns()['schema_version']['default'])->toContain('1');

    DB::table('activity_log')->insert([
        'public_id' => (string) Str::uuid7(),
        'description' => 'inserted without a version',
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
        ['log_name'],
        ['event'],
        ['subject_type', 'subject_id'],
        ['causer_type', 'causer_id'],
        ['organisation_id'],
        ['request_id'],
        ['created_at'],
    ]);

    expect($indexes)->toHaveKeys(['subject', 'causer']);

    $uniqueIndexes = collect($indexes)
        ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    expect($uniqueIndexes)->toBe([['public_id']]);
});

it('refuses a duplicate public_id', function (): void {
    $publicId = (string) Str::uuid7();
    DB::table('activity_log')->insert(['public_id' => $publicId, 'description' => 'first']);

    expect(fn () => DB::table('activity_log')->insert(['public_id' => $publicId, 'description' => 'second']))
        ->toThrow(QueryException::class);
});

it('has no foreign keys, so activity morphs never cascade', function (): void {
    expect(Schema::getForeignKeys('activity_log'))->toBe([]);
});

it('drops the table on rollback', function (): void {
    $this->artisan('migrate:rollback')->assertSuccessful();

    expect(Schema::hasTable('activity_log'))->toBeFalse();
});
