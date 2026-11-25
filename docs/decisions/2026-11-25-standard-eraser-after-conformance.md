# 2026-11-25: the fleet-standard eraser ships after the conformance suite

Team Leader bot, 2026-11-25, under Luca's rule "standard and safe". Answers
finding M3 of the release-candidate review of `develop` at `74ddc85` and issue
#12.

| Item | Decision | Why |
| --- | --- | --- |
| `AuditEraser` with the fleet-standard shape (`erase(...)`, `ErasureReport`, `SubjectErasedEvent`; knowledge `decisions/2026-11-24-fleet-erasure-and-cimd.md`) | **Not in `v0.1.0-beta.1`. Before `1.0.0`:** `AuditEraser` lands as soon as the fleet erasure conformance suite (`qiick-io/app-ui` #225) is merged, and passes it. | Luca approved the shape (knowledge #136), but the conformance suite that pins it (E1–E14) is still in final review and may change details of the contract. Building the eraser before the suite merges risks a second change to a public API. |
| What beta.1 ships for erasure | `Contracts\AuditAnonymiser` and its default `Anonymisation\ActivityAnonymiser`, unchanged. The README section "Erasure perimeter and caller duties" says what it covers. | The anonymiser already does the work the eraser will wrap (design §5, A7, A8, A14): `AuditEraser` builds the `ErasureSubject` and calls the bound `AuditAnonymiser`, so adopters of beta.1 call the same code the eraser will call. |
| What a beta adopter does until then | The product's erasure job calls `AuditAnonymiser::anonymise()` inside its erasure transaction, as `UPGRADING.md` step 7 says. When `AuditEraser` lands, the orchestrator calls it instead; the result on `activity_log` is the same. | No migration and no data change between the two entry points. |
