<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Qiick\Audit\Audit;
use Qiick\Audit\AuditContext;
use Qiick\Audit\Contracts\AuditActor;
use Qiick\Audit\Contracts\AuditAnonymiser;
use Qiick\Audit\Contracts\ErasureSubject;
use Qiick\Audit\Contracts\MorphRef;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuditedIntegerRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * The package end to end, as a product uses it: a model with the trait
 * changed through an API key request, then exported and erased.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    CreatesKeyedRecordTables::create();
});

it('attributes a trait-logged change made through an API key request', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'service principal']);
    $organisationId = (string) Str::uuid7();

    Route::post('/projects', function () use ($owner, $organisationId) {
        AuditContext::bindCredential(new AuditActor($owner, 'api_key', '0193a3b0-7c1d-7000-8000-000000000001', 'qk_ab12', 'usr_creator'));
        Context::add('request_id', 'req-1');

        activity()->tap(Audit::inOrganisation($organisationId))->log('project exported');
        AuditedIntegerRecord::query()->create(['name' => 'Apollo']);

        return response()->noContent();
    });

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeader('User-Agent', 'qiick-cli/2.0')
        ->post('/projects')
        ->assertNoContent();

    $rows = Activity::query()->orderBy('id')->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->causer?->is($owner))->toBeTrue()
            ->and($row->request_id)->toBe('req-1')
            ->and($row->schema_version)->toBe(1)
            ->and($row->getProperty('api_key_id'))->toBe('0193a3b0-7c1d-7000-8000-000000000001')
            ->and($row->getProperty('ip'))->toBe('198.51.100.7')
            ->and($row->getProperty('user_agent'))->toBe('qiick-cli/2.0');
    }

    expect($rows[0]->organisation_id)->toBe($organisationId)
        ->and($rows[1]->event)->toBe('created')
        ->and($rows[1]->organisation_id)->toBeNull();
});

it('stores CR, LF and HTML in the description and properties as they are (A10)', function (): void {
    $payload = "line one\r\nline two <script>alert(1)</script>";

    activity()->withProperties(['comment' => $payload])->log($payload);

    $row = DB::table('activity_log')->sole();

    expect($row->description)->toBe($payload)
        ->and(json_decode((string) $row->properties, true)['comment'])->toBe($payload);
});

it('exports by public_id with RFC 3339 UTC timestamps and never the bigint id (A13)', function (): void {
    $this->travelTo(now('UTC')->setDate(2026, 11, 24)->setTime(10, 30, 15));

    activity()->log('exported');

    $export = Activity::query()->sole()->toArray();

    expect($export)->not->toHaveKey('id')
        ->and($export['public_id'])->toBeString()
        ->and($export['created_at'])->toBe('2026-11-24T10:30:15.000000Z')
        ->and(Activity::query()->sole()->toJson())->not->toContain('"id":')
        ->and(Activity::query()->sole()->makeVisible('id')->toArray())->not->toHaveKey('id')
        ->and(Activity::query()->sole()->makeHidden('description')->toArray())->not->toHaveKeys(['id', 'description']);
});

it('erases a person after logging, keeping the evidence (A7, A8)', function (): void {
    $person = IntegerKeyedRecord::query()->create(['name' => 'Ada']);
    activity()->causedBy($person)->withProperties(['email' => 'ada@example.com'])->log('Ada signed in');

    $rowsBefore = DB::table('activity_log')->count();

    $report = DB::transaction(fn () => app(AuditAnonymiser::class)->anonymise(
        new ErasureSubject([MorphRef::of($person)], 'erased-1', ['Ada']),
    ));

    $row = Activity::query()->sole();

    expect($report->rows)->toBe(1)
        ->and(DB::table('activity_log')->count())->toBe($rowsBefore)
        ->and($row->description)->toBe('erased-1 signed in')
        ->and($row->getProperty('email'))->toBe('erased-1')
        ->and($row->anonymised_at)->not->toBeNull();
});
