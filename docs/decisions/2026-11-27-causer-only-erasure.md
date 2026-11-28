# 2026-11-27: Erasure keeps another subject's attributes in rows the person only caused

**Context.** Wiring `AuditEraser` into Excubia's erasure orchestrator showed that a
row the erased person only caused (they renamed a project) lost the project's
`name` in `attribute_changes`: the anonymiser scrubbed every personal key in
every row linked to the person. Those attributes are evidence about another
subject, not the person's data.

**Decision** (coordinator, 2026-11-27, on the maintainer's rule "standard and
safe"). In a row where the person is only the causer and the row has another
subject:

- `properties`, the context of the request that wrote the row, lose every
  personal key as before (`ip`, `user_agent`, `email`, names, `audit.personal_keys`);
- the subject's `attribute_changes` keep their keys; only the person's
  identifiers (names, usernames, public ids, their emails and phones in plain,
  URL-encoded or `\uXXXX` form, and numeric phone values) are replaced, at any
  depth (an `assigned_to` holding their name). Generic email/phone patterns are
  not applied there: they would wipe the other subject's own contacts;
- `description` is scrubbed as before.

Personal keys apply in full when the person (or one of their principals) is
the subject, when one of their keys is the recorded credential, and in a
causer row with no subject.

**Tests.** `tests/Feature/CauserOnlyErasureTest.php`.
