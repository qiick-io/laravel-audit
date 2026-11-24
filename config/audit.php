<?php

declare(strict_types=1);

/*
 * qiick/laravel-audit configuration (design §4, retention §10).
 *
 * The package also merges its defaults into Spatie's `activitylog` key, so
 * Spatie keeps reading its own configuration.
 */
return [
    /*
     * The secret floor: exact names (in any spelling) whose values are logged
     * as `[redacted]`, on top of the built-in list, every attribute cast as
     * `encrypted*` or `hashed`, and the built-in name parts (password, token,
     * secret, …). A model can add excludes; it cannot remove these.
     */
    'sensitive_attributes' => [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'key_hash',
        'previous_key_hash',
        'secret',
        'token_hash',
        'private_key',
    ],

    /*
     * Extra name parts that make an attribute or a nested key secret wherever
     * they appear, after normalising (lower case, no `_ - .` or spaces), for
     * names in other languages: ['chiave', 'motdepasse']. Moments, flags and
     * counts (`*_at`, datetime, boolean, integer casts) are not matched.
     */
    'sensitive_parts' => [],

    /*
     * Extra keys in `properties` and `attribute_changes` that the anonymiser
     * scrubs, on top of ip, user_agent, email, username, name, first_name and
     * last_name (design §5).
     */
    'personal_keys' => [],

    /* The user agent stored in `properties.user_agent` is cut to this length. */
    'user_agent_max' => 512,

    /*
     * Retention (Luca, 2026-11-23): every log is kept 365 days, the security
     * log 730 days. The daily `audit:purge` deletes in two chunked passes so
     * the two floors cannot be conflated.
     */
    'retention_days' => 365,
    'security_log_name' => 'auth',
    'security_retention_days' => 730,

    'purge' => [
        /*
         * Retention is a privacy obligation, so the provider schedules the
         * purge daily unless the product turns this off and schedules
         * `audit:purge` itself.
         */
        'schedule' => true,
    ],

    'migrations' => [
        /* Load the package's activity_log migration (externa 1.x sets false). */
        'load' => true,
    ],

    /*
     * Morph id columns (design §6.3). Only externa 1.x changes them, to
     * `subject_ref` / `causer_ref`, until its v2.
     */
    'columns' => [
        'subject_id' => 'subject_id',
        'causer_id' => 'causer_id',
    ],
];
