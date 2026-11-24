<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Qiick\Audit\Support\SecretAttributeFloor;
use Qiick\Audit\Tests\Support\Models\AuditedAccount;
use Qiick\Audit\Tests\Support\Models\SecretCastsRecord;

/*
 * A1 at the unit level: every path shape Spatie can log, and the failure
 * modes of the floor itself.
 */

function secretFloor(array $configured = []): SecretAttributeFloor
{
    return new SecretAttributeFloor($configured);
}

it('treats the built-in names as secret whatever their case', function (string $attributeName): void {
    expect(secretFloor()->isSecret(new AuditedAccount, $attributeName))->toBeTrue();
})->with([
    'password', 'PASSWORD', 'remember_token', 'Two_Factor_Secret', 'two_factor_recovery_codes',
    'key_hash', 'previous_key_hash', 'secret', 'token_hash', 'private_key',
]);

it('lists exactly the design floor', function (): void {
    expect(SecretAttributeFloor::builtInAttributes())->toBe([
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
        'key_hash', 'previous_key_hash', 'secret', 'token_hash', 'private_key',
    ]);
});

it('adds the configured names, case-insensitively', function (): void {
    expect(secretFloor(['Email'])->isSecret(new AuditedAccount, 'email'))->toBeTrue()
        ->and(secretFloor()->isSecret(new AuditedAccount, 'email'))->toBeFalse();
});

it('treats encrypted and hashed casts as secret', function (string $column): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, $column))->toBeTrue();
})->with(['encrypted_value', 'encrypted_array', 'encrypted_collection', 'encrypted_object', 'hashed_value', 'Hashed_Value', 'mixed_case_value', 'encrypted_array_object', 'encrypted_collection_class']);

it('logs ordinary casts and attributes', function (string $column): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, $column))->toBeFalse();
})->with(['plain_array', 'name', 'created_at']);

it('treats a JSON path as secret when its column or any key is', function (string $attributePath, bool $isSecret): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, $attributePath))->toBe($isSecret);
})->with([
    ['plain_array->secret', true],
    ['plain_array->nested->Private_Key', true],
    ['encrypted_array->harmless', true],
    ['plain_array->harmless', false],
]);

it('follows relations to the model that owns the attribute', function (string $attributePath, bool $isSecret): void {
    expect(secretFloor()->isSecret(new AuditedAccount, $attributePath))->toBe($isSecret);
})->with([
    ['parentAccount.api_token', true],
    ['parent_account.api_token', true],
    ['parentAccount.pin', true],
    ['parentAccount.parentAccount.password', true],
    ['parentAccount.name', false],
    ['owner.name', false],
]);

it('fails closed on a segment that is not a relation', function (string $attributePath): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, $attributePath))->toBeTrue();
})->with(['missing.name', 'notARelation.name']);

it('filters a list of paths and keeps their order', function (): void {
    expect(secretFloor()->withoutSecrets(new AuditedAccount, ['name', 'password', 'email', 'api_token', 'owner.name']))
        ->toBe(['name', 'email', 'owner.name']);
});

it('reads the config list', function (): void {
    expect(SecretAttributeFloor::fromConfig(['email'])->isSecret(new AuditedAccount, 'email'))->toBeTrue()
        ->and(SecretAttributeFloor::fromConfig([])->isSecret(new AuditedAccount, 'password'))->toBeTrue();
});

it('refuses a config that is not a list of names', function (mixed $configured): void {
    expect(fn () => SecretAttributeFloor::fromConfig($configured))
        ->toThrow(InvalidArgumentException::class, 'audit.sensitive_attributes must be a list of attribute names.');
})->with([
    'null' => [null],
    'string' => ['password'],
    'non-string item' => [[['password']]],
]);

it('refuses an attribute that is not named by a string', function (): void {
    expect(fn () => secretFloor()->withoutSecrets(new AuditedAccount, ['name', 42]))
        ->toThrow(InvalidArgumentException::class, 'An attribute to log must be named by a string.');
});

it('treats a name that resolves to a relation as secret', function (string $attributePath): void {
    expect(secretFloor()->isSecret(new AuditedAccount, $attributePath))->toBeTrue();
})->with(['owner', 'parentAccount', 'parent_account', 'parentAccount.owner']);

it('does not call a method without a Relation return type to find out', function (): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, 'notARelation'))->toBeFalse()
        ->and(secretFloor()->isSecret(new SecretCastsRecord, 'untypedMethod'))->toBeFalse()
        ->and(SecretCastsRecord::$untypedMethodCalls)->toBe(0);
});

it('drops values that hold models and floor keys at any depth', function (): void {
    $model = new AuditedAccount;
    $changes = [
        'name' => 'kept',
        'owner' => $model,
        'children' => new Collection([$model]),
        'wrapped' => ['deep' => [$model]],
        'count' => 3,
        'settings' => ['Secret' => 'x', 'keep' => ['password' => 'y', 'ok' => 1], 7 => 'numeric key'],
        'collection' => collect(['token_hash' => 'z', 'label' => 'l']),
        'value_object' => new ArrayObject(['private_key' => 'k', 'size' => 2]),
        'json_object' => new class implements JsonSerializable
        {
            public function jsonSerialize(): array
            {
                return ['remember_token' => 'r', 'theme' => 'dark'];
            }
        },
    ];

    expect(secretFloor()->withoutSecretValues($changes))->toBe([
        'name' => 'kept',
        'count' => 3,
        'settings' => ['keep' => ['ok' => 1], 7 => 'numeric key'],
        'collection' => ['label' => 'l'],
        'value_object' => ['size' => 2],
        'json_object' => ['theme' => 'dark'],
    ]);
});

it('matches floor names whatever their spelling (review B4)', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeTrue();
})->with([
    'privateKey', 'private-key', 'Private Key', 'private.key', 'twoFactorSecret', 'two-factor-secret',
    'remember-token', 'RememberToken', 'apiSecret', 'client_secret', 'webhookSecret', 'accessToken',
    'refresh_token', 'api_token', 'apiKey', 'api-key', 'X-Api-Key', 'twoFactorRecoveryCodes', 'recovery_code',
    'userPassword', 'password_confirmation', 'keyHash', 'previous-key-hash', 'tokenHash',
]);

it('keeps keys that only look like the floor in part', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeFalse();
})->with(['name', 'key', 'hash', 'theme', 'api_version', 'factor', 'private', 'email']);

it('matches configured names whatever their spelling, exactly', function (): void {
    expect(secretFloor(['signing_salt'])->namesAFloorKey(['signingSalt']))->toBeTrue()
        ->and(secretFloor(['signing_salt'])->namesAFloorKey(['signing_salt_label']))->toBeFalse();
});

it('drops nested keys whatever their spelling (review B4)', function (): void {
    expect(secretFloor()->withoutSecretValues(['config' => [
        'privateKey' => 'PK', 'twoFactorSecret' => 'TFS', 'remember-token' => 'RT', 'PASSWORD' => 'UP',
        'nested' => [['password' => 'NP', 'apiSecret' => 'AS', 'label' => 'kept']],
    ]]))->toBe(['config' => ['nested' => [['label' => 'kept']]]]);
});

it('never calls a method that is not a typed relation to walk a path (review B3)', function (string $attributePath): void {
    $record = new SecretCastsRecord;

    expect(secretFloor()->isSecret($record, $attributePath))->toBeTrue()
        ->and(SecretCastsRecord::$untypedMethodCalls)->toBe(0);
})->with(['untypedMethod.name', 'delete.name', 'touch.name', 'replicate.name', 'maybeRelation.name']);

it('treats a name as secret when it holds one secret part', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeTrue();
})->with(['userPassword', 'clientSecret', 'accessToken', 'privateKeyPem', 'xApiKey', 'twoFactorEnabled', 'recoveryCodes', 'rememberMe', "tab\tSecret", "line\nToken"]);

it('removes each separator before matching', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeTrue();
})->with(['api_key', 'api-key', 'api.key', 'api key', "api\tkey", "api\nkey"]);
