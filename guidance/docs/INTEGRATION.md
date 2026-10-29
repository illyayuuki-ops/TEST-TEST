# Shared-source integration contract

Base: TEST-TEST `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`, not the earlier Library branch. Contribution scope: Guidance only, architecture A. Working branch: `feature/guidance-restart`; no remote push.

## Canonical records and boundaries

```text
Shared login / users / offices
              |
        shared PHP session ---- gd_sessions + fresh account/membership check
              |
      +-------+-----------------------+
      |                               |
Student / SAO shared routes      Guidance workspace
      |                               |
      +-- tickets / replies / files --+  (office_code = guide)
                                      |
              gd_staff / case_meta / appointments
              programs / events / invites / certificates
              articles / preferences / feedback / privacy
              rate / settings
```

The database remains one shared MySQL-compatible schema. There is no duplicate Guidance identity, ticket, reply or attachment store. The encrypted export is a backup format, not a second live database.

## Shared files touched

| File | Integration responsibility |
|---|---|
| `config/app.php` | Private env/local configuration, PDO port, safe cookies, bridge, scoped Guidance navigation/role handling, case transition guard, exclude Guidance from no-reply auto-close |
| `auth/login.php` | Keep SAO staff; admit explicitly enrolled Guidance staff; login rate checks and hashed session registration |
| `auth/logout.php`, `auth/change_password.php` | Session revocation / password-change stamp |
| `student/new_ticket.php` | Add metadata for real shared Guidance tickets |
| `ticket.php` | Owner/head/assigned-counselor check; head-only assignment; eligible staff list; shared Guidance write lock |
| `file.php` | Same case guard before native attachment authorization |
| `includes/layout.php` | Guidance menus, shared student navigation, draft-safe polling, local SVG mobile/bell controls |
| `includes/mail.php` | Generic authorized in-app Guidance updates; no external Guidance clinical message email; disable all external mail in synthetic mode |
| `includes/audit.php` | Redact generic Guidance audit descriptions |
| `includes/notifications.php` | Avoid lazy notification-table DDL during module transactions |
| `.htaccess`, `.gitignore` | Protect local/private files; exclude uploads and secrets; retain public QR vendors |

The source's SAO controllers were not replaced. Its restriction on other-office staff login is retained. Do not interpret the common application's 12 office profiles as evidence that all departments have implemented staff workflows.

## Additive schema — 14 tables

| Table | Purpose |
|---|---|
| `gd_staff` | Shared-user membership: head, counselor, administrator |
| `gd_sessions` | Hashed shared session identifiers and eight-hour expiry |
| `gd_case_meta` | Shared ticket creator / walk-in metadata |
| `gd_preferences` | Guidance ticket-update preference |
| `gd_feedback` | Owner feedback on resolved/closed cases |
| `gd_programs` | Program groups for separately authored activity sessions |
| `gd_events` | Activities, capacity, schedule, check-in window, certificate policy |
| `gd_invites` | Unique student/event invitation, RSVP, attendance and pass |
| `gd_certificates` | Immutable issued participation snapshots and credits |
| `gd_appointments` | Requester/counselor appointments with overlap checks |
| `gd_articles` | Draft/published Guidance resources |
| `gd_privacy` | Review requests and head responses; no automatic erasure |
| `gd_rate` | Persistent hashed rate buckets |
| `gd_settings` | Schema marker and operational timestamp |

Foreign keys reference the original integer `users.id` and `tickets.id`. `gd_settings.schema=2` enables the bridge after installation. InnoDB is required. Migration is additive/idempotent, but MySQL DDL is not transactionally rolled back: use a maintenance window and a full pre-migration backup. Review a partially failed migration before retrying.

## Authorization

| Capability | Student | Assigned counselor | Guidance head | Guidance activity administrator | SAO / unrelated office |
|---|---|---|---|---|---|
| Read confidential case | Own | Assigned | All Guidance | No automatic access | No |
| Read internal note/file | No | Assigned case | Guidance case | No | No |
| Reassign case | No | No | Yes | No | No |
| Book appointment | Own | Not on behalf of a student | Not on behalf of a student | No | No |
| Confirm/complete appointment | No | Assigned appointment | Yes | No | No |
| Manage Guidance activities | No | Yes | Yes | Yes | No |
| RSVP / self check-in | Own invitation | No student impersonation | No student impersonation | No student impersonation | No |
| Aggregate reports | No | Yes | Yes | Yes | No |
| Guidance membership/categories/privacy audit | No | No | Yes | No | No |

Staff walk-in creation uses the selected student's shared account as ticket owner; it never makes the creator the confidential-case owner. A counselor's walk-in is assigned to that counselor. Last active head and outstanding clinical assignment/appointment safeguards protect membership changes.

## Routes and action contract

- `guidance/index.php?view=...`: shared shell, authenticated, server-rendered role-scoped pages.
- `guidance/action.php`: POST only; shared `csrf` and `action`, validated values; `{ok,message,redirect,id}` or error. Guidance writes are transactional and take the same database advisory lock as native Guidance ticket writes.
- `guidance/state.php`: authenticated signature only, no case content; three-second visible-tab polling.
- `guidance/export.php`: own Guidance records JSON; `type=reports` staff-only aggregate CSV.
- `guidance/certificate.php?id=...`: owner/Guidance-staff authenticated print view; not public verification.
- `guidance/migrate.php`, `setup-local.php`, `jobs.php`, `backup.php`: CLI only, also blocked by router/Apache rules.

Action families: `walkin`, `feedback`, `appointment.create`, `appointment.update`, `program.save`, `event.save`, `invite`, `rsvp`, `checkin`, `attendance`, `certificates`, `article.save`, `category.save`, `staff.create`, `staff.save`, `preferences`, `sessions`, `privacy.submit`, `privacy.review`.

Manual attendance and QR check-in use the same server rules. No certificate is issued until a certificate-enabled activity is completed and a registered participant is present/late. Duplicate issuance is prevented. Issuance locks conflicting event/attendance edits. No certificate revocation/correction workflow is provided yet.

## Runtime, security and operations

PHP 8.1+; PDO MySQL, mbstring, fileinfo and sodium; MySQL-compatible InnoDB. Locally executed on PHP 8.4.27/MariaDB 11.8.6. MySQL 8.4 and Apache production behavior still require coordinator verification. The Node helper is a file installer only; Node is not the web backend.

Shared session cookies are HttpOnly/SameSite=Lax and Secure when PHP sees HTTPS. Correctly configure a trusted HTTPS reverse proxy; do not trust arbitrary forwarded headers. Session tokens are hashed at rest, expire after eight hours, and are checked against current account/membership/password state. This is not hardware/device-bound authentication. Shared registration/password-change minimum remains the base eight-character policy; new Guidance staff creation requires at least twelve characters and an initial password change. Review institution-wide password policy before production.

Login limits: 1,000 requests/source and 10/identifier per 15 minutes, plus the base account lockout. Module writes: 300/user per 15 minutes. Campus-NAT and sustained-load behavior are not yet validated. Polling/signature queries are not a demonstrated large-school scalability design.

Aggregate counts below five and small-group time averages are suppressed; no case narrative or student identity is included in reports. This is not a formal differential-privacy guarantee or a legal compliance certification.

Private attachment access remains through `file.php`; native MIME/size checks remain. Direct uploads/config/database/internal paths are blocked. Antivirus scanning and encrypted live database storage are not implemented by this contribution.

The development router is required with PHP's built-in server, which ignores `.htaccess`. Do not use the development server for real counseling data or expose it to the internet. Apache must enable the relevant access controls and rewrite rules; verify them before live use.

## Collaboration and rollback

Coordinate the eleven edited shared PHP files as one integration patch. Each colleague owns their department; do not replace their controllers. The applier refuses changed base files rather than force-merging.

Before a real integration: full shared database + uploads backup, maintenance window, credential rotation, private environment configuration, initial head enrollment, migration, security/regression checks and stakeholder approval. Existing sessions must re-sign-in once.

Rollback requires a stopped server and coordinator review. Restore source from the sibling backup as appropriate, but do not blindly restore the original inline-credential configuration or start it against an unintended remote database. Additive Guidance tables should remain until the data owner approves any removal. Do not drop shared tables. A full recovery must reconcile the entire shared database and upload storage; the Guidance encrypted subset is not a standalone identity/database restore.
