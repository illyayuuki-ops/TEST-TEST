# LCCT ServiceDesk — Guidance contribution
## Six-phase development and review record

Date: 29 October 2026 · Scope: Guidance within the combined campus application · Status: local synthetic review build, not production acceptance.

Authoritative base: TEST-TEST commit `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`. The earlier Library-based contribution is superseded. The original React/TypeScript application is retained only as a feature reference; its SQLite records were not imported.

This record follows the teacher's Comprehensive Development Checklist for the Multi-Department School Ticketing System. It records evidence and gaps honestly. Interviews, professor approval, production testing and legal compliance are not fabricated. The full twelve-department project is a team responsibility; this contribution does not claim to complete other departments.

# Phase 1 — Concept

## Business need and vision

Provide a traceable, private Guidance service using the campus system students already sign into. A student should submit a request, see progress, communicate with the authorized counselor and participate in Guidance activities without maintaining a second account or navigating a disconnected demonstration site.

The need is based on the user's requirements and the supplied checklist, not a completed institutional interview study. The checklist's interviews with at least three departments remain pending for the team.

## Scope and charter

In scope: confidential Guidance requests; shared replies/attachments/statuses; walk-in intake; appointments; programs/activities/invitations/RSVP/attendance; certificates and credits; resources; aggregate reports; Guidance membership, privacy review, audit and operational exports.

Out of scope: rebuilding Student Affairs or another department, introducing a second login/database, silently migrating old identities, replacing the common student registration policy, pushing shared main, or deploying a live service without approval.

Success criteria: a request created through the native campus form is the same record shown in Guidance; unauthorized direct URLs fail; Student Affairs remains functional; drafts survive background updates; activity participation/certificates follow validated server rules. The acceptance ledger and test reports provide measurable evidence.

## Stakeholders and ownership

| Stakeholder | Responsibility | Approval status |
|---|---|---|
| Students | Request and participation usability; own-data review | Pending UAT |
| Guidance counselors | Assigned case and appointment workflows | Pending UAT |
| Guidance head | Clinical access, assignment, team and privacy review | Pending approval |
| Guidance activity administrator | Activities, attendance and aggregate reports, not unrestricted clinical reads | Pending approval |
| Combined application / DB owner | Shared schema, first head, configuration, recovery and deployment | Pending approval |
| Student Affairs collaborator | Coexistence regression and shared-contract review | Automated checks only |
| Other department collaborators | Own department implementation and shared merge coordination | Outside this contribution |
| Professor / assessor | Checklist acceptance and academic sign-off | Pending |
| School privacy/security authority | Retention, lawful processing, access and incident policy | Pending |

## Feasibility and risks

Technical feasibility is demonstrated locally with PHP 8.4.27/MariaDB 11.8.6 and browser tests. PHP/MySQL matches the corrected shared source, avoiding a separate Node/SQLite runtime for Guidance. Node 24 remains usable for the source-update helper. Operational feasibility depends on staff training and school policy. Economic feasibility assumes existing local infrastructure; no approved budget or hosting purchase is claimed. Legal feasibility requires a school-led review under the Philippine Data Privacy Act; code alone does not establish compliance.

Major risks: wrong source/version, shared-file merge conflicts, clinical access leakage, exposed historical credentials, incomplete backups/recovery, incorrect role mapping, email/camera/TLS setup, and treating a synthetic review as production. Mitigations include base-hash checks, additive tables, route-level authorization, credential removal/rotation instructions, isolated fixtures, explicit gaps and required UAT.

# Phase 2 — Inception

## Requirements and user stories

| Priority | Story | Acceptance criterion | Evidence / gap |
|---|---|---|---|
| Must | As a student, use one campus account for Guidance | Native login and ticket form; same ticket ID in both views | HTTP regression |
| Must | As a counselor, see only assigned confidential cases | Direct ticket/file/live access denied when unassigned | HTTP and core checks |
| Must | As a head, assign and review Guidance cases | Head-only assignment; membership safety checks | HTTP regression |
| Must | As a student, retain an unsaved draft | Cross-user updates do not reload a changed form | Browser checks |
| Must | As staff, manage participant state correctly | Invitation, capacity, RSVP, check-in and certificate validation | HTTP regression |
| Must | As an activity administrator, report without clinical access | Aggregate CSV allowed, case and attachment denied | HTTP regression |
| Must | As another office, keep existing workflows | SAO ticket/reply/report/team/category/audit checks pass | HTTP regression; broader team UAT pending |
| Should | As a head, review privacy requests safely | Own export excludes internal notes; review does not erase records | HTTP regression |
| Should | As an operator, verify a private backup | Encrypted subset verifies; tamper/wrong key rejected | Operations checks; full restore pending |
| Could | As staff, automate recurring cohort activities | Uses approved shared cohort fields and recurrence rules | Not implemented |
| Not this release | As security owner, enforce hardware binding/2FA | Requires common-auth design and verification | Not implemented |

## Business workflow

```text
Student shared login -> native Guidance request -> shared ticket
    -> head assignment -> assigned counselor replies / internal notes
    -> In Progress / Waiting for User / Escalated / Resolved
    -> student confirmation -> Closed -> policy-limited Reopened

Student appointment request -> overlap/future checks
    -> assigned counselor/head confirmation -> completion or cancellation

Staff activity -> explicit student invitation -> registered or declined
    -> valid window QR/code check-in or recorded staff attendance
    -> completed enabled activity -> immutable certificate and credits
```

The shared source's Low/Normal/Urgent priorities and native statuses are retained. Guidance is excluded from the source's automatic no-reply closure. Scheduled escalation uses the source's 120/72/24-hour elapsed-time targets; institution-specific targets and business-hour policies remain decisions for the team.

## Architecture and data design

Presentation: common PHP shell, responsive Guidance views, local QR scripts and small JavaScript actions/polling. Application: native auth/ticket/file flows plus a validated transactional Guidance domain module. Storage: one MySQL-compatible InnoDB database. Integration: shared integer IDs, office code `guide`, additive `gd_*` relationships, generic shared notifications.

Canonical entities: users, offices, categories, tickets, replies, ticket files and audit/notifications. Fourteen extension tables cover memberships, sessions, case metadata, preferences, feedback, programs, events, invites, certificates, appointments, articles, privacy review, rate buckets and settings. See INTEGRATION.md for the complete relationship and route contract.

## Role and privacy model

Students read their own case. A counselor reads only a currently assigned Guidance case. The Guidance head reads Guidance cases and manages assignments. The activity administrator manages activities/reports without automatic clinical access. Other offices do not inherit Guidance case access. Internal notes and files are excluded from student views and own-data exports.

Reports contain no counseling narrative or student identities. Counts below five and averages with fewer than five contributing cases are suppressed. This is basic disclosure reduction, not a formal statistical privacy guarantee. Retention/deletion is review-based, not automatic.

## Nonfunctional targets and planning

Responsive desktop/mobile operation, clear errors, keyboard-readable labels, reduced-motion support and draft preservation are design targets. Synthetic browser checks cover 1366px and 390px layouts with external font/icon requests blocked. Production accessibility certification, campus-scale load, p95 latency, availability SLA, full backup recovery time/objectives and real-device camera behavior are not yet established.

Proposed team gates: agree shared contracts; review the contribution branch; test against the campus coordinator's MySQL version; conduct representative UAT; resolve blocking privacy/recovery issues; approve a release. No schedule or named assignment is represented as approved without the team's confirmation.

# Phase 3 — Iteration

## Implemented integration

The common users/tickets/replies/files remain the source of truth. New Guidance screens route to native ticket details and file authorization. Walk-ins create a shared ticket for the selected student. Shared login admits enrolled Guidance admins while preserving SAO access. Guidance admins are redirected away from generic admin pages that could bypass the narrower clinical permissions.

The module supplies appointments, activities and grouped sessions, explicit invitations, RSVP/capacity checks, local QR/manual attendance, eligible certificates/credits/history, knowledge drafts/publication, aggregate metrics, membership/category controls and privacy review. New staff use native password hashes and must change their initial password.

## Security controls and limitations

Prepared queries and server-side validation; shared CSRF enforcement; context-escaped output; current membership checks; hashed eight-hour session registry; password-stamp revocation; persistent login/write limits; internal-note/file isolation; generic Guidance case notices; private-path protections; authenticated encrypted exports; and CSV formula mitigation are implemented and exercised in synthetic checks.

Limitations: no hardware binding or 2FA; native student/password-change minimum remains eight characters; no production mail/TLS/camera verification; no attachment antivirus; no full live database encryption/restore implementation; no independent penetration test. Published inline credentials were removed from the working configuration but historical Git exposure requires owner rotation and history review.

## Test and defect record

Current review evidence: 133 HTTP checks, 188 browser checks, 30 core checks and 15 operations checks, totaling 366 passing assertions; 46 PHP files passed syntax checks at the recorded build. Re-run the repository tests after any later changes. Evidence belongs only to this corrected-source build; earlier Node and Library counts are not reused.

Examples of defects found and fixed during this iteration: fixture table extraction count and duplicate configuration load; JSON auth response for a revoked/logout session; development router inadvertently blocking public QR vendors; mobile long-button overflow with offline fallback fonts; and missing preserved base images in the local review workspace, restored byte-for-byte from the exact source commit. No other source files were overwritten by that asset recovery.

Automated HTTP checks cover same-record integration, SAO coexistence, confidential/internal file access, CSRF, invalid transitions, appointments, capacity, certificates, membership, privacy, preferences, password change, rate limits and session replay. Browser checks cover responsive views, offline core functionality, real form submission, shared and module draft preservation, QR rendering and denied-camera manual fallback. Core/operations checks cover validators, role scope, CSV safety, transaction rollback, migration rerun, fixture refusal, scoped jobs and encrypted snapshot integrity.

Formal code review by collaborators and representative UAT remain pending. Automated assertion counts are not a feature-completeness, compliance or production-readiness claim.

# Phase 4 — Release

## Review package and installation

Deliverables: checked source overlay, safe Node file applier, README_GUIDANCE.md, additive migration, guarded fresh synthetic setup, CLI operations tools, six-phase Markdown/Word documentation, integration/parity/UAT records and sanitized test evidence. The applier validates expected base hashes, refuses unknown local changes, creates a sibling backup and never modifies a database. It does not touch existing private configuration or runtime uploads.

Fresh review setup creates a new local `_test` database and random application database account, then inserts seven explicitly synthetic personas. It refuses an existing database/private configuration. It does not import original seed accounts or execute destructive SQL. See the README for copy-paste PowerShell and local start instructions. Do not use the old Library contribution ZIP.

Existing campus integration is a separate coordinator-controlled process: full shared backup, maintenance window, private DB/mail configuration, additive migration and enrollment of an existing shared Guidance administrator as head. One new sign-in is required for existing sessions after initial migration. No live deployment or remote push was executed.

## Release gate

| Gate | Status |
|---|---|
| Correct source and shared-record integration | Locally implemented/tested |
| Source apply safety / preservation | Local helper tests; review package |
| Core/HTTP/browser/operations checks | Synthetic evidence supplied |
| Windows Laragon / MySQL 8.4 runtime | Pending user/coordinator smoke test |
| Real camera / multiple devices / accessibility UAT | Pending |
| Apache and HTTPS deployment hardening | Pending |
| Live mail with nonsensitive content | Pending |
| Complete shared database/upload restore rehearsal | Pending |
| Credential rotation and privacy/retention approval | Pending owner action |
| Professor / stakeholder sign-off | Pending |

## Training and user manual

Students: use the common dashboard, select Guidance services, submit through the native form, follow requests/appointments, respond to invitations and view participation history. Counselors: work only assigned requests; use internal notes appropriately; manage authorized appointments and activities. Heads: assign cases, enroll/manage Guidance staff, maintain services and review privacy/audit. Activity administrators: manage activity operations and aggregate reports without clinical case access.

Do not enter real counseling details during review. Shared passwords in the synthetic fixture are not production credentials. Do not expose PHP's built-in server to the internet. On same-Wi-Fi HTTP review, use manual attendance if the browser blocks camera access.

# Phase 5 — Maintenance

Daily/weekly: review unassigned and overdue Guidance cases, requested appointments, attendance discrepancies, failed operations and support feedback. Use the scheduled CLI in preview mode before enabling `--apply`; it escalates only eligible overdue Guidance tickets and deletes only expired session/rate rows. No automatic clinical erasure is performed. Configure the OS schedule deliberately; no schedule is silently installed.

Access review: check active Guidance membership, reassignment before counselor deactivation, last-head availability, common-account status and school-approved retention rules. Session freshness responds to changed account/password/membership state. Training should emphasize the difference between clinical and activity-administration privileges.

Backup: create authenticated encrypted Guidance subsets outside the web root; keep keys separately; verify integrity and attachments; log operator/date/result. A subset without shared credentials is not a full recovery mechanism. The combined owner must also back up and rehearse restoration of the complete shared database, uploads and private configuration in a separate environment.

Updates: review PHP/MySQL/browser dependencies and vendored QR libraries; preserve licenses; rerun all tests; coordinate shared-file merges across the twelve contributors. Keep secrets, runtime data, uploads and backups out of Git. Monitoring/load targets, incident response contacts, patch cadence and backup retention require institutional approval.

Improvement backlog: approved cohort mappings and recurrence automation, configurable SLA/advanced filters/reports, certificate correction/revocation, common-auth enhancements, full recovery/retirement integration and real-device accessibility/performance review. PARITY.md distinguishes what moved from what remains.

# Phase 6 — Retirement

Retirement triggers may include replacement by the school's approved system, unsupported technology, unacceptable operating risk or a formally approved closure of this module. The institution, not an automated script, decides retention and destruction.

Proposed process: obtain approval; stop new Guidance writes during a controlled transition; inventory related shared records and dependencies; create and verify encrypted exports and a complete shared recovery backup; test the approved successor import with authorization and ID reconciliation; notify/train affected users; revoke obsolete sessions and credentials; remove service access only after acceptance; retain required records with controlled access; and document authorized destruction after retention expires.

The Guidance subset export excludes password hashes and active sessions but includes sensitive Guidance data and attachment bytes under encryption. It requires key custody and access controls. Do not drop common users, tickets, categories or files merely because Guidance is retired; other departments depend on the same schema. No automated full retirement/delete/restore workflow was implemented or executed.

## Evidence and approval ledger

- Requirements source: user directions and supplied teacher checklist.
- Architecture approval: user selected shared architecture A; Guidance only.
- Technical evidence: VERIFICATION.md and sanitized evidence JSON; no real school data.
- Feature completeness: PARITY.md, with explicit partial/deferred items.
- Acceptance: UAT.md, unsigned pending actual review.
- Production release, privacy compliance, full recovery and retirement authorization: pending.

This document is a factual implementation/review record, not a signed project charter or a certification that the entire twelve-department checklist is complete.


# Stage 1 restoration addendum — 30 October 2026

The earlier phase record above remains a historical implementation record. It does not establish full TS-to-PHP parity. RESTORATION-TRACKER.md is the current comprehensive A–N gap ledger, and RESTORATION-STAGE1.md defines this source-only release.

Phase 1 / Concept: Guidance-only shared architecture and source preservation remain unchanged. User authorized restoring all missing features in controlled stages, conditional on preserving the combined source.

Phase 2 / Design: add query workspaces and marked GET filters; maintain per-case and per-appointment authorization independently. Reports use intake-cohort semantics and minimum-cell suppression; no inferred academic data. No database schema or shared-auth change.

Phase 3 / Development: student directory, request filters/sorting/pagination/read-only board, staff metadata CSV and expanded filtered aggregate reports implemented. Existing native shell/dashboard/actions and top-right Settings location retained. Separate copy from the verified Role Sidebar baseline; no Git push/deployment.

Phase 4 / Testing: 919 prior plus 341 new application checks passed (1,260 total); PHP lint and responsive/no-JS/draft/privacy checks completed on synthetic data. Installer/rollback tests are separate evidence. No school UAT, legal/privacy certification or production-load acceptance is claimed.

Phase 5 / Maintenance: guarded source preflight, sibling backup and hash-checked source rollback. Academic metadata/cohorts, waitlist, staff certificate register, service/routing detail, attendance policy, staff edit/recovery, notification/resource/help discovery, audit/privacy and operations/shared recovery remain tracked, not silently dropped.

Phase 6 / Retirement: unchanged. Source rollback is not database restore or authorized record deletion. Preserve all data, private configuration, uploads, old sources and backups. Shared full-system restore and any old-data import require coordinated ownership and separate review.


# Consolidated restoration addendum — 30 October 2026

This addendum supersedes the Stage 1 pending list. The current A–N tracker and CONSOLIDATED-RESTORATION.md define implementation, adaptations and shared-owner gates. No production or full-parity certification is implied.

Phase 1 — Concept: deliver the remaining Guidance-scoped work in one combined release at the user’s request. Preserve the single shared identity/database/application, other departments, original source, configuration, data and backups.

Phase 2 — Design: ten additive gd_plus tables, no shared-table ALTER/DROP; independent authorization for clinical and activity records; explicit academic source/verifier; Guidance-only High/targets without altering shared enum semantics; staged privacy and audit-safe exports. Whole-system recovery/common self-service auth policy remain coordinated gates.

Phase 3 — Development: cohort preview/invitations, waitlist, register/individual certificates, attendance policy/provenance, service hierarchy/routing, intake attachments/preferences, review/reprioritization/reasons, staff edit/assisted reset, full notifications, resource/help discovery, keyboard palette, audit/privacy and operations/verified subset scheduling are implemented. Settings remains header-only. Source installer accepts known Role Sidebar or Stage 1 baselines and rejects unknown edits.

Phase 4 — Testing: 1,673 application/operations checks passed in an isolated synthetic PHP/MariaDB/browser environment, including separate-process last-seat concurrency, all roles, aggregate privacy, upload isolation, migration-repeat/schema guards, backup tamper/key checks and responsive/keyboard/draft flows. Installer/rollback tests are separate evidence. Legacy assertions were appropriately scoped for additional forms/navigation and additive table count; screenshots were bounded to avoid synthetic fixture page-height limits. Windows Task Scheduler execution, physical camera, production load and school UAT remain unverified.

Phase 5 — Maintenance: default plan-only migration, source preflight/backup/rollback, full shared-backup prerequisite and explicit opt-in scheduler. New tables are included in encrypted Guidance subset backups. Interactive Windows tasks require a logged-on user and correct private environment. No key/password is shipped or displayed, and no retention deletion is scheduled.

Phase 6 — Retirement: unchanged institutional authority. Rollback restores source only and preserves new data/tables. Stop new scheduled tasks before rollback. Old source cannot display all new metadata or back it up completely. Full shared restore, historical identity/data import, common recovery policy, 2FA/hardware binding, legal retention and final retirement remain separately approved work.
