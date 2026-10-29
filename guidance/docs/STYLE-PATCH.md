# Guidance shared-source style correction

Date: 29 October 2026. Applies **after** the 53-file LCCT-Guidance-Connected-Update on TEST-TEST base `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`.

## Corrected

- Reuses the common `dash_hero()` campus-photo banner, gold primary action and last-ticket-update panel.
- Reuses native `.stats` / `.stat` cards, color accents and icon positions.
- Removes the separate Guidance palette/card/form-heading styling; native cards and standard fields now inherit the common theme.
- Number and date-time inputs get the same dimensions and styling as the source's card fields (the base CSS did not cover those input types).
- Reuses `.table-wrap`, `table.stack` and responsive `data-label` cells. At mobile widths, rows stack instead of requiring a wide table.
- Keeps search filtering effective on mobile stacked rows.
- Removes redundant top tabs; every section remains reachable through the shared sidebar/mobile menu.
- Correctly highlights query-string views. Activity details highlight Activities; walk-in intake highlights Requests. Non-Guidance route matching is unchanged.
- Adds a stylesheet version to prevent a previously cached Guidance CSS file from hiding the update.
- Keeps reduced-motion handling, QR display, camera/manual fallback, live polling and draft protection.

The native `assets/style.css`, original student/SAO dashboards, authentication, database configuration, domain/actions, migrations and permission helpers are not changed by this patch. The official seal remains in the shared shell; the separate spinning-hero treatment is removed to match the chosen shared-source reference.

Google Fonts and Font Awesome still follow the shared source's external loading approach. This patch does not promise offline font/icon delivery; tests deliberately block those external requests and verify usable layout/fallbacks. Missing external fonts/icons on a specific PC require a separate network/resource check.

## Scope

Three runtime files: `guidance/index.php`, `guidance/assets/guidance.css`, `includes/layout.php`. Also includes the adjusted browser test, a focused presentation test and this note. No SQL execution or database migration is required. Existing accounts, tickets, uploads and private `config/local.php` are not changed.

## Apply on the user's current PC

Download `LCCT-Guidance-Style-Fix.zip` into Downloads. Stop the PHP web server if it is running (Ctrl+C in its server window); leave MySQL and its data alone. Run this in another PowerShell window:

```powershell
& {
    $ErrorActionPreference = 'Stop'
    $project = 'C:\Users\User\Downloads\COLLAB'
    $zip = Join-Path $env:USERPROFILE 'Downloads\LCCT-Guidance-Style-Fix.zip'
    if (-not (Test-Path -LiteralPath $zip)) { throw 'Download the style-fix ZIP into Downloads first.' }
    if (-not (Get-Command node.exe -ErrorAction SilentlyContinue)) { throw 'Node is not available in this window.' }
    $folder = Join-Path $env:USERPROFILE ('Downloads\Guidance-Style-Fix-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    Expand-Archive -LiteralPath $zip -DestinationPath $folder
    & node.exe (Join-Path $folder 'apply.cjs') $project
    if ($LASTEXITCODE -ne 0) { throw 'Patch stopped. Send the output; do not force an overwrite.' }
}
```

The installer validates the previously installed version, refuses unknown changes and backs up replaced files to a sibling `COLLAB-pre-guidance-style-...` folder. An identical second application is a no-op. Keep the backup. Do not reapply the older full contribution over this patch.

If local database setup has already succeeded, restart using the same PHP executable/INI and the Guidance development router. Do **not** rerun setup or import SQL for a style change. If the database is not configured yet, this patch can still be applied, but it does not complete database setup.

```powershell
& {
    $ErrorActionPreference = 'Stop'
    Set-Location 'C:\Users\User\Downloads\COLLAB'
    if (-not (Test-Path '.\config\local.php')) { throw 'Local database configuration is not present. Finish the separately instructed database setup first.' }
    $php = Join-Path $env:LOCALAPPDATA 'Microsoft\WinGet\Packages\PHP.PHP.8.4_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe'
    $ini = Join-Path $env:LOCALAPPDATA 'LCCT-Guidance-PHP\php.ini'
    & $php -c $ini -d upload_max_filesize=3M -d post_max_size=12M -S 127.0.0.1:8081 guidance/dev-router.php
}
```

Open `http://127.0.0.1:8081/guidance/index.php` and press Ctrl+F5. Keep the server window open. The synthetic review banner is intentional, not a missing style. If the patch reports a conflict, preserve the changed file and send the message for a merge.

## Verification

Synthetic local PHP/MariaDB/Chrome only; not a claim that a patch was already installed or tested on the user's PC. Regression suites: HTTP 133, browser 188, core 30, operations 15. Additional presentation suite: 48 assertions, including query navigation, native component styling, cache version, responsive labels, mobile search, reduced motion and unchanged SAO style loading/navigation. Full-tree PHP syntax checks also pass. Package evidence includes the current JSON results and installer-safety results.

Run the ordinary suites in an isolated synthetic database as described in VERIFICATION.md. Then run `node guidance/tests/style.cjs`; configure `PUPPETEER_MODULE` and `GUIDANCE_BASE` for your separate test-tools installation. The original six-phase document/screenshots describe the initial contribution; this note and the new package evidence supersede its interface screenshots. No production-readiness or additional feature-parity claim is made.
