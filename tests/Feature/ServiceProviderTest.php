<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use Qiick\Audit\Laravel\AuditServiceProvider;

it('is registered by the test app', function (): void {
    expect(app()->getProviders(AuditServiceProvider::class))->toHaveCount(1);
});

it('merges the design defaults under the audit key', function (): void {
    expect(config('audit.user_agent_max'))->toBe(512)
        ->and(config('audit.retention_days'))->toBe(365)
        ->and(config('audit.security_log_name'))->toBe('auth')
        ->and(config('audit.security_retention_days'))->toBe(730)
        ->and(config('audit.purge.schedule'))->toBeTrue()
        ->and(config('audit.migrations.load'))->toBeTrue()
        ->and(config('audit.columns'))->toBe([
            'subject_id' => 'subject_id',
            'causer_id' => 'causer_id',
        ])
        ->and(config('audit.sensitive_attributes'))->toBe([
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'key_hash',
            'previous_key_hash',
            'secret',
            'token_hash',
            'private_key',
        ])
        ->and(config('audit.personal_keys'))->toBe([]);
});

it('publishes the config under the audit-config tag', function (): void {
    $publishedPaths = ServiceProvider::pathsToPublish(AuditServiceProvider::class, 'audit-config');

    expect(array_values($publishedPaths))->toBe([config_path('audit.php')]);
});
