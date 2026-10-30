# Role-focused sidebar with collapsible shared tools

**30 October 2026. Apply after Sidebar Cleanup.**

## Approved change

The user requested grouping similar tools into a collapsed sidebar section, while leaving each role's distinctive responsibilities outside it. They explicitly chose **sidebar only**, not dashboard sections or tabs.

The dashboard markup, cards, statistics, filters, data queries and permissions are unchanged. Settings remains in the top-right account dropdown, not the sidebar.

## Sidebar structure

All Guidance workspaces keep Overview at the top and show a role-labelled “My work” heading.

| Role | Main work, outside the collapsed group |
|---|---|
| Student in Guidance | My requests, My appointments, Certificates & credits |
| Counselor | Assigned requests, My appointments |
| Guidance Head | Requests & assignments, All appointments, Privacy reviews, Guidance audit |
| Guidance Administrator | Activity operations |

**Shared Guidance tools** contains Programs, Calendar and Resources; Activities for students/counselors/heads; personal Certificates & credits for staff; and Aggregate reports for authorized staff only.

The administrator's Activities route is promoted to **Activity operations** outside the group rather than duplicated inside it. It opens the existing activity workspace, from which invitations, attendance and certificates are managed. This does not make activity work exclusive to that role, or grant anyone new access. Staff can still use their existing shared activity tools.

The Head's Team and Services links remain inside Settings. Settings, Profile and Password do not reappear in the sidebar. The common campus student dashboard and other departments' staff navigation remain unchanged; the grouped student menu applies when using the Guidance workspace.

## Disclosure behavior

- Collapsed by default on Overview and role-specific pages.
- Automatically opens when the selected page is a child of the group, so the active link is visible. Activity details map to Activities for counselors/heads/students; they map to the administrator's visible Activity operations link for that role.
- Click, Enter and Space toggle the native HTML `details`/`summary` control. It works without JavaScript.
- Uses a scoped sidebar stylesheet and the existing shared visual tokens. No dashboard/global theme overrides.
- No persistence, cookies, browser storage or additional network API calls are used for the group state. Navigating to a primary page restores the default collapsed state.
- Hiding or grouping a link is not an authorization mechanism: existing endpoints retain their access checks.

## Patch boundaries

Seven payload files: two existing shared navigation PHP files, one new scoped sidebar stylesheet, two adjusted existing browser tests, one new sidebar test suite and this note. The two browser tests now explicitly target dashboard form disclosures rather than accidentally selecting the new sidebar disclosure.

Requires corrected TEST-TEST plus Connected Update, Style Fix, Guidance Polish, Unified UI and Sidebar Cleanup. The guarded installer validates exact prerequisites, rejects unknown edits before copying, creates a sibling `COLLAB-pre-guidance-role-sidebar-...` source backup and is idempotent. It does not change databases, private configuration, uploads, `.env`, Git history or earlier backups. No migration or database setup is needed. Stop PHP while applying, keep MySQL running, restart PHP and use Ctrl+F5 afterward. Source backups are not database backups.

## Verification

Synthetic local Linux PHP 8.4.27 / MariaDB 11.8.6 / Chrome 148. No real school records.

| Suite | Passed |
|---|---:|
| HTTP integration | 133/133 |
| Existing browser workflows/draft protection | 188/188 |
| Shared style/navigation | 48/48 |
| Password HTTP/security | 57/57 |
| Password/usability browser | 45/45 |
| All-role UI and header Settings navigation | 174/174 |
| New grouped sidebar | 205/205 |
| Password boundary/hash checks | 24/24 |
| Domain/core | 30/30 |
| Jobs/migrations/encrypted backup | 15/15 |
| **Application total** | **919/919** |

PHP lint passed; browser JavaScript exception lists were empty. New checks cover all four roles at widths 1366, 390 and 320; collapsed defaults, visible role work, no duplicate destinations, active-child expansion, actual navigation, keyboard toggle, no-JavaScript operation, head-only access, Settings exclusion and unrelated-office preservation. Source hashes separately verify that the Guidance dashboard, overview partial, dashboard stylesheet and domain logic were not changed.

Screenshots use synthetic accounts and block external fonts/icons. The source's existing CDN dependencies remain unchanged. Installer-safety results are packaged separately. Windows installation and visual acceptance of this new patch remain pending; earlier patch installations do not establish that this update is installed.
