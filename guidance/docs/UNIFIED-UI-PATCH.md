# Shared student-style UI for every Guidance role

**30 October 2026 — incremental patch after Guidance Polish.**

## Approved reference and scope

The user's student dashboard screenshot is the visual reference for students, Guidance Head, Counselor and Guidance Administrator. Reuse the shared source's sidebar, campus-photo hero, navy/blue/white/gold palette, typography, rounded cards, dashboard grid and header account dropdown. Role-specific information and authorization still differ. Account/settings functions belong inside Settings instead of being repeated as sidebar entries.

## Changes

- Guidance dashboards now use the same native `.hero`, `.stats`, `.dash`, `.card`, `.tk-card` and recent-updates panel structure as the common student dashboard. The native shared stylesheet is unchanged.
- All three Guidance staff roles now have the same top-right account dropdown pattern as students, including an accurate role label. The duplicate staff account block at the sidebar bottom is removed for Guidance staff only.
- Student, Head and Counselor overview cards show only the already-authorized requests. Activity administrators see an activity workspace and activity/program statistics rather than clinical case cards. No authorization query, permission or database schema is broadened.
- Search/status filters operate only on the already-authorized rendered list. Clear and no-match states are included; no new network request or storage is used.
- One **Settings** sidebar entry replaces standalone **Profile** and **Password** links for students and Guidance staff. The account dropdown links to Settings and Sign out; profile/password actions are inside the Settings pages.
- The shared student portal gets `student/settings.php`, linking to its existing profile editor, existing native password form and Guidance preferences when the extension is ready. The original student dashboard/profile logic is unchanged.
- Guidance Settings retains notification preferences, privacy review, own-data export and session revocation, and links to profile editing for students and Guidance password changes for all authorized users.
- The Head's Team and Services pages are linked inside Settings instead of duplicated in the sidebar. They retain head-only authorization. Team, Services and password subpages highlight Settings.
- Other departments' staff dashboard/menu behavior, registration, recovery, passwords and permissions remain unchanged. The common student shell intentionally changes its account navigation for all students; this is not an isolated alternate student account.

## Password-policy boundary retained

No password/security policy is changed by this UI patch. Guidance-entry password changes and Guidance staff creation retain the preceding 12–128-character uppercase/lowercase/number policy and Argon2id hashing. The ordinary campus student password route retains its native policy. Consolidating navigation does not turn this into a campus-wide password-policy migration.

## Installation safety

Requires corrected TEST-TEST `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0` plus the installed Connected Update, Style Fix and 16-file Guidance Polish patch. This package updates **11 source/test/documentation files**.

Stop the PHP server before applying. The installer checks hashes and prerequisites, refuses unknown collaborator edits before copying, creates a sibling `COLLAB-pre-guidance-unified-ui-...` source backup and does not change databases, private configuration, uploads, environment files or Git history. Reapplying the exact patch is a no-op. No database setup or migration is needed. Restart the configured server, keep MySQL running and press Ctrl+F5.

Keep all earlier backups. Source backups are not database backups. Do not run older packages over this release or force an overwrite when a merge is required. No remote push/deployment has been performed.

## Verification

Synthetic local Linux PHP 8.4.27 / MariaDB 11.8.6 / Chrome 148. No real school records used.

| Suite | Passed |
|---|---:|
| HTTP integration | 133/133 |
| Existing browser workflows and draft protection | 188/188 |
| Shared-source style and query navigation | 48/48 |
| Password HTTP/security | 57/57 |
| Password/usability browser | 45/45 |
| New all-role UI and Settings navigation | 169/169 |
| Password unit/boundary checks | 24/24 |
| Domain/core | 30/30 |
| Migration/jobs/encrypted backup | 15/15 |
| **Application total** | **709/709** |

PHP lint and overview JavaScript syntax passed. Browser exception lists were empty. Each role was checked at 1366, 390 and 320 pixels. Checks include a single Settings entry, no profile/password sidebar duplication, role labels, header menu operation, filters, empty states, Settings subpage highlighting, head-only management, other-office isolation and direct-access protection of the overview partial. Two existing test expectations were updated from the removed Password navigation entry to the new Settings entry; their security checks remain intact.

Desktop/mobile screenshots and a separate installer-safety report are included under `evidence/`. External fonts/icons are blocked during browser tests, so screenshots use fallback fonts/icons; the source's existing CDN dependencies remain unchanged.

The previous Polish installer and Argon2id check passed on the user's Windows machine. A later screenshot shows the student dashboard rendering, but this new patch has not yet been installed or visually accepted there. Apache/nested mounts, real email, hardware camera use and production load remain unverified.
