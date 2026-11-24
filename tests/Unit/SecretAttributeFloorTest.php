<?php

declare(strict_types=1);

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
})->with(['encrypted_value', 'encrypted_array', 'encrypted_collection', 'encrypted_object', 'hashed_value', 'Hashed_Value', 'mixed_case_secret', 'encrypted_array_object', 'encrypted_collection_class']);

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
