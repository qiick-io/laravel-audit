<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the tables of the bigint, UUID and ULID test models. Used with
 * DatabaseMigrations, which drops every table after each test.
 */
final class CreatesKeyedRecordTables
{
    public static function create(): void
    {
        Schema::create('integer_keyed_records', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_keyed_records', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('ulid_keyed_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
