# Standards traceability

1.0 checklist item 7a: every MUST of the standards the design names
(`laravel-audit-design.md` §8) is implemented, every SHOULD is implemented or
moved to a dated decision, and every LATER item is additive after `1.0.0`.
The tests named here are the evidence; `docs/security-checklist.md` maps the
design risks A1–A14.

## MUST: implemented

| Source | Requirement | Where | Test |
| --- | --- | --- | --- |
| OWASP Logging CS, "what to log"; ASVS 16.2.1 | authentication, authorization and admin events can be logged with who, what, when, where | `LogsActivity`, `activity()`, attribution in `LogActivityAction` | `AttributionTest`, `IntegrationTest` |
| OWASP Logging CS, "never log"; ASVS 16.2.5 | no secrets, passwords or session ids | secret floor (`SecretAttributeFloor`) | A1: `LogsActivitySecretFloorTest`, `SecretAttributeFloorTest` |
| OWASP Logging CS, attribution integrity | callers cannot forge the actor, credential or tenant | reserved keys, server-written organisation | A2, A3, A4: `AttributionTest` |
| OWASP Logging CS, injection; ASVS 16.4.1 | log data stored as data, escaped by the reader | stored as is in text / JSON | A10: `IntegrationTest` |
| OWASP Logging CS, "protect the log from being turned off" | logging cannot be turned off or silently lost | production boot checks | A9: `AuditBootChecksTest`, `ProductionBootFailsTest` |
| ASVS 16.2.2, RFC 3339 | UTC time, unambiguous format | `app.timezone` boot check, `Z` export, `datetime` columns | A13: `BootFailsTest`, `IntegrationTest`, `ActivityLogSchemaTest` |
| ASVS V8 (authorization), fleet core | tenant isolation of reads | `forOrganisation()` | A5: `ActivityScopesTest` |
| Fleet core schema | no internal ids leave the database; UUID v7 `public_id` (RFC 9562) | `Activity` | A6: `AuditActorTest`, `AuditContextTest`, `IntegrationTest` |
| GDPR Art. 17(1), 17(3)(b) and (e) | erase personal data, keep evidence under a legal obligation | `AuditEraser`, `ActivityAnonymiser` | A7, A8, A14, E1–E14: `ActivityAnonymiserTest`, `AuditEraserTest`, `tests/Erasure` |
| GDPR Art. 5(1)(e), NIST SP 800-92 | bounded retention, enforced on every delete path | `RetentionPurger`, `audit:purge`, `CleanActivityLogAction` | `PurgeActivityLogCommandTest`, `PurgeScheduleConfigTest`, `CleanActivityLogActionTest`, `PurgeConcurrencyTest` |
| GDPR Art. 5(2), 25 | the log is append-only for the application | write-once model | `ActivityImmutabilityTest`, `ActivityWritesArchitectureTest` |
| Fleet conventions F1, F2, F5, F9, F12 | nested config defaults, deploy check, `*Event` names, scheduled chunked purge, the standard package files | provider, `audit:check`, `Events\*Event`, `docs/` | `PublishedConfigDefaultsTest`, `AuditCheckCommandTest`, `NamingArchitectureTest`, `PublicApiTest` |

## SHOULD: implemented or decided

| Source | Item | Status |
| --- | --- | --- |
| W3C Trace Context | correlate rows with application logs | implemented: `request_id` (`string(128)`) |
| OWASP Logging CS, "where" | IP and user agent of the request | implemented: reserved keys `ip`, `user_agent` (cut to `audit.user_agent_max`) |
| ASVS 16.4.2 | logs cannot be modified | partly implemented: the application cannot update or delete rows outside the anonymiser and the purge (`ActivityImmutabilityTest`); protection against holders of the database credentials is the product's, with 16.4.3 |
| ASVS 16.4.3 | security logs sent to a logically separate, protected system | product duty, documented in the README threat model; export procedure tracked in #24 |
| GDPR Art. 32 | erasure safe under concurrency | implemented: row locks (`AnonymiserConcurrencyTest`); proof under truly parallel processes is part of the RC review (#10) |
| Fleet erasure decision 2026-11-24 | one fleet eraser shape, constant tombstone | implemented: `docs/decisions/2026-11-25-standard-eraser-after-conformance.md` |
| Fleet core schema, `public_id` on rows that leave the database | implemented | — |

## LATER: additive after 1.0.0

Each one is a new table or a new nullable column, so no existing row is
rewritten (design §3).

- Legal hold (a separate table, excluded from both purge passes).
- Hash chaining for tamper evidence.
- `batch_uuid`.
- Event names aligned with the OWASP Logging Vocabulary Cheat Sheet (`event`
  values; the column already exists).
