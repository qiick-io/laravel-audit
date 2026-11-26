<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Qiick\Audit\Erasure\AuditEraser;
use Qiick\Audit\Tests\Support\Models\AuthenticatableRecord;
use Qiick\Standards\Erasure\Support\ErasureFixture;
use Qiick\Standards\Erasure\Support\ErasurePerson;

/**
 * The audit table is the only store. The package writes no cache, file or
 * secondary connection containing a person's data. User references include
 * the current alias and historical class name, with the user already deleted.
 */
return new class implements ErasureFixture
{
    public function packageName(): string
    {
        return 'qiick/laravel-audit';
    }

    public function eraserClass(): string
    {
        return AuditEraser::class;
    }

    public function connectionName(): ?string
    {
        return null;
    }

    public function tables(): array
    {
        return [
            'activity_log' => [
                'id' => 'other',
                'public_id' => 'other',
                'log_name' => 'other',
                'description' => 'personal',
                'event' => 'other',
                'subject_type' => 'other',
                'subject_id' => 'retained',
                'causer_type' => 'other',
                'causer_id' => 'retained',
                'organisation_id' => 'other',
                'attribute_changes' => 'personal',
                'properties' => 'personal',
                'schema_version' => 'other',
                'request_id' => 'other',
                'anonymised_at' => 'other',
                'created_at' => 'other',
                'updated_at' => 'other',
            ],
        ];
    }

    public function retainedCleartextColumns(): array
    {
        return [
            'activity_log.subject_id' => 'legal_obligation',
            'activity_log.causer_id' => 'legal_obligation',
        ];
    }

    public function diskPaths(ErasurePerson $person): array
    {
        return [];
    }

    public function additionalConnections(): array
    {
        return [];
    }

    public function makePerson(): ErasurePerson
    {
        config()->set('auth.providers.users.model', AuthenticatableRecord::class);

        return ErasurePerson::fresh((string) Str::ulid());
    }

    public function seed(ErasurePerson $person, ?string $organisationId): void
    {
        [$email, $username, $name] = $person->identifiers;
        [$ipAddress, $userAgent] = $person->otherPersonalValues;
        [$principalId, $credentialId] = $person->publicIds;
        $attributes = [
            'organisation_id' => $organisationId,
            'created_at' => '2026-11-25 12:00:00',
            'log_name' => 'auth',
            'event' => 'updated',
        ];

        DB::table('activity_log')->insert([
            [
                ...$attributes,
                'public_id' => (string) Str::uuid7(),
                'subject_type' => AuthenticatableRecord::class,
                'subject_id' => $person->userId,
                'causer_type' => 'principal',
                'causer_id' => $principalId,
                'description' => "{$name} signed in as {$username}",
                'properties' => json_encode(['ip' => $ipAddress, 'userAgent' => $userAgent], JSON_THROW_ON_ERROR),
                'attribute_changes' => json_encode(['attributes' => ['email' => $email]], JSON_THROW_ON_ERROR),
            ],
            [
                ...$attributes,
                'public_id' => (string) Str::uuid7(),
                'subject_type' => null,
                'subject_id' => null,
                'causer_type' => 'audit-user',
                'causer_id' => $person->userId,
                'description' => "{$name} changed the account recovery phone",
                'properties' => json_encode(['user_agent' => $userAgent], JSON_THROW_ON_ERROR),
                'attribute_changes' => json_encode(['old' => ['contact' => ['email' => $email]]], JSON_THROW_ON_ERROR),
            ],
            [
                ...$attributes,
                'public_id' => (string) Str::uuid7(),
                'subject_type' => 'api-key',
                'subject_id' => $credentialId,
                'causer_type' => null,
                'causer_id' => null,
                'description' => "{$name} created a key",
                'properties' => json_encode(['api_key_id' => $credentialId, 'api_key_created_by' => $person->userPublicId], JSON_THROW_ON_ERROR),
                'attribute_changes' => json_encode(['attributes' => ['firstName' => $name]], JSON_THROW_ON_ERROR),
            ],
            [
                ...$attributes,
                'public_id' => (string) Str::uuid7(),
                'subject_type' => 'invoice',
                'subject_id' => '77',
                'causer_type' => null,
                'causer_id' => null,
                'description' => "Invoice updated using {$email} or +39 333 123 4567",
                'properties' => json_encode([
                    'api_key_id' => $credentialId,
                    'api_key_prefix' => 'display-'.substr($credentialId, 0, 8),
                    'ip' => $ipAddress,
                    'user_agent' => $userAgent,
                ], JSON_THROW_ON_ERROR),
                'attribute_changes' => json_encode(['old' => ['contact' => ['first_name' => $name]]], JSON_THROW_ON_ERROR),
            ],
        ]);
    }

    public function supportsOrganisationScope(): bool
    {
        return true;
    }

    public function organisationScopedTables(): array
    {
        return ['activity_log'];
    }

    public function cacheKeys(ErasurePerson $person): array
    {
        return [];
    }

    public function call(Closure $erase): object
    {
        $previousMorphMap = Relation::morphMap();
        Relation::morphMap(['audit-user' => AuthenticatableRecord::class], false);

        try {
            return $erase();
        } finally {
            Relation::morphMap($previousMorphMap, false);
        }
    }
};
