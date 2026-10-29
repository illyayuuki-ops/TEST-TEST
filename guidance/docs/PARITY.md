# Old Guidance feature inventory and transfer ledger

Reviewed against the preserved React/TypeScript/Express/SQLite source, especially `server/index.ts`, `server/engine.ts`, `server/database.ts`, `server/archive-tool.ts`, and the earlier PHP reference. The Library-based payload is **not** used as an installable foundation. Old test counts do not certify this new build. Old SQLite records were not imported.

Status definitions: **Connected** = implemented against corrected shared records; **Adapted** = meaningful implementation with changed shared-source behavior; **Partial** = known missing capabilities; **Deferred** = not implemented here; **Out of scope** = belongs to other departments or the combined application owner.

| Original capability | Status | Corrected-source implementation / gap |
|---|---|---|
| Shared sign-in/sign-out, password hashing | Connected | Native auth/users; new session registry and freshness checks |
| Register, verify, reset password | Adapted | Existing common student routes retained; no live email delivery verified |
| Account approval / student administration | Out of scope | Existing common owner/SAO policy, not recreated inside Guidance |
| Four Guidance-facing personas | Adapted | Student plus head/counselor/activity administrator memberships over shared admin rows; no all-powerful clinical technical admin |
| Device binding and 2FA | Deferred | No hardware binding or 2FA; eight-hour server sessions are not a substitute |
| Own sessions / sign out all | Connected | Shared session revocation, cookie replay checks |
| Requests, replies, internal notes, attachments | Connected | Same `tickets`, `replies`, `ticket_files`; authorization on ticket/live/file routes |
| Walk-in intake | Connected | Selected student remains owner; creator recorded separately |
| Assignment / reassignment | Adapted | Head-only reassignment; currently assigned counselor scope |
| Ticket lifecycle, reopen, closure | Adapted | Native statuses; validated Guidance transitions; no automatic no-reply closure for Guidance |
| Priority / SLA / escalation | Partial | Native Low/Normal/Urgent targets, manual escalation, scheduled Guidance-only overdue escalation; no High priority or configurable per-Guidance target editor |
| Categories and knowledge | Connected | Shared Guidance categories; draft/published resources |
| Updates and notifications | Adapted | Shared bell; generic in-app case notices, preferences, draft-safe refresh; no external clinical-case emails |
| Search / filtering | Partial | Basic request search and native portal filtering; not all old advanced filters |
| Appointments and calendar | Connected | Future booking, conflict checks, counselor/head status handling; chronological calendar, not drag-and-drop scheduling |
| Guidance activity types | Connected | Seminar, workshop, assembly, orientation, testing, career fair, recollection, wellbeing and other |
| Recurring programs | Partial | Grouped manually authored sessions; no automatic recurrence generator |
| Audience/cohort selection | Partial | Explicit verified-student invitations, deduplication and capacity checks; no old program/year-level audience matching engine |
| Invitation -> RSVP -> attendance | Connected | Per-student state/history, registered/declined and eligible attendance |
| Student venue QR / staff pass scan | Connected | Local QR libraries, authenticated server checks, bounded window; camera permission fallback tested, physical-camera scan still UAT |
| Manual codes / manual attendance | Connected | Same policy checks; staff manual attendance recorded |
| Certificates and credits | Adapted | Authenticated printable certificate, snapshot, idempotent issuance; no public verification, correction or revocation workflow |
| Event deletion | Adapted | Cancel instead of destructively deleting participation history |
| Student participation history | Connected | Invitations, RSVP, attendance, earned credits and certificate access |
| Aggregate counselor/admin analytics | Partial | Status, priority, attendance, overdue counts, suppressed response/resolution averages and CSV; no full old advanced chart/filter suite |
| Audit and rate limits | Connected | Scoped/redacted action audit, persistent limits, shared actor IDs; not a completed independent security audit |
| Privacy/export/review | Adapted | Own-record JSON excludes internal notes/file bytes; review-only erasure requests; no automatic clinical deletion |
| Encrypted backups / integrity checks | Partial | CLI authenticated encrypted Guidance subset including files; no web operations console or automatic backup schedule installation |
| Whole-system restore and safety snapshot | Deferred | Original Express implementation had full SQLite restore. Blindly porting its delete/reinsert behavior would endanger the combined database; owner-approved shared recovery required |
| Retirement archive / deletion controls | Partial | Encrypted subset and written retirement process; no full coordinated retirement automation |
| Operations dashboard, mail queue, health metrics | Partial | CLI jobs and evidence/docs; not the former operations UI |
| Department administration / twelve staff modules | Out of scope | Preserve corrected shared source; other collaborators own their departments |
| Production release / remote push | Deferred | Local synthetic review only; no new push or deployment authorization |

## Next prioritized work

1. Run the corrected build on the user's Laragon/MySQL 8.4 environment and obtain real student/counselor/head/SAO UAT.
2. Agree the combined application's full-backup/restore ownership, encryption/retention and recovery rehearsal before real records.
3. Add approved cohort mapping and recurrence rules using actual shared student fields, not a duplicate Guidance identity model.
4. Agree SLA policy, additional reporting/filtering, certificate correction/revocation and account lifecycle governance.
5. Plan advanced authentication with the common auth owner. Do not market device binding or 2FA before implementation and verification.
