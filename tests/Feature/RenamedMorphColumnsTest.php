<?php

declare(strict_types=1);

namespace Qiick\Audit\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuditedIntegerRecord;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;
use Qiick\Audit\Tests\Support\Models\UuidKeyedRecord;
use Qiick\Audit\Tests\Support\PackageTestCase;
use Spatie\Activitylog\Support\ActivityLogger;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Design §6.3: with audit.columns pointing at subject_ref / causer_ref (the
 * externa 1.x shape), the relations, the scopes, the trait relation and
 * Spatie's logger all read and write those columns, never subject_id /
 * causer_id. Spatie's ActivityLogger reads getAttribute('causer_id') to decide
 * whether a causer is set (Support/ActivityLogger.php:229); the package logger
 * reads the configured column.
 */
final class RenamedMorphColumnsTest extends PackageTestCase
{
    use DatabaseMigrations;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('audit.columns', [
            'subject_id' => 'subject_ref',
            'causer_id' => 'causer_ref',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        CreatesKeyedRecordTables::create();

        Schema::table('activity_log', function (Blueprint $table): void {
            $table->string('subject_ref', 64)->nullable();
            $table->string('causer_ref', 64)->nullable();
        });
    }

    public function test_it_writes_and_reads_the_configured_columns(): void
    {
        $subject = IntegerKeyedRecord::query()->create(['name' => 'subject']);
        $causer = UuidKeyedRecord::query()->create(['name' => 'causer']);

        activity()->performedOn($subject)->causedBy($causer)->log('renamed');

        $row = DB::table('activity_log')->sole();

        $this->assertSame((string) $subject->getKey(), $row->subject_ref);
        $this->assertSame($causer->getKey(), $row->causer_ref);
        $this->assertNull($row->subject_id);
        $this->assertNull($row->causer_id);

        $activity = Activity::query()->sole();

        $this->assertTrue($activity->subject?->is($subject));
        $this->assertTrue($activity->causer?->is($causer));
        $this->assertSame(1, Activity::query()->forSubject($subject)->count());
        $this->assertSame(1, Activity::query()->causedBy($causer)->count());
    }

    public function test_the_trait_relation_reads_the_configured_column(): void
    {
        $record = AuditedIntegerRecord::query()->create(['name' => 'audited']);

        $this->assertSame(['created'], $record->activitiesAsSubject()->pluck('event')->all());
    }

    public function test_a_causer_set_on_the_activity_is_not_replaced_by_the_default_causer(): void
    {
        $presetCauser = IntegerKeyedRecord::query()->create(['name' => 'preset']);
        app(CauserResolver::class)->setCauser(IntegerKeyedRecord::query()->create(['name' => 'default causer']));

        ActivityLogger::macro('presetCauserForTest', function ($causer) {
            /** @var ActivityLogger $this */
            $this->getActivity()->causer()->associate($causer);

            return $this;
        });

        app(ActivityLogger::class)->presetCauserForTest($presetCauser)->log('preset causer');

        $this->assertSame((string) $presetCauser->getKey(), DB::table('activity_log')->value('causer_ref'));
        $this->assertSame($presetCauser->getMorphClass(), DB::table('activity_log')->value('causer_type'));
    }

    public function test_the_default_causer_is_used_when_none_is_set(): void
    {
        $defaultCauser = IntegerKeyedRecord::query()->create(['name' => 'default causer']);
        app(CauserResolver::class)->setCauser($defaultCauser);

        activity()->log('default causer');

        $this->assertSame((string) $defaultCauser->getKey(), DB::table('activity_log')->value('causer_ref'));
    }

    public function test_an_anonymous_causer_stays_anonymous(): void
    {
        app(CauserResolver::class)->setCauser(IntegerKeyedRecord::query()->create(['name' => 'default causer']));

        activity()->causedByAnonymous()->log('anonymous');

        $this->assertNull(DB::table('activity_log')->value('causer_ref'));
    }

    public function test_it_refuses_an_activity_model_that_does_not_extend_the_package_model(): void
    {
        config()->set('activitylog.activity_model', \Spatie\Activitylog\Models\Activity::class);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('activitylog.activity_model must extend '.Activity::class.'.');

        activity()->log('spatie model');
    }
}
