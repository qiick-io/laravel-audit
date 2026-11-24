<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shared activity_log table (design §3, final for 1.0).
 *
 * Spatie's standard name and columns, so Spatie keeps working unchanged, with:
 * - string(64) morph ids, so bigint, UUID and ULID keys all fit;
 * - public_id (UUID v7), the only id that leaves the database;
 * - organisation_id as uuid, joinable with a uuid organisation key on PostgreSQL;
 * - schema_version (1 = current row format, 0 = legacy externa row);
 * - request_id for correlation and anonymised_at for provable erasure.
 *
 * No foreign keys: activity morphs never cascade, and audit rows are
 * anonymised, never deleted or hidden. Editable here until 1.0.0.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->string('event')->nullable()->index();
            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->index(['subject_type', 'subject_id'], 'subject');
            $table->string('causer_type')->nullable();
            $table->string('causer_id', 64)->nullable();
            $table->index(['causer_type', 'causer_id'], 'causer');
            $table->uuid('organisation_id')->nullable()->index();
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->string('request_id', 64)->nullable()->index();
            $table->timestamp('anonymised_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
