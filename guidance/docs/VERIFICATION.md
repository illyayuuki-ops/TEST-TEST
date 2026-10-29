# Corrected-source verification record

Date: 29 October 2026. Base: TEST-TEST `75fe13ee15b7e4ed8c93d19b5d539c80a67628b0`. Synthetic local database only. No school production database or remote credentials were used.

## Executed checks

| Suite | Result | Main evidence |
|---|---|---|
| Shared HTTP workflows/security | **133 / 133** | `evidence/http.json` |
| Browser/responsive/interactions | **188 / 188** | `evidence/browser.json` |
| Core authorization/validation/CSV/transaction checks | **30 / 30** | `evidence/core.json` |
| Migration/scheduler/encrypted export operations | **15 / 15** | `evidence/operations.json` |
| Total automated assertions | **366 / 366** | These four suites only |
| PHP syntax | **46 / 46 files** | `evidence/php-lint.txt` |
| Browser JS exceptions in completed suite | **0** | Browser evidence |
| Module/applier JavaScript syntax | Passed | Node syntax checks |

Additional manual local bootstrap check: copied the corrected build to an isolated scratch root, created a separate new local `_test` schema, confirmed seven synthetic shared users/readiness and persistent synthetic mode, then confirmed rerunning setup refused the existing private configuration. No existing database was deleted or reset.

Environment: PHP **8.4.27**, MariaDB **11.8.6**, headless Chrome **148.0.7778.97** via Puppeteer 24, Linux sandbox. Browser layouts: **1366px** and **390px**. External font/icon requests were blocked during the browser suite; core UI, official local images and QR scripts still worked. Evidence screenshots contain synthetic data only.

## What this demonstrates

- Native student submission and Guidance workspace reference the same shared ticket ID; walk-in ownership is preserved.
- Owner/head/assigned-counselor checks protect shared ticket, file and live endpoints. Internal notes/files remain hidden from students and personal export.
- Activity administrator does not inherit clinical access. Student Affairs original ticket/reply and report/team/category/records/audit paths remain accessible.
- CSRF, invalid transitions, safe output, appointment conflicts, capacity, RSVP/check-in, certificate eligibility/idempotency/locking, drafts, membership safeguards, preferences, privacy review, password change, rate limits and session cookie replay were exercised.
- All principal staff/student Guidance views rendered without page overflow at the two checked sizes, with no broken local images.
- Browser form submission persisted. Cross-user updates protected both native-ticket and module drafts even after field blur. QR SVG generation and denied-camera manual fallback worked.
- Migration rerun preserved shared account/ticket/membership counts. Synthetic setup refused a populated database. Scheduled escalation touched Guidance but not the corresponding SAO case. Re-running the job did not repeat the escalation.
- Encrypted snapshot creation/verification passed; plaintext was not visible; wrong key, tampering, overwrite and public-root output were rejected.

## What it does not demonstrate

Not Windows/Laragon or MySQL 8.4 execution; not all twelve departments; not full feature parity; not actual physical-camera decoding; not production SMTP/TLS/Apache/nested-path verification; not a high-load/concurrent-campus benchmark; not a full shared-system restore rehearsal; not stakeholder UAT, accessibility certification, penetration-test approval, legal compliance or production deployment. No hardware-bound authentication or 2FA is implemented.

The update helper passed **12 / 12** separate file-safety checks recorded in `evidence/package-checks.json` after packaging. Those are not counted in the 366 application assertions. The preceding Node/SQLite and wrong-Library test totals are unrelated and not reused.

## Reproduce in an isolated synthetic environment

1. Prepare PHP/MySQL and an empty local `_test` database; create fixtures with the guarded setup, never with original dump seeds.
2. Start `php -S 127.0.0.1:8081 guidance/dev-router.php` in the source root, with the synthetic DB environment/private configuration.
3. Install Python test dependencies `requests` and `beautifulsoup4` in a separate test environment. Install Puppeteer 24 in a separate tools folder, not the production application.
4. Set `GUIDANCE_BASE=http://127.0.0.1:8081`; set `PUPPETEER_MODULE` to the installed Puppeteer module directory. Set `LCCT_SYNTHETIC_TEST=1` and the test DB environment for CLI tests.
5. Run `php guidance/tests/core.php`, `python guidance/tests/operations.py`, `python guidance/tests/http_regression.py`, then `node guidance/tests/browser.cjs`.

The tests create additional **synthetic** records. They do not delete/reseed existing accounts. Keep fixture logins available; browser tests expect the HTTP suite's synthetic tickets and certificates. Review JSON results as well as process exit codes. Tests are intentionally not public web endpoints.
