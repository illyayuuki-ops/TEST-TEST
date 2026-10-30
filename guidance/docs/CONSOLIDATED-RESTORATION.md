# Consolidated Guidance restoration

**30 October 2026 · Local tested release · Guidance only**

This is one consolidated package containing Stage 1 and the remaining Guidance-scoped restoration. It targets either the installed **Role Sidebar** release or **Restoration Stage 1**. It does not replace your entire project or deploy to a remote server.

Read `RESTORATION-TRACKER.md` for the A–N feature ledger. Common self-service recovery, hardware binding/2FA, whole-system restore and old-data import are explicitly not claimed as completed.

## Main additions

Verified academic metadata and cohort filtering/invitations; explicit waitlist and staff-confirmed promotion; certificate register/individual issuance; service descriptions/hierarchy/routing/Guidance-only SLA and High priority; expanded intake with initial attachments and preferences; case review/reprioritization and escalation/cancellation reasons; facilitator, automatic late policy and attendance provenance; scoped staff profile editing and assisted password reset; full notifications/deep links/read controls; resource search; help/service catalogue and keyboard page finder; audit filters/safe CSV; privacy stages/history/requester replies; operations status and opt-in verified encrypted backup scheduling.

The shared native appearance, dashboard structure, ownership boundaries and top-right Settings location remain. Expanded staff/service controls are inside Settings, not duplicated in the sidebar.

## Important compatibility decisions

- **Ten additive tables**, all `gd_plus_*`, reference existing identities and records. No shared-table ALTER/DROP, duplicate identity system, SQLite import, database replacement or record deletion.
- The original Guidance `schema=2` readiness marker remains. A separate restoration marker enables new workspaces only after migration; installing source alone does not enable the new feature set.
- **High is Guidance-only.** Shared `tickets.priority` stores Normal; a Guidance metadata override stores High. A notice on native Guidance ticket pages explains the effective priority/target. Guidance queue/report/job calculations use service targets; generic common screens and the legacy aggregate export retain common defaults. This avoids changing another department’s enum or policy.
- Current service target edits can affect existing Guidance cases because targets are measured from case creation. Review school policy before changing defaults. No true paused-duration accounting or business-hours calendar is claimed.
- The expanded Guidance intake is used by Guidance’s Request/Walk-in controls. The common multi-office campus intake remains available with its original fields. Preferred counselor/time does not reserve an appointment.
- Academic metadata is entered by the Head after verifying a named school source. It is current metadata, not a historical registrar snapshot or automatic sync. Cohort invitation matching requires explicit audience preview/confirmation.
- Waitlist membership is not registration. Staff promotion is first-eligible and capacity-checked; no unapproved automatic promotion is added.
- Review reasons remain staff-only; students see their own transition reasons. Privacy-review responses/history are deliberately shared with their requester.
- Queue CSV excludes student names/IDs, subjects, narratives, replies and internal notes. Aggregate exports suppress cells under five and omit student identity/content. Audit export contains timestamp, staff actor (requester identities withheld) and action. These exports have different purposes; private references and staff names still require appropriate handling.
- Minimum-cell suppression is not protection against all overlapping-query inference. Do not publish staff reports as formally anonymous datasets.
- Legacy records without attendance provenance or notification target IDs are labeled legacy/not recorded or linked to a workspace; no historical facts are fabricated.

## Installation — source first, then additive migration

### Before starting

1. Keep your existing source and earlier backups. Have the shared database owner make and verify a **full shared database + uploads + private-configuration backup** using your established process. The Guidance encrypted subset is not a replacement for full-system recovery.
2. Stop the PHP development server using Ctrl+C in its own terminal. Do not stop/reset/delete the database. Do not run the old Library setup/import scripts.
3. Download the ZIP into Downloads. The example source folder remains `Downloads\COLLAB`; change it only if your actual combined project is elsewhere.

### Apply the guarded source package (PowerShell)

```powershell
$downloads = Join-Path $env:USERPROFILE 'Downloads'
Expand-Archive "$downloads\LCCT-Guidance-Consolidated-Restoration.zip" $downloads -Force
$patch = "$downloads\LCCT-Guidance-Consolidated-Restoration"
$project = "$downloads\COLLAB"

node "$patch\apply.cjs" "$project" --check
if ($LASTEXITCODE -ne 0) { throw 'Source check stopped. Keep your files and request a merge.' }
node "$patch\apply.cjs" "$project"
if ($LASTEXITCODE -ne 0) { throw 'Source update stopped. Keep the server stopped and read the backup message.' }
```

Preflight validates every payload and known source hash, immutable shared anchors and destination paths before writing. Unknown collaborator changes, missing prerequisites, new-file collisions and symlink/junction destinations stop installation. Text CRLF is accepted; Word files use binary hashes. A sibling source backup records actual originals. Exact repeat installation is a no-op. `--check` writes no source or backup.

No database, uploads, private configuration, `.env` or Git data is included in the payload or modified by this source installer.

### Preview the migration

Use your existing PHP executable and INI. Your previously reported paths were:

```powershell
$php = Join-Path $env:USERPROFILE 'AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
$ini = Join-Path $env:LOCALAPPDATA 'LCCT-Guidance-PHP\php.ini'
if (!(Test-Path $php) -or !(Test-Path $ini)) { throw 'Use the PHP and INI paths from your working server command.' }
Set-Location $project
& $php -c $ini guidance/restoration-migrate.php
```

Expected output begins **PLAN ONLY** and lists ten `gd_plus_*` tables. This uses your existing private database settings; no credentials are supplied by the patch. If it reports a connection or schema error, stop and send the output—do not import or reset a database.

### Apply only after your backup is confirmed

```powershell
$confirmed = Read-Host 'After verifying the shared backup, type BACKED-UP to add the Guidance tables'
if ($confirmed -cne 'BACKED-UP') { throw 'No migration requested.' }
& $php -c $ini guidance/restoration-migrate.php --apply
if ($LASTEXITCODE -ne 0) { throw 'Migration stopped. Keep the server stopped and retain all backups.' }
```

Expected completion: **Guidance restoration enabled**. Migration validates existing table columns/types/nullability/primary keys/foreign keys and InnoDB before changes. It is repeatable; existing source data is preserved. MySQL DDL is not transactional: if interrupted, inspect the error and rerun after resolving it, rather than dropping partial tables.

Restart your same PHP server, for example:

```powershell
& $php -c $ini -d upload_max_filesize=3M -d post_max_size=12M -S 127.0.0.1:8081 guidance/dev-router.php
```

Then Ctrl+F5 in the browser. This is local-only; it does not publish a school service to the internet.

## Where to find the features

- **Head → Student directory → student:** record verified academic information.
- **Activity → Audience and attendance policy:** cohort preview, facilitator/late/window settings; below it, provenance, individual certificate and waitlist.
- **Shared Guidance tools → Certificate register / Notifications / Help & services.**
- **Requests → Review / preferences:** authorized review controls/history. Shared request pages also link here.
- **Top-right account → Settings → Team / Services:** profile/reset and category hierarchy/routing/targets. No new sidebar Settings entry.
- **Head → Privacy reviews / Guidance audit.** Requesters see privacy progress and can reply in their own Settings when awaiting information.
- **Head → Shared Guidance tools → Guidance operations:** last job/verified backup status.
- **Ctrl/Command+K:** role-visible Guidance page finder. Escape closes; arrows/Enter navigate. Settings remains header-only.

## Backup scheduling (optional, separate approval to install a task)

The encrypted subset now includes all ten new tables plus their mapped Guidance notification records. Keys are never put in the package, task arguments or UI. Configure the private `LCCT_GUIDANCE_BACKUP_KEY` environment for the account that runs PHP; store the key separately and protect it. Create a private backup folder **outside** the web/project root first.

Run `guidance/schedule-restoration.ps1` with `-ProjectRoot`, `-PhpPath`, `-PhpIni`, and `-BackupDirectory` to perform one Guidance job + backup + verification. Add `-Install -DailyAt '02:00'` only when you explicitly want the daily task installed.

The task is named `LCCT-Guidance-Verified-Subset-Backup`; an existing task with that name is refused, not overwritten. It uses the current user, limited privileges and **Interactive logon**: it runs only when that user is logged on. It is not a 24/7 service and does not store account passwords. Task Scheduler must be tested on your Windows machine with the correct environment and permissions. There is no automatic retention deletion, restore or whole-system backup installation.

## Rollback

1. Stop PHP and disable any new Guidance scheduled task you chose to install.
2. Preserve the full shared backup and the current new-table data. Source rollback does not undo saved actions, password changes, statuses, certificate issuance or migrations.
3. Run:

```powershell
node "$patch\rollback.cjs" 'EXACT-SIBLING-BACKUP-PATH-PRINTED-BY-THIS-INSTALLER'
```

Use your real backup path. Rollback checks current and original hashes before modifying source, refuses later collaborator edits, restores replaced files, and moves newly added files into `retired-additions` inside the backup instead of deleting them. It does not drop tables or erase data. High overrides/extended metadata will not appear in old screens until compatible code is restored. Do not treat an old-version Guidance subset backup as covering the new tables. Full shared restore remains a separately coordinated recovery procedure.

## Verification and remaining acceptance

Final local suites: **1,673 / 1,673 checks** (919 earlier regression assertions, 341 Stage 1 assertions, 413 consolidated-restoration assertions). Prior tests were updated only where the new table count, additional password forms, separate command navigation, or bounded screenshot capture required an accurate assertion target. This is not a claim of full production readiness.

Tests include direct HTTP role/ownership/CSRF/malformed-input checks, original workflows, initial uploaded attachments and download isolation, privacy/resource/aggregate boundaries, cohort preview/idempotency, seat capacity in separate concurrent PHP processes, attendance policy/provenance, individual certificate eligibility, scoped staff reset/session revocation, default plan/repeat/malformed-schema migration handling, encrypted backup verification and tamper/wrong-key rejection, responsive pages at 1366/390/320 px, keyboard palette, unsaved drafts and no-JavaScript read/filter pages. POST actions retain the application's existing AJAX-form behavior.

PHP lint and zero browser JavaScript exceptions are included in the evidence. Installer/rollback checks are separate in `evidence/package.json`. Test data is synthetic only. Some initial test failures came from outdated selectors, an invalid test username, the expected existing CSRF status (419), and overly tall screenshot capture; final evidence is from successful reruns, not ignored failures.

**Not executed:** real Windows Task Scheduler registration, physical camera use, production load testing, external email delivery, full-system restore rehearsal, old-data migration, shared self-service recovery policy changes, 2FA/hardware binding, or school UAT/approval. No remote push or deployment.
