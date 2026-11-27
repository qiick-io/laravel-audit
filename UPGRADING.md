# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## From 0.1.0-beta.1

- `Qiick\Audit\Events\ActivityAnonymised` is renamed
  `Qiick\Audit\Events\ActivityAnonymisedEvent`, with the same `rows` and
  `refs` payload. Update `Event::listen()` registrations, listener type hints
  and `Event::fake()` / assertions, then rebuild the event cache if the app
  caches discovered listeners. Drain queued listeners that hold the old class
  before deploying.

## Migrations before 1.0.0

Migrations freeze at the first stable release (`1.0.0`). Until then, schema
changes are made directly in the package's `create_` migration: an app that
ran an earlier pre-release schema recreates the table (`migrate:fresh`).

## Adopting the package in a product (pre-release products)

For excubia-app, signaculum-core and instituta-core (design §6.1). externa
1.x adopts the table through its own additive migrations once the package is
`1.0.0` (design §6.2) and sets `audit.migrations.load` to `false`.

1. Delete the product's `create_activity_log_table` migration and recreate the
   database: the package migration creates `activity_log`.
2. Delete `config/activitylog.php` unless it overrides something on purpose:
   the package fills `activity_model` and `actions.log_activity`. A product
   model or action must extend `Qiick\Audit\Models\Activity` /
   `Qiick\Audit\Actions\LogActivityAction`, or boot fails.
3. Replace `LogsApplicationActivityTrait` with `Qiick\Audit\Concerns\LogsActivity`,
   and Spatie's `CausesActivity` / `HasActivity` with the package traits of
   the same name.
   Per-model `logExcept()` lists of secrets can go: the floor covers
   `encrypted*` / `hashed` casts and `audit.sensitive_attributes`.
4. Remove the `Activity::beforeLogging` blocks that add `ip`, `user_agent`,
   `api_key_*` or `organisation_id`: the package writes them, and a product
   value for a reserved key is removed.
5. In the API key or OAuth middleware, once the credential is verified, call
   `AuditContext::bindCredential()` with the owner principal as causer and the
   key's public UUID, prefix and creator's public id.
6. Bind `Qiick\Audit\Contracts\OrganisationResolver` to the product's
   resolver: the organisation of the current team context, never a user's
   first membership.
7. Call `Qiick\Audit\Erasure\AuditEraser` from the product orchestrator with
   the snapshot captured before deletion or principal deactivation. The
   eraser joins any transaction on the activity model's connection. Update
   fleet erasure listeners to use the counts-only `SubjectErasedEvent`. If the
   product replaces `AuditAnonymiser`, its binding must implement
   `FleetAuditAnonymiserContract` before switching to `AuditEraser`.
   A legacy-only binding makes fleet calls fail closed before any write or
   event, including dry runs. Direct `AuditAnonymiser::anonymise()` calls
   still use that binding.
8. `activitylog:clean` can be removed from the schedule: the package
   schedules `audit:purge` daily (365 days, 730 for the security logs `auth`,
   `security`, `access` and `api-keys`). If it
   stays, it goes through the same two floors. Product prune commands that
   delete activity rows (for example after 90 days) must go: no row may leave
   before its floor.
9. Keep `app.timezone` at `UTC`. In production, `ACTIVITYLOG_ENABLED` must
   stay true and `ACTIVITYLOG_BUFFER_ENABLED` false, or boot fails.
