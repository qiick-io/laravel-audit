# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## Migrations before 1.0.0

Migrations freeze at the first stable release (`1.0.0`). Until then, schema
changes are made directly in the package's `create_` migration: an app that
ran an earlier pre-release schema recreates the table (`migrate:fresh`).
