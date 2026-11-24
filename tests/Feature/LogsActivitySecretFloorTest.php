<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Schema;
use Qiick\Audit\Models\Activity;
use Qiick\Audit\Support\SecretAttributeFloor;
use Qiick\Audit\Tests\Support\CreatesKeyedRecordTables;
use Qiick\Audit\Tests\Support\Models\AuditedAccount;
use Qiick\Audit\Tests\Support\Models\AuditedAccountLoggingPaths;
use Qiick\Audit\Tests\Support\Models\AuditedAccountWithJson;
use Qiick\Audit\Tests\Support\Models\AuditedAccountWithOptions;
use Qiick\Audit\Tests\Support\Models\AuditedAccountWithOwner;
use Qiick\Audit\Tests\Support\Models\IntegerKeyedRecord;

/*
 * A1 (design §4, §7): the trait's secret floor. Every encrypted* or hashed
 * cast and every audit.sensitive_attributes name stays out of
 * attribute_changes, on create, update and delete, whatever the model's
 * options say.
 */

uses(DatabaseMigrations::class);

beforeEach(function (): void {
    // updated_at is logged too: a frozen clock keeps it out of the update diff.
    $this->freezeSecond();

    CreatesKeyedRecordTables::create();

    Schema::create('audited_accounts', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('owner_id')->nullable();
        $table->foreignId('parent_account_id')->nullable();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
        $table->string('password')->nullable();
        $table->string('Remember_Token')->nullable();
        $table->string('secret')->nullable();
        $table->string('private_key')->nullable();
        $table->text('api_token')->nullable();
        $table->text('recovery_settings')->nullable();
        $table->string('pin')->nullable();
        $table->text('settings')->nullable();
        $table->text('options')->nullable();
        $table->text('flags')->nullable();
        $table->text('memo')->nullable();
        $table->dateTime('two_factor_confirmed_at')->nullable();
        $table->dateTime('password_changed_at')->nullable();
        $table->integer('token_estimate')->nullable();
        $table->integer('session_lifetime')->nullable();
        $table->timestamps();
    });
});

/**
 * @return array<string, mixed>
 */
function loggedAttributeChanges(string $event): array
{
    $activity = Activity::query()->where('event', $event)->sole();

    return $activity->attribute_changes?->toArray() ?? [];
}

const SECRET_VALUES = ['hunter2', 'remember-me', 'shared-secret', '-----BEGIN KEY-----', 'tok_live', '1234'];

function expectNoSecretValueIn(array $changes): void
{
    $json = json_encode($changes, JSON_THROW_ON_ERROR);

    foreach (SECRET_VALUES as $secretValue) {
        expect($json)->not->toContain($secretValue);
    }
}

function createAuditedAccount(): AuditedAccount
{
    return AuditedAccount::query()->create([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'hunter2',
        'Remember_Token' => 'remember-me',
        'secret' => 'shared-secret',
        'private_key' => '-----BEGIN KEY-----',
        'api_token' => 'tok_live',
        'recovery_settings' => ['codes' => ['1234']],
        'pin' => '1234',
        'memo' => 'tok_live',
    ]);
}

it('logs the ordinary attributes on create', function (): void {
    createAuditedAccount();

    $changes = loggedAttributeChanges('created');

    expect($changes['attributes'])->toMatchArray(['name' => 'Ada', 'email' => 'ada@example.com']);
});

it('redacts encrypted, hashed and floor attributes on create (A1)', function (): void {
    createAuditedAccount();

    $changes = loggedAttributeChanges('created');

    expect($changes['attributes'])->toMatchArray(array_fill_keys(
        ['password', 'Remember_Token', 'secret', 'private_key', 'api_token', 'recovery_settings', 'pin'],
        SecretAttributeFloor::REDACTED,
    ));
    expectNoSecretValueIn($changes);
});

it('records that a secret changed, never its old or new value (A1, review B5)', function (): void {
    $account = createAuditedAccount();

    $account->update([
        'name' => 'Ada L.',
        'password' => 'hunter2-new',
        'api_token' => 'tok_live_2',
        'pin' => '5678',
    ]);

    $changes = loggedAttributeChanges('updated');

    $redacted = SecretAttributeFloor::REDACTED;

    expect($changes['attributes'])->toEqual(['name' => 'Ada L.', 'password' => $redacted, 'api_token' => $redacted, 'pin' => $redacted])
        ->and($changes['old'])->toEqual(['name' => 'Ada', 'password' => $redacted, 'api_token' => $redacted, 'pin' => $redacted]);
    expectNoSecretValueIn($changes);
});

it('logs a password change on its own, without the value (review B5)', function (): void {
    $account = createAuditedAccount();

    $account->update(['password' => 'hunter3']);

    expect(loggedAttributeChanges('updated'))->toEqual([
        'attributes' => ['password' => SecretAttributeFloor::REDACTED],
        'old' => ['password' => SecretAttributeFloor::REDACTED],
    ]);
});

it('logs a two-factor confirmation with its time (review B5)', function (): void {
    $account = createAuditedAccount();
    $this->travelTo(now('UTC')->setDate(2026, 11, 24)->setTime(9, 30));

    $account->update(['two_factor_confirmed_at' => now('UTC'), 'password_changed_at' => now('UTC'), 'token_estimate' => 5, 'session_lifetime' => 120]);

    $changes = loggedAttributeChanges('updated');

    expect($changes['attributes'])->toMatchArray([
        'two_factor_confirmed_at' => '2026-11-24T09:30:00.000000Z',
        'password_changed_at' => '2026-11-24 09:30:00',
        'token_estimate' => 5,
        // No cast says it is a number, so the secret part `session` redacts it (fail closed).
        'session_lifetime' => SecretAttributeFloor::REDACTED,
    ])->and($changes['old'])->toMatchArray(['two_factor_confirmed_at' => null, 'token_estimate' => null]);
});

it('keeps them out of old on delete (A1)', function (): void {
    createAuditedAccount()->delete();

    $changes = loggedAttributeChanges('deleted');

    expect($changes['old'])->toHaveKey('name');
    expectNoSecretValueIn($changes);
});

it('uses the design defaults: default log, only dirty, no empty changes', function (): void {
    $account = createAuditedAccount();
    $account->update(['name' => 'Ada']);

    expect(Activity::query()->sole()->log_name)->toEqual('default');

    $account->update(['name' => 'Ada L.', 'email' => 'ada@example.com']);

    expect(loggedAttributeChanges('updated')['attributes'])->toEqual(['name' => 'Ada L.']);
});

it('redacts secrets a model logs explicitly, by path or through a relation (A1)', function (): void {
    $parent = createAuditedAccount();

    AuditedAccountWithOptions::query()->create([
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'hunter2',
        'secret' => 'shared-secret',
        'api_token' => 'tok_live',
        'settings' => ['secret' => 'remember-me'],
        'parent_account_id' => $parent->getKey(),
    ]);

    $activity = Activity::query()->where('log_name', 'accounts')->sole();
    $changes = $activity->attribute_changes?->toArray() ?? [];

    $redacted = SecretAttributeFloor::REDACTED;

    expect($changes['attributes'])->toEqual([
        'name' => 'Grace',
        'password' => $redacted,
        'secret' => $redacted,
        'api_token' => $redacted,
        'settings' => ['secret' => $redacted],
        'parentAccount.password' => $redacted,
    ]);
    expectNoSecretValueIn($changes);
});

it('applies config additions to the floor', function (): void {
    config()->set('audit.sensitive_attributes', [...config('audit.sensitive_attributes'), 'email']);
    config()->set('audit.sensitive_parts', ['nome']);

    AuditedAccount::query()->create(['name' => 'Ada', 'email' => 'ada@example.com', 'settings' => '{"nome_utente":"ada"}']);

    $changes = loggedAttributeChanges('created');

    expect($changes['attributes']['email'])->toEqual(SecretAttributeFloor::REDACTED)
        ->and($changes['attributes']['name'])->toEqual('Ada');
});

it('keeps the built-in floor when the config list is emptied (A1)', function (): void {
    config()->set('audit.sensitive_attributes', []);

    createAuditedAccount();

    $changes = loggedAttributeChanges('created');

    expect($changes['attributes'])->toMatchArray(array_fill_keys(['password', 'Remember_Token', 'secret', 'private_key'], SecretAttributeFloor::REDACTED));
    expectNoSecretValueIn($changes);
});

it('logs an ordinary relation attribute', function (): void {
    $owner = IntegerKeyedRecord::query()->create(['name' => 'Owner']);

    AuditedAccountWithOwner::query()->create(['name' => 'Linked', 'owner_id' => $owner->getKey()]);

    expect(loggedAttributeChanges('created')['attributes'])->toEqual(['name' => 'Linked', 'owner.name' => 'Owner']);
});

it('never logs a related model named as an attribute, nor its decrypted secrets (A1, review B1)', function (string $attributePath): void {
    $owner = createAuditedAccount();

    AuditedAccountLoggingPaths::$paths = ['name', $attributePath];
    $child = AuditedAccountLoggingPaths::query()->create(['name' => 'Child', 'parent_account_id' => $owner->getKey()]);

    $changes = Activity::query()->forSubject($child)->sole()->attribute_changes?->toArray() ?? [];

    expect($changes['attributes'])->toEqual(['name' => 'Child']);
    expectNoSecretValueIn($changes);
})->with(['parentAccount', 'parent_account', 'parentAccount.parentAccount']);

it('redacts secret keys nested at any depth in a JSON column (A1, review B2)', function (): void {
    $account = AuditedAccountWithJson::query()->create([
        'name' => 'Json',
        'settings' => ['theme' => 'dark', 'Password' => 'hunter2', 'nested' => ['secret' => 'shared-secret', 'list' => [['private_key' => '-----BEGIN KEY-----', 'label' => 'kept']]]],
    ]);

    $created = loggedAttributeChanges('created');

    $redacted = SecretAttributeFloor::REDACTED;
    $createdSettings = ['theme' => 'dark', 'Password' => $redacted, 'nested' => ['secret' => $redacted, 'list' => [['private_key' => $redacted, 'label' => 'kept']]]];

    expect($created['attributes']['settings'])->toEqual($createdSettings);
    expectNoSecretValueIn($created);

    $account->update(['settings' => ['theme' => 'light', 'token_hash' => 'remember-me']]);
    $updated = loggedAttributeChanges('updated');

    expect($updated['attributes']['settings'])->toEqual(['theme' => 'light', 'token_hash' => $redacted])
        ->and($updated['old']['settings'])->toEqual($createdSettings);
    expectNoSecretValueIn($updated);

    $account->delete();

    expectNoSecretValueIn(loggedAttributeChanges('deleted'));
});

it('redacts secret keys in collection and array object casts (A1)', function (): void {
    AuditedAccountWithJson::query()->create([
        'name' => 'Objects',
        'options' => ['secret' => 'shared-secret', 'mode' => 'fast'],
        'flags' => ['two_factor_secret' => 'remember-me', 'beta' => true],
    ]);

    $changes = loggedAttributeChanges('created');

    expect($changes['attributes']['options'])->toEqual(['secret' => SecretAttributeFloor::REDACTED, 'mode' => 'fast'])
        ->and($changes['attributes']['flags'])->toEqual(['two_factor_secret' => SecretAttributeFloor::REDACTED, 'beta' => true]);
    expectNoSecretValueIn($changes);
});

it('leaves description placeholders for secrets and related models unresolved (A1)', function (): void {
    $account = createAuditedAccount();

    activity()->performedOn($account)->causedBy($account)
        ->log('renamed :subject.name with :subject.api_token, :causer.PASSWORD, :subject.pin, :subject.memo, :subject.parentAccount, :subject.recovery_settings and :subject');

    $description = Activity::query()->latest('id')->firstOrFail()->description;

    expect($description)->toEqual('renamed Ada with :subject.api_token, :causer.PASSWORD, :subject.pin, :subject.memo, :subject.parentAccount, :subject.recovery_settings and :subject');
});

it('still resolves ordinary placeholders, properties included', function (): void {
    $account = createAuditedAccount();

    activity()->performedOn($account)->causedBy($account)->withProperties(['reason' => 'audit', 'secret' => 'x', 'count' => 3])
        ->log(':subject.email changed by :causer.name for :properties.reason x:properties.count (:properties.secret, :event.x, :description)');

    expect(Activity::query()->latest('id')->firstOrFail()->description)
        ->toEqual('ada@example.com changed by Ada for audit x3 (:properties.secret, :event.x, :description)');
});
