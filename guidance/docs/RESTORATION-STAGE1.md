> **Consolidated release update — 30 October 2026:** This is a historical patch/inventory record. The current feature status is `RESTORATION-TRACKER.md`; use `CONSOLIDATED-RESTORATION.md` for the combined installation and migration.

# Guidance restoration — Stage 1

30 October 2026 · Source-only, Guidance-scoped update · **Not full feature parity or production acceptance**

## What changes

- **Student directory:** Head and counselor primary navigation only. Search by name/school ID/email; paginated verified-student list; student detail with independently scoped case and appointment lists. A counselor's appointment with a student does **not** grant access to another counselor's cases. No edit/delete controls or inferred academic demographics.
- **Requests:** server-side search and filters for status, service, current priority, assignment, creation dates and due targets; recent/oldest/priority/due sorting; 25-record pages; list or read-only status board. Board columns show records on the current page, not full-column totals. Student rating forms remain available for resolved/closed requests.
- **Queue CSV:** Head/counselor only, authorized filtered cases across all pages. Reference, service, status, priority, counselor and dates only. No student names/IDs, subjects, descriptions, replies, internal notes, feedback text or file contents. References are still private case metadata; handle accordingly. This is separate from aggregate analytics.
- **Aggregate reports:** inclusive intake-date/service/counselor filters, accessible tables and CSS bars, workload/backlog/trend/response/resolution/satisfaction/target views, activity attendance/RSVP and filtered CSV. Existing `export.php?type=reports` remains compatible.
- **Draft safety:** new read-only GET filter forms do not create an unsaved-write warning. Existing POST drafts still block navigation, including navigation via a filter form.

## Reporting definitions and limits

Case dates select requests **created** in the range, inclusive, in Philippine time. Current status/service/counselor determine grouping. Resolution and first public staff reply means describe that intake cohort, not work performed during the period. Feedback uses the currently recorded rating, never its narrative. Open workload includes Waiting for User; overdue excludes it, matching the common shared policy. No SLA/priorities are changed.

Activity charts select the **activity start date** and are independent of case service/counselor controls. Counts below 5 and means with fewer than 5 eligible samples are suppressed in HTML, bars and CSV. Target percentage is suppressed unless both outcome cells have at least 5 records. Suppressed bars have zero width and no hidden raw count. Staff counselor names may label workload groups; student identities and counseling text are not included.

This is minimum-cell suppression, **not differential privacy or protection against all overlapping-query inference**. Keep reports staff-only and do not publish filtered outputs as anonymous datasets. Production-scale load and school interpretation review remain pending. Like the existing app, query helpers currently load authorized case sets before filtering/pagination; display pagination is not a large-database scalability claim.

## What does not change

- Shared database schema, migrations, existing records/accounts/files and all non-Guidance department source.
- Shared login/registration/recovery, passwords and policy constants.
- Dashboard markup, existing shared shell, Settings location (top-right account dropdown only).
- Existing requests/actions/attachments, appointments, activities, RSVP/QR/attendance, certificates, privacy, audit and CLI backup/job behavior.
- No database import/reset, old SQLite import, deployment or remote Git push.

See **RESTORATION-TRACKER.md** for every remaining audit gap. Academic metadata/cohort invitations, waitlist, staff certificate register, richer categories/routing, attendance details, staff editing/recovery, notification/resource/help discovery, audit/privacy controls and operations/restore are **not all completed by this patch**.

## Safe installation on Windows

Prerequisite: the corrected combined source with **Role Sidebar installed**. Stop the PHP development server with **Ctrl+C in its server window** before applying. Leave MySQL/data alone. Do not install over the old Library project or an unknown edited source.

Extract `LCCT-Guidance-Restoration-Stage1.zip` into Downloads. In PowerShell:

```powershell
$project = Join-Path $env:USERPROFILE 'Downloads\COLLAB'
$patch = Join-Path $env:USERPROFILE 'Downloads\LCCT-Guidance-Restoration-Stage1'
node "$patch\apply.cjs" "$project" --check
if ($LASTEXITCODE -ne 0) { throw 'Preflight stopped. Keep your files; request a merge.' }
node "$patch\apply.cjs" "$project"
if ($LASTEXITCODE -ne 0) { throw 'Update stopped. Read its message and retain the backup.' }
```

The installer validates payload hashes, baseline hashes, unmodified shared anchors and destination paths **before writing**. Unknown collaborator edits cause a stop, not a forced overwrite. Windows CRLF text is accepted. New file collisions and symlink/junction destinations are refused. A sibling source backup records exact originals and added files. Repeat installation of this exact release is a no-op. `--check` writes nothing, including no backup.

Restart your existing PHP server using the same local configuration, then Ctrl+F5. **Do not run SQL setup or a migration for this stage.** Your existing database and private connection settings stay as they are.

## Source rollback

Stop the PHP server. Use the exact backup path printed by this installer:

```powershell
node "$patch\rollback.cjs" 'C:\Users\User\Downloads\COLLAB-pre-guidance-restoration-stage1-EXACT-TIMESTAMP'
```

Replace the sample path with your real path; never guess a timestamp. Rollback verifies originals and current release hashes, restores replaced files, and **moves** newly added files into `retired-additions` inside the backup rather than deleting them. It refuses unrecognized post-install edits. It does not reverse database transactions, audit events or unrelated source changes, and it does not touch `.env`, private config, uploads, Git or MySQL. Keep backups after rollback. A rollback is not a full-system disaster recovery tool.

## Verification

Synthetic PHP/MariaDB/browser tests only; no real school database or emails used. The release evidence contains:

- 919 prior regression checks rerun successfully after the query/form changes.
- 341 new checks: 49 query/privacy unit checks, 159 HTTP/auth/filter/export checks and 133 browser checks.
- **1,260 / 1,260 application checks** combined; this count is not a claim of full feature parity.
- All 55 application PHP files in the isolated tree linted successfully at verification time.
- Head/counselor new workspaces at 1366/390/320 px; student/operations boundaries; no-JavaScript filters; independent case/appointment scope; GET-filter navigation and protected POST drafts.
- Screenshots and per-check JSON; no browser JavaScript exceptions in the completed suites.
- Installer/rollback checks are reported separately in `evidence/package.json`.

An initial test-environment image failure was resolved by restoring unchanged official assets from the pinned upstream commit **only into the isolated test tree**; those assets are not in this patch. A regression test caught the GET-form draft-warning interaction; the fix excludes only marked read-only filter forms and preserves POST draft warnings. An initial CSV assertion was corrected to parse valid CSV quoting. Final passing evidence is from reruns, not from ignoring those failures.

## Local acceptance after installation

1. Head: directory appears; student list/detail and request filters/board work; Settings remains header-only.
2. Counselor: an unrelated student cannot be opened by changing its URL ID; a linked student's other counselor cases/appointments stay hidden.
3. Student: no directory/reports access; own requests/ratings still work.
4. Activity administrator: no directory or clinical queue CSV; activities and aggregate reports still work.
5. Filter, reset, next-page and CSV results agree; CSV includes all authorized filtered pages.
6. Reports with small cohorts say Suppressed, without names/clinical text; date semantics are understood.
7. A partially typed feedback/write form still warns before navigation; ordinary filtering does not.
8. Check another department with its owner. Keep backups and report a conflict rather than forcing a patch.
