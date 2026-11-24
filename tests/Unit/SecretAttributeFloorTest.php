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

function secretFloor(array $configured = [], array $configuredParts = []): SecretAttributeFloor
{
    return new SecretAttributeFloor($configured, $configuredParts);
}

it('treats the built-in names as secret whatever their case', function (string $attributeName): void {
    expect(secretFloor()->isSecret(new AuditedAccount, $attributeName))->toBeTrue();
})->with([
    'password', 'PASSWORD', 'remember_token', 'Two_Factor_Secret', 'two_factor_recovery_codes',
    'key_hash', 'previous_key_hash', 'secret', 'token_hash', 'private_key',
]);

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

it('treats a name that resolves to a relation as secret', function (string $attributePath): void {
    expect(secretFloor()->isSecret(new AuditedAccount, $attributePath))->toBeTrue();
})->with(['owner', 'parentAccount', 'parent_account', 'parentAccount.owner']);

it('does not call a method without a Relation return type to find out', function (): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, 'notARelation'))->toBeFalse()
        ->and(secretFloor()->isSecret(new SecretCastsRecord, 'untypedMethod'))->toBeFalse()
        ->and(SecretCastsRecord::$untypedMethodCalls)->toBe(0);
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

it('lists exactly the built-in names and parts', function (): void {
    expect(SecretAttributeFloor::builtInAttributes())->toBe([
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'key_hash',
        'previous_key_hash', 'secret', 'token_hash', 'private_key', 'pwd', 'pass', 'otp', 'pin', 'cvv',
        'iban', 'signing_key', 'dsn',
    ])->and(SecretAttributeFloor::builtInSecretParts())->toBe([
        'password', 'secret', 'token', 'privatekey', 'apikey', 'twofactor', 'recoverycode', 'remember',
        'passphrase', 'passwd', 'credential', 'authorization', 'cookie', 'session', 'segreto',
    ]);
});

it('matches the abbreviations and other names of the review', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeTrue();
})->with(['pwd', 'PASS', 'otp', 'pin', 'cvv', 'iban', 'signingKey', 'DSN', 'passphrase', 'myPasswd', 'credentials', 'Authorization', 'cookie', 'session_id', 'parola_segreto']);

it('keeps words that only contain a short abbreviation', function (string $key): void {
    expect(secretFloor()->namesAFloorKey([$key]))->toBeFalse();
})->with(['passport', 'bypass', 'shipping', 'mapping', 'spinner', 'topology']);

it('adds configured parts', function (): void {
    expect(secretFloor([], ['chiave'])->namesAFloorKey(['chiave_privata']))->toBeTrue()
        ->and(secretFloor([], ['Mot-De'])->namesAFloorKey(['motDePasse']))->toBeTrue()
        ->and(secretFloor()->namesAFloorKey(['chiave_privata']))->toBeFalse();
});

it('does not treat moments, flags and counts as secrets (review B5)', function (string $attributePath, bool $isSecret): void {
    expect(secretFloor()->isSecret(new SecretCastsRecord, $attributePath))->toBe($isSecret);
})->with([
    'name ending in _at' => ['two_factor_confirmed_at', false],
    'name ending in _on' => ['password_changed_on', false],
    'camelCase At' => ['tokenRotatedAt', false],
    'datetime cast' => ['session_started', false],
    'date cast' => ['password_reset_day', false],
    'boolean cast' => ['two_factor_enabled', false],
    'integer cast' => ['token_count', false],
    'int cast' => ['secret_version', false],
    'bool cast' => ['secret_shared', false],
    'timestamp cast' => ['token_issued', false],
    'immutable date cast' => ['secret_day', false],
    'immutable datetime cast' => ['session_closed', false],
    'no cast' => ['token_count_raw', true],
    'string cast' => ['secret_label', true],
    'exact name wins over a cast' => ['pin', true],
]);

it('keeps the paths Spatie may resolve, in order', function (): void {
    expect(secretFloor()->loggablePaths(new AuditedAccount, ['name', 'password', 'owner', 'parentAccount.name', 'missing.name', 'settings->secret']))
        ->toBe(['name', 'password', 'parentAccount.name', 'settings->secret']);
});

it('refuses a path that is not named by a string', function (): void {
    expect(fn () => secretFloor()->loggablePaths(new AuditedAccount, ['name', 42]))
        ->toThrow(InvalidArgumentException::class, 'An attribute to log must be named by a string.');
});

it('drops values that hold models', function (): void {
    $model = new AuditedAccount;

    expect(secretFloor()->withoutModels([
        'name' => 'kept',
        'owner' => $model,
        'children' => new Collection([$model]),
        'wrapped' => ['deep' => [$model]],
        'count' => 3,
    ]))->toBe(['name' => 'kept', 'count' => 3]);
});

it('redacts secret paths and nested keys of every section, with and without a subject', function (): void {
    $redacted = SecretAttributeFloor::REDACTED;
    $changes = [
        'attributes' => [
            'name' => 'kept',
            'api_token' => 'tok',
            'pin' => 'hash',
            'settings' => ['Secret' => 'x', 'keep' => ['password' => 'y', 'ok' => 1], 7 => 'numeric key', 'privateKey' => 'k'],
            'collection' => collect(['token_hash' => 'z', 'label' => 'l']),
            'value_object' => new ArrayObject(['private_key' => 'k', 'size' => 2]),
            'json_object' => new class implements JsonSerializable
            {
                public function jsonSerialize(): array
                {
                    return ['remember_token' => 'r', 'theme' => 'dark'];
                }
            },
            'owner.password' => 'p',
        ],
        'old' => ['name' => 'before', 'api_token' => 'old'],
        'note' => ['apiSecret' => 's', 'text' => 't'],
    ];

    $expected = [
        'attributes' => [
            'name' => 'kept',
            'api_token' => $redacted,
            'pin' => $redacted,
            'settings' => ['Secret' => $redacted, 'keep' => ['password' => $redacted, 'ok' => 1], 7 => 'numeric key', 'privateKey' => $redacted],
            'collection' => ['token_hash' => $redacted, 'label' => 'l'],
            'value_object' => ['private_key' => $redacted, 'size' => 2],
            'json_object' => ['remember_token' => $redacted, 'theme' => 'dark'],
            'owner.password' => $redacted,
        ],
        'old' => ['name' => 'before', 'api_token' => $redacted],
        'note' => ['apiSecret' => $redacted, 'text' => 't'],
    ];

    expect(secretFloor()->redactChanges($changes, new AuditedAccount))->toBe($expected)
        ->and(secretFloor()->redactChanges($changes, null))->toBe($expected);
});

it('redacts a JSON path by name without a subject', function (): void {
    expect(secretFloor()->redactChanges(['attributes' => ['settings->secret' => 'x', 'settings->pin' => '1', 'settings->theme' => 'dark']], null))
        ->toBe(['attributes' => ['settings->secret' => SecretAttributeFloor::REDACTED, 'settings->pin' => SecretAttributeFloor::REDACTED, 'settings->theme' => 'dark']]);
});

it('redacts a non-array section through its nested keys', function (): void {
    expect(secretFloor()->redactChanges(['summary' => 'text', 'blob' => collect(['password' => 'p'])], null))
        ->toBe(['summary' => 'text', 'blob' => ['password' => SecretAttributeFloor::REDACTED]]);
});

it('refuses a sensitive_parts config that is not a list of names', function (mixed $configured): void {
    expect(fn () => SecretAttributeFloor::fromConfig([], $configured))
        ->toThrow(InvalidArgumentException::class, 'audit.sensitive_parts must be a list of name parts.');
})->with(['null' => [null], 'string' => ['segreto'], 'non-string item' => [[1]]]);

it('reads both config lists', function (): void {
    config()->set('audit.sensitive_attributes', ['email']);
    config()->set('audit.sensitive_parts', ['chiave']);

    expect(SecretAttributeFloor::configured()->namesAFloorKey(['Email']))->toBeTrue()
        ->and(SecretAttributeFloor::configured()->namesAFloorKey(['la_chiave']))->toBeTrue();
});

it('reads list sections by value, never as attribute names', function (): void {
    expect(secretFloor()->redactChanges(['attributes' => ['password', ['token' => 't']]], new AuditedAccount))
        ->toBe(['attributes' => ['password', ['token' => SecretAttributeFloor::REDACTED]]]);
});
