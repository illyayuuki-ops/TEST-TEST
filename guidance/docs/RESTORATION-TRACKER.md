# Consolidated Guidance restoration tracker

30 October 2026 · Corrected TEST-TEST `75fe13e` + Role Sidebar / Restoration Stage 1 · Guidance only, one shared application/database/login.

This ledger replaces the Stage 1 pending list. **Delivered means implemented and tested locally on synthetic data, not school UAT, production approval, or exact recreation of every old implementation detail.** The consolidated package includes Stage 1 as well as the work below. Nothing is pushed or deployed remotely.

| Audit item | Consolidated implementation | Boundary / acceptance still needed |
|---|---|---|
| A — Directory and academic metadata | Scoped searchable directory/caseload plus Head-managed program/year/section with source reference, verifier and timestamp; academic directory filters. | Guidance-scoped verified metadata, not a duplicate login/student table. No automatic registrar sync or guessed data. School must enter/verify actual records. |
| B — Reports | Intake-date/service/counselor and current academic cohort filters; workload, age, trends, response/resolution, satisfaction, SLA and participation views/CSV. | Minimum-cell suppression, not differential privacy. Academic data is current, not a historical enrollment snapshot. Activity charts use activity start dates only. Definition/load/UAT review remains. |
| C — Queue | Server filters/sorting, paginated list/read-only board, private authorized metadata CSV, High priority support. | Board is not drag-and-drop. No student names/narratives in queue CSV; references remain private. |
| D — Cohort invitations | Automatic matching of verified academic fields, audience preview and fingerprint confirmation, duplicate-safe invitations. | Explicit confirmation replaces silent audience expansion. Existing/new invitations do not reserve seats. Max 500 per confirmed cohort; narrow larger cohorts. No automatic registrar import. |
| E — Waitlist | Join/withdraw, preserved queue ordering, staff-confirmed first-eligible promotion, capacity checks under database lock. | No automatic promotion policy is invented. Regular invitations can still register when capacity is available; waitlist is not a reserved-seat system. |
| F — Certificate register | Staff search/date-filtered register, authenticated viewing/printing and idempotent individual issuance, in addition to existing bulk issuance. | Completion, certificate enablement and eligible attendance required. No public verifier, revocation or correction workflow claimed; not proven in original feature inventory. |
| G — Services and High/SLA | Guidance description/hierarchy with cycle checks, default routing counselor and per-priority operational targets; Guidance-only High. | No shared priority enum/policy alteration: High uses shared Normal plus Guidance metadata. Guidance screens, filtered reports and Guidance jobs use the extension target; generic common screens and legacy aggregate export keep common semantics. |
| H — Intake/review/workflow | Guidance intake with initial attachments and preferred counselor/time, configured service routing, review/reprioritization, reasoned escalation/cancellation, authorized history. Native Guidance status/cancel forms also enforce reasons. | Preferences are not guaranteed assignments or confirmed bookings. Native lifecycle remains authoritative. Generic campus multi-office intake retains its original fields; Guidance intake is the expanded path. Review reasons are staff-only except a requester’s own logged transition reasons. |
| I — Attendance | Facilitator, optional auto-late threshold, explicit manual-window policy, method/checker provenance, duplicate check-in protection and certificate locks. | QR vs typed-code label describes the client-reported method, not hardware attestation. Server still verifies identity/registration/code/window. Real camera/device UAT remains. |
| J — Staff editing/recovery | Head-only Guidance staff profile editing and assisted temporary-password reset, current-head-password confirmation, rate limit, Argon2id, session revocation and forced change at sign-in. | Head must verify identity and share temporary password privately. **Shared self-service recovery/email policy, 2FA and hardware binding remain shared-owner work**, not silently changed. |
| K — Notices/resources | Full own notification list/search/read controls, authorized record-specific links for new Guidance notices (including native bell), legacy workspace fallbacks, published-resource search; corrected native clinical-email success wording. | No clinical email delivery promised. Old notices without record references cannot be retroactively given invented exact targets. Other departments retain their native notification behavior. |
| L — Help/catalogue/palette | Searchable service catalogue and real request links; role-aware Ctrl/Command+K page navigation and keyboard controls; no fake Send success. | Emergency/contact/response-time claims are not fabricated. Settings stays in top-right account menu, not sidebar or palette. Institution-approved contact details can be published as resources. |
| M — Audit/privacy | Head-only date/actor/action/search audit and safe-field CSV; staged privacy review, append-only response history and requester reply while awaiting information. | No automatic clinical erasure. Audit CSV omits descriptions, IP addresses and counseling text and withholds requester identities. School retention/privacy approvals still required. |
| N — Operations/backup | Head operations status, Guidance scheduler, custom-target escalation, opt-in Windows daily task, encrypted subset backup includes all new tables and verifies before recording success. | **Full shared-system restore and historical data import remain owner-coordinated.** Scheduler is current-user/interactive, not a server service; Windows execution is UAT, not tested in Linux. No clinical mail queue is invented. |

## Explicit shared-owner / institution gates

These are not labeled complete and were not executed:

1. Whole-application restore/recovery rehearsal, including shared accounts, uploads and private configuration. Guidance subset verification is not a full restore.
2. Changes to common registration/self-service recovery, shared priority semantics, hardware binding or 2FA.
3. Old SQLite data import / identity reconciliation; no records imported or deleted.
4. Windows Task Scheduler execution, actual school email/contact approval, camera/device testing, production load/security review and signed role-based UAT.

## Preservation rules

- Combined installer recognizes the installed Role Sidebar baseline or Stage 1 and refuses unknown edits/collisions before copying.
- Source backup and rollback preserve replaced and newly added files. Database tables/data are not dropped on rollback.
- Ten new `gd_plus_*` tables; no ALTER/DROP of shared tables, no replacing accounts/tickets/uploads, no second application/login/database.
- Source installation and opt-in database migration are separate. Default migration invocation is plan-only.
- Keep original TS/PHP projects, all data and backups. Shared main is not pushed or overwritten remotely.

See `CONSOLIDATED-RESTORATION.md` for installation, compatibility, test evidence, operational limitations and rollback. Earlier patch documents are historical records, not the current completion ledger.
