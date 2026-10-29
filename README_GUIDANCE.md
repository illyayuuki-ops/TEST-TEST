# LCCT ServiceDesk — connected Guidance contribution

**Review build for TEST-TEST commit `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`.**

This is architecture A: one shared PHP application, login and MySQL database. It is not a separate portal and is not the earlier Library-based contribution. The common shell retains the updated source's branding and layout.

## What is connected

- Existing `users`, `offices`, `categories`, `tickets`, `replies`, `ticket_files`, notification bell and login remain canonical.
- A student creates a Guidance ticket with the original `student/new_ticket.php?office=guide` form. Guidance staff open that same `ticket.php?id=...` record. There is no second ticket database.
- Guidance head sees Guidance cases; counselors see assigned cases; the student sees their own. The activity administrator does **not** get clinical-case access merely for being an administrator.
- Walk-ins create real shared tickets for the selected student. Appointments, activities/programs, invitations/RSVP, QR/manual attendance, certificates/credits, knowledge, aggregate reports, settings and privacy review extend the shared records.
- Student Affairs retains its original staff access and has regression coverage. Other-office staff are **not** re-enabled by this contribution; that restriction already exists in this source.
- An additive migration creates **14** `gd_*` tables. It does not replace shared accounts or tickets.

## Apply the update on Windows

Do not use the old `LCCT-Guidance-Contribution.zip` or old SQL files. Keep source, data, `.env`, private configuration and recovery backups.

1. Download `LCCT-Guidance-Connected-Update.zip`.
2. Open PowerShell **inside the updated TEST-TEST source folder**, the one containing `auth`, `config`, `student`, `ticket.php` and `database/ticketdesk_hosting.sql`.
3. Run:

```powershell
$zip = Join-Path $env:USERPROFILE 'Downloads\LCCT-Guidance-Connected-Update.zip'
$bundle = Join-Path $env:USERPROFILE 'Downloads\LCCT-Guidance-Connected-Update'
Expand-Archive -LiteralPath $zip -DestinationPath $bundle -Force
node (Join-Path $bundle 'apply.cjs') (Get-Location).Path
```

The Node helper only checks and copies source files. It verifies base-file hashes, refuses incompatible or locally changed files, creates a **sibling backup**, never deletes your source/data and never connects to a database. A second identical application is a no-op. If it stops on a conflict, keep that file and arrange a merge; do not force an overwrite. Node 24 is fine. No execution-policy change or `.cmd` wrapper is needed.

## Fresh local review database — optional, isolated

Use this only for a new synthetic review database, not an existing campus database. Start Laragon's MySQL service first. The setup tool connects only to `127.0.0.1` as local root, creates a **new** `_test` database and a random application-only database account, then writes ignored `config/local.php`. It refuses an existing database or existing private configuration. It extracts only CREATE TABLE definitions from the base SQL, never the original seed accounts or deletion scripts.

Use your previously verified PHP executable and separate PHP INI. This block locates PHP on PATH, then Laragon as a fallback:

```powershell
$phpCommand = Get-Command php.exe -ErrorAction SilentlyContinue
$php = if ($phpCommand) { $phpCommand.Source } else {
    Get-ChildItem 'C:\laragon\bin\php' -Filter php.exe -Recurse -ErrorAction SilentlyContinue |
        Sort-Object FullName -Descending | Select-Object -First 1 -ExpandProperty FullName
}
if (-not $php) { throw 'Locate the PHP executable you previously verified before continuing.' }
$ini = Join-Path $env:LOCALAPPDATA 'LCCT-Guidance-PHP\php.ini'
$phpArgs = @()
if (Test-Path $ini) { $phpArgs = @('-c', $ini) }
& $php @phpArgs --version
& $php @phpArgs guidance/setup-local.php
if ($LASTEXITCODE -ne 0) { throw 'Setup stopped safely. Read the message; do not delete an existing database.' }
& $php @phpArgs -S 127.0.0.1:8081 guidance/dev-router.php
```

Open **http://127.0.0.1:8081**. Student logins land on the shared dashboard; select **Guidance services**. Guidance staff land on the Guidance workspace.

If local root uses a password, set `LCCT_LOCAL_ROOT_PASSWORD` in this PowerShell process before setup; do not put it in Git or a screenshot. The default local MySQL port is 3306; use `LCCT_LOCAL_MYSQL_PORT` if yours differs. If the proposed database already exists, choose a different new name with `LCCT_LOCAL_TEST_DB`, ending in `_test`. The tool never drops a database.

### Synthetic logins

All seven fixture accounts initially use **`GuidanceDemo2026!`**, solely for the local review database.

| Username | Review purpose |
|---|---|
| `student_one` | Student owner and participation |
| `student_two` | Different student / isolation checks |
| `guide_head` | Guidance head, assignment and team |
| `guide_counselor` | Assigned counselor |
| `guide_other` | Different counselor / isolation checks |
| `guide_admin` | Activity administrator, not a clinical-case reader |
| `sao_demo` | Existing Student Affairs workflow |

The private local configuration persists synthetic mode: no outgoing mail, visible review banner and local `_test` database guard. Do not convert the fixture database into production. On later starts, run only the final PHP server command; do not run setup again.

### Same-Wi-Fi review

For synthetic review only, bind PHP to `0.0.0.0:8081` instead of `127.0.0.1`. Allow the port only on a trusted Windows **Private** network and open `http://YOUR-PC-LAN-IP:8081` on the other device. Never point that device at its own `localhost`. Do not port-forward this development server. Camera access normally requires HTTPS or localhost; manual code entry and manual attendance remain available on plain-HTTP LAN access. Real counseling records require a hardened HTTPS deployment, not this server.

## Existing shared database — coordinator-controlled integration

Do **not** run the synthetic setup against existing data. Back up the complete shared database and uploads, stop writes, then configure `config/local.php` or `LCCT_DB_HOST`, `LCCT_DB_PORT`, `LCCT_DB_NAME`, `LCCT_DB_USER`, `LCCT_DB_PASSWORD`. Local config overrides environment values. This contribution removed inline database/mail credentials; the owner should rotate previously published credentials and review Git history separately.

```text
php guidance/migrate.php
php guidance/migrate.php --grant-head EXISTING_GUIDANCE_ADMIN_USERNAME
```

The head must already be an active shared `users` row with `role=admin`, `office_code=guide`; enrollment does not silently convert a Student Affairs account. The campus application owner must provision that initial shared account if none exists. Later Guidance staff can be created by the head in **Guidance team**. They use the same shared login and must change the initial password. Existing sessions need a new sign-in after the first migration.

This is a review release: complete owner approval, MySQL 8.4/Laragon smoke tests, real-device UAT, HTTPS configuration and a full shared-system recovery rehearsal before live use. No production deployment or remote push was performed.

## Operations

- `php guidance/jobs.php` previews expired session/rate cleanup and overdue Guidance escalation. Add `--apply` to apply it. Schedule it using Windows Task Scheduler or cron; no schedule is installed automatically. No clinical records are automatically erased. Shared priority targets remain Low 120h, Normal 72h, Urgent 24h.
- `php guidance/backup.php keygen` generates a private base64 key. Store it separately and set `LCCT_GUIDANCE_BACKUP_KEY` for backup commands.
- `php guidance/backup.php create /PRIVATE/OUTSIDE-WEBROOT/snapshot.lcctgd` creates an authenticated encrypted Guidance subset including its attachments.
- `php guidance/backup.php verify /PRIVATE/OUTSIDE-WEBROOT/snapshot.lcctgd` checks encryption integrity and attachment checksums. It is **not** a full-system restore rehearsal. Shared user references exclude passwords and active sessions. Full restore needs the combined application's database owner.

Do not commit private configuration, uploads, backup keys, database dumps, runtime data or test output. GitHub is source storage, not deployment or a database backup.

## Evidence and remaining scope

See `guidance/docs/SIX-PHASE-GUIDANCE.md` and its `.docx` companion, `PARITY.md`, `INTEGRATION.md`, `VERIFICATION.md` and `UAT.md`.

The review is not a claim that every old feature has already moved. Cohort/audience-driven invitations and recurrence automation, coordinator-approved full shared-system restore/retirement, configurable SLA policies/advanced reporting and advanced authentication remain gaps. Hardware-bound authentication and 2FA are not implemented. Existing SQLite records were not automatically imported.
