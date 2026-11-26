# Public API

Everything listed here is the public API of `qiick/laravel-audit`. In `0.x` it
may change between minors, each break recorded in `UPGRADING.md`; from `1.0.0`
it changes only as the deprecation policy in the README allows. Everything else
in `src/` is marked `@internal` and may change in any release.
`tests/Arch/PublicApiTest.php` fails when a declaration in `src/` is neither
`@api` nor `@internal`, when an `@api` one is missing from this page, or when a
config key is missing below.

## Classes, interfaces and traits

Public means the name, the public methods and constructor, public constants
and the public properties of readonly value objects. Internal on purpose:
`AuditBootChecks`, `PurgeActivityLogCommand` (the command name is public, not
the class), `StringKeyedMorphMany`, `ActivityAttributor`, `ActivityLogger`,
`RetentionPurger` and `SecretAttributeFloor`. `AuditContext::rememberHttpRequest()`
and `AuditContext::httpRequest()` are `@internal` methods of a public class.

- `Qiick\Audit\Audit` (class)
- `Qiick\Audit\AuditContext` (class)
- `Qiick\Audit\Actions\CleanActivityLogAction` (class)
- `Qiick\Audit\Actions\LogActivityAction` (class)
- `Qiick\Audit\Anonymisation\ActivityAnonymiser` (class)
- `Qiick\Audit\Concerns\CausesActivity` (trait)
- `Qiick\Audit\Concerns\HasActivity` (trait)
- `Qiick\Audit\Concerns\LogsActivity` (trait)
- `Qiick\Audit\Contracts\AnonymisationReport` (class)
- `Qiick\Audit\Contracts\AuditActor` (class)
- `Qiick\Audit\Contracts\AuditActorResolver` (interface)
- `Qiick\Audit\Contracts\AuditAnonymiser` (interface)
- `Qiick\Audit\Contracts\ErasureSubject` (class)
- `Qiick\Audit\Contracts\FleetAuditAnonymiserContract` (interface)
- `Qiick\Audit\Contracts\MorphRef` (class)
- `Qiick\Audit\Contracts\OrganisationResolver` (interface)
- `Qiick\Audit\Erasure\AuditEraser` (class)
- `Qiick\Audit\Erasure\ErasureReport` (class)
- `Qiick\Audit\Erasure\OrganisationScopeUnsupportedException` (class)
- `Qiick\Audit\Events\ActivityAnonymised` (class)
- `Qiick\Audit\Events\SubjectErasedEvent` (class)
- `Qiick\Audit\Laravel\AuditServiceProvider` (class)
- `Qiick\Audit\Models\Activity` (class)
- `Qiick\Audit\Resolvers\NullOrganisationResolver` (class)
- `Qiick\Audit\Resolvers\RequestAttributeActorResolver` (class)

Container bindings the provider registers with `bindIf` (a product binding
wins): `AuditActorResolver` → `RequestAttributeActorResolver`,
`OrganisationResolver` → `NullOrganisationResolver`, `AuditAnonymiser` →
`ActivityAnonymiser`. `AuditContext` is scoped.

## Config keys

Every key sits in `config/audit.php`, which documents each one:

- `audit.sensitive_attributes`
- `audit.sensitive_parts`
- `audit.personal_keys`
- `audit.erasure_tombstone`
- `audit.user_agent_max`
- `audit.retention_days`
- `audit.security_log_names`
- `audit.security_retention_days`
- `audit.purge.schedule`
- `audit.purge.time`
- `audit.purge.chunk_size`
- `audit.migrations.load`
- `audit.columns`

The package also sets and checks Spatie's keys: it fills
`activitylog.activity_model`, `activitylog.actions.log_activity` and
`activitylog.actions.clean_log` unless the product configured subclasses,
writes `activitylog.clean_after_days` from `audit.retention_days`, and boot
checks `activitylog.enabled`, `activitylog.buffer.enabled` (production) and
`app.timezone`.

## Environment variables

None of its own. Spatie's `ACTIVITYLOG_ENABLED` and
`ACTIVITYLOG_BUFFER_ENABLED` must stay `true` / `false` in production, or boot
fails.

## Reserved property keys

Written by the package on every row, never by callers (design §3.1): `ip`,
`user_agent`, `actor_kind`, `api_key_id`, `api_key_prefix`,
`api_key_created_by`, `oauth_client_id`.

## Events

- `Qiick\Audit\Events\ActivityAnonymised` after a direct `AuditAnonymiser::anonymise()` call commits.
- `Qiick\Audit\Events\SubjectErasedEvent` after a real `AuditEraser::erase()` call commits, with an `ErasureReport` containing counts and retention reasons.

## Artisan commands

- `audit:purge` (scheduled daily by the provider unless `audit.purge.schedule`
  is false). Spatie's `activitylog:clean` stays available and goes through the
  same retention floors.

## Publish tags

- `audit-config`

## Migrations

- `2026_11_24_000000_create_activity_log_table.php`, loaded unless
  `audit.migrations.load` is false. The columns are in
  `docs/reference-schema.md`.

## Wire and storage formats

- `activity_log.schema_version`: `1` is the current row format, `0` the legacy
  externa shape; readers branch on it.
- `[redacted]` is the value of a secret in `attribute_changes`.
- Log names are stored trimmed and lower-cased.
