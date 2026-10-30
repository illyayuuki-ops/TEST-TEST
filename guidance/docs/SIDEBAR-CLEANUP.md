# Settings in the header only — sidebar cleanup

**30 October 2026. Requires the installed Unified UI patch.**

## Updated user instruction

Remove the Settings entry itself from student and Guidance staff sidebars, not merely the individual Profile/Password shortcuts. This supersedes the earlier Unified UI instruction to keep one Settings entry in the sidebar.

## Scope

Only three navigation-array lines change at runtime, across two existing files:

- `includes/layout.php`: remove the student sidebar Settings link and its now-empty Account heading.
- `includes/guidance.php`: remove the Settings link shared by Student Guidance, Guidance Head, Counselor and Guidance Administrator menus.

The top-right account dropdown still contains **Settings** and **Sign out**. Open Settings there. The student profile and password controls remain in Settings; the Guidance Head's Team/Services links remain inside Guidance Settings with their existing authorization. No settings page, feature, role permission, password policy, database record or session is removed. Work menus such as Requests, Appointments, Activities and Reports are unchanged. Other departments' staff menus are unchanged.

Settings and account subpages have no highlighted sidebar link, because they are no longer sidebar destinations. Existing header links and direct URLs still work. The current synthetic tests were updated to assert this intentional navigation behavior instead of expecting the old sidebar Settings entry.

## Package and safety

The package contains **7 files**: 2 runtime files, 4 updated regression tests and this note. It layers on corrected TEST-TEST plus Connected Update, Style Fix, Guidance Polish and Unified UI. It is not a replacement source tree.

The installer verifies the baseline and payload hashes, refuses unknown edits before copying, and creates `COLLAB-pre-guidance-sidebar-...` with original source files. It does not touch MySQL, private config, uploads, `.env`, Git or earlier backups. No migration or database setup is needed. Stop PHP while applying; keep MySQL running; restart the existing configured PHP server and use Ctrl+F5 afterward. Do not force a merge conflict or apply older packages over this one.

## Verification

Final synthetic Linux PHP 8.4.27 / MariaDB 11.8.6 / Chrome 148 run:

| Suite | Passed |
|---|---:|
| HTTP integration | 133/133 |
| Existing browser workflows/draft protection | 188/188 |
| Style/navigation | 48/48 |
| Password HTTP/security | 57/57 |
| Password/usability browser | 45/45 |
| All-role UI/navigation, including header-to-Settings clicks | 174/174 |
| Password boundaries/hashing | 24/24 |
| Domain/core | 30/30 |
| Migration/jobs/backup | 15/15 |
| **Application total** | **714/714** |

PHP lint passed; browser exception lists were empty. Desktop/mobile widths: 1366, 390 and 320 pixels. Tests verify there are no Settings/Profile/Password sidebar links for students and Guidance staff, header Settings links remain usable, staff management remains head-only, and other-office staff navigation remains unchanged. Separate installer-safety results and screenshots are in the package's evidence folder.

Windows installation of this small patch is pending. Earlier Windows Unified UI installation succeeded; that is not evidence that this later patch is already installed. Browser tests block external fonts/icons; the application's existing CDN dependencies remain unchanged.
