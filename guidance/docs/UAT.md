# Guided acceptance review — not yet signed off

Use only the fresh synthetic database. Do not enter real counseling details. The automated checks are not a substitute for representative users or school approval.

## Student

- Sign in as `student_one` through the shared login. Create a Guidance request using the common new-ticket form and attach a harmless PDF.
- Confirm the same ticket appears in both the common dashboard and Guidance Requests. Reply, track status, confirm resolution, then reopen within policy.
- Book a future appointment. Confirm a conflicting booking is refused without losing the form values.
- Accept an invitation, view the personal pass, try the correct venue code during the activity window, and view participation history/credits/certificate after staff completion.
- Test declined/unregistered participation, a wrong code, an ended activity and someone else's certificate.
- Enter an unsaved draft, have another user update data, and confirm the draft survives after focus moves away from the field.
- Export own records; verify no internal note or internal attachment appears.

## Counselor / head

- As `guide_head`, assign the request to `guide_counselor`. Confirm `guide_other` cannot open it or download its files, even with a copied URL.
- Add an internal note/file as the assigned counselor; confirm the student cannot see it. Reassign as head and confirm the old counselor immediately loses access.
- Create a walk-in for `student_two`. Confirm that student is the owner and the staff creator is recorded separately.
- Confirm/cancel an appointment. Try an invalid transition and a future completion.
- Create a program/activity, send explicit invitations, register participants, mark attendance and issue certificates only after completion. Try duplicate issuance and post-issuance attendance/event changes.
- Check knowledge drafts/publication, categories, membership changes, last-head safeguards and privacy review responses.

## Activity administrator / Student Affairs

- As `guide_admin`, manage an activity and open aggregate reports. Attempt direct access to a clinical case, attachment, internal note and clinical live endpoint: deny.
- As `sao_demo`, process a Student Affairs ticket and open its original reports/team/categories/records/audit pages. Attempt a Guidance case URL: deny.
- Confirm a student's Student Affairs request does not appear in Guidance case reports or queues.

## Devices / operations

- Windows desktop, phone at 390px, tablet, keyboard-only navigation and reduced-motion setting.
- Real camera under HTTPS/localhost; plain-HTTP LAN manual fallback; camera denied/unavailable; incorrect/mismatched QR.
- Wi-Fi disconnect/reconnect, expired session, duplicate clicks, multiple simultaneous registrations for the final seat, delayed database and recovery after server restart.
- MySQL 8.4 strict-mode migration and workflows; Apache direct-file protections; nested deployment path if used by the team.
- Encrypted backup integrity and an owner-approved complete shared database/upload recovery rehearsal in a separate environment.
- HTTPS cookies, production mail with nonsensitive content, credential rotation, least-privilege DB account, access review and retention approval.

## Sign-off record

| Role | Name | Date | Result | Blocking issues |
|---|---|---|---|---|
| Student representative | Pending | | | |
| Counselor | Pending | | | |
| Guidance head | Pending | | | |
| Student Affairs collaborator | Pending | | | |
| Combined application / DB owner | Pending | | | |
| Professor / assessor | Pending | | | |
| Privacy / security reviewer | Pending | | | |

No interview, approval, performance target, legal compliance or production acceptance is represented as completed by this document.
