# Guidance usability and password consistency patch

**Date:** 29 October 2026. **Baseline:** corrected TEST-TEST `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`, plus the installed Guidance Connected Update and Guidance Style Fix.

This is an incremental Guidance contribution, not a replacement application or campus-wide authentication redesign. Keep the shared PHP source layout, colors, cards, responsive tables, query-aware navigation and department boundaries.

## What changed

- Shorter navigation labels and matching page titles: Overview, My requests/Requests, Appointments, Activities, Programs, Calendar, Certificates & credits, Resources and My settings.
- Role-specific overview shortcuts: students request support/book/check activities; counselors review assigned cases and appointments; operational administrators manage activities/programs and see only their already-authorized aggregate reports. No additional clinical permission is granted.
- Helpful next steps for empty lists, appointment examples, Philippine-time instructions, attachment/privacy guidance and activity/program instructions. Programs group individually scheduled sessions; this does not add automatic recurrence.
- Action-specific success messages after redirects, a Saving indicator, accessible validation feedback and retained ordinary inputs on failed AJAX saves. Existing draft-safe refresh remains.
- Guidance password forms use the earlier TypeScript application's control pattern: show/hide per field, a requirements checklist, a heuristic strength estimate and confirmation feedback. Shared-source visual styling is retained rather than copying a separate React theme.

## Exact password scope and policy

Applies to **Guidance staff creation**, **authenticated Guidance staff password changes**, and **student password changes opened from Guidance** (`auth/change_password.php?from=guidance`). These update the same shared campus account, not a separate Guidance credential.

- 12–128 Unicode code points; at least one ASCII uppercase letter, lowercase letter and digit. Symbols are optional. Client and server count code points consistently; no trimming or normalization.
- Current password is required for a password change; new password must differ and confirmation must match. New Guidance staff must change their temporary password before accessing the application.
- Plaintext passwords are not put in logs, audit entries, browser storage, session drafts, or returned HTML values. The full-page password-change error clears all password fields. AJAX staff-create errors retain the current in-page form, but do not persist it in storage.
- New hashes use **Argon2id**. PHP's current bcrypt default truncates at 72 bytes, so using it for a 128-character policy would not faithfully protect the full password. No existing hash is migrated; native `password_verify()` continues to support existing bcrypt hashes.
- The active PHP runtime must support Argon2id. Run the supplied `check-php.php` using the **same PHP executable and INI as your application**. It checks the algorithm and a long-password verification round trip without connecting to a database. Unsupported servers fail closed with an actionable error; there is no weaker fallback. Windows support has not been verified on the user's machine.
- Authenticated change: shared CSRF check, 10 attempts per 15 minutes per account, compare-and-swap update inside the Guidance transaction, recovery-token invalidation, removal of all that account's shared session records, audit entry without password values, then shared sign-in confirmation. Revocation applies on the next request from another device; it cannot erase a page already displayed there.

**Not a campus-wide password-policy migration:** other departments' native registration, recovery and ordinary password forms retain their existing policy and code paths. Students entering the ordinary campus password route instead of Guidance still receive that native form. This patch does not promise a globally enforced minimum of 12 characters on every campus workflow. A campus-wide policy requires agreement with the other collaborators.

## Preservation and installation

No schema migration or database bootstrap is needed **for this patch**. It does not initialize an unconfigured application. The user's corrected Windows database/configuration setup was not confirmed at packaging time; installation does not establish that it is working.

The guarded installer validates the installed style-release baseline, package hashes and shared-source anchors, refuses unknown local edits, backs up replaced source files to a sibling `COLLAB-pre-guidance-polish-...` directory, then copies only the manifest paths. Running it twice is a no-op. It does not connect to MySQL, run PHP migrations, change Git history, or touch private config, uploads, databases, `.env`, old backups or unrelated department pages. Stop the PHP server while applying and restart the existing configured application afterward; press Ctrl+F5.

Keep all earlier backups and this new backup. Source backups are not database backups. No remote push or deployment has been performed. Do not reapply the initial Connected Update over the installed style/polish release. If the installer reports a conflict, retain everything and request a merge instead of forcing it.

## Verification

All checks below passed on synthetic local accounts in Linux PHP 8.4.27, MariaDB 11.8.6 and headless Chrome. Browser tests block external resources; shared fonts/icons still depend on the source application's external assets when online.

| Suite | Passed |
|---|---:|
| Existing HTTP integration | 133/133 |
| Existing browser workflow and draft protection | 188/188 |
| Shared-source style and navigation | 48/48 |
| Password HTTP/security integration | 57/57 |
| Usability/password browser checks | 45/45 |
| Password boundary/hash unit checks | 24/24 |
| Existing domain/core checks | 30/30 |
| Existing migration/jobs/encrypted-backup checks | 15/15 |
| **Application total** | **540/540** |

PHP lint and JavaScript syntax checks passed; browser exception lists were empty. Screens checked at 1366, 390 and 320 pixels. New checks cover authorization/CSRF, unchanged non-Guidance forms, forced change, rejection of invalid passwords, confirmation, preserved spaces, 128 Unicode characters, significance of characters beyond byte 72, reset-token invalidation, old-device revocation, audit secrecy, rate limiting, visible success feedback, retained ordinary inputs and accessible controls. A separate package-safety report accompanies the installer.

Not verified here: the user's Windows runtime/Argon2id support, actual corrected local database bootstrap, real email, Apache/nested mounts, production load or real camera hardware. No hardware binding, 2FA, automatic program recurrence or full backup restore is added by this patch.
