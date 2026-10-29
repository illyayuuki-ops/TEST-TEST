LCCT Ticketing - what changed and what to do
=============================================
STUDENT AFFAIRS-ONLY STAFF (latest change)
   The Super Admin pages/role and all office-admin accounts except Student Affairs (sao_admin) were removed.
   ONE login page for everyone (/auth/login.php): it detects the account automatically - the Student Affairs admin goes to the admin side, students go to the student side. The hidden staff link/key is no longer needed (/staff/login.php just redirects).
   Student Affairs also reviews alumni card approvals now. Other offices still exist for students to file tickets, but have no admin to handle them.
   Existing database: run database/remove_other_admins.sql once in phpMyAdmin (or just import the updated .sql dump).
   Because the Super Admin is gone, offices/QR codes/users can no longer be managed from the website - use phpMyAdmin.
   Alumni sign-up and alumni card approvals were removed too: registration is students only (school email + confirmation link). database/remove_alumni.sql is optional.
   Sections 0-0b below that mention Super Admin / other office admins are historical.

0. OFFICE ADMIN UPGRADE (run this first on an existing database)
   phpMyAdmin -> select your database -> SQL tab -> run database/upgrade_office_admin.sql  (safe to re-run).
   It adds ticket assignment + internal notes, and creates/fills the 12 department offices with their checklist categories.
   The new offices start with no admin: Super Admin -> Offices -> "Add admin" on each office card creates one (forced password change at first sign-in).
   Fresh install? ticketdesk_hosting.sql already includes all of this.

0b. ATTACHMENTS + OFFICE ALERTS (existing database): run database/upgrade_attachments.sql the same way (safe to re-run). Without it tickets still work but files are not saved.
   Upload the new folder uploads/ticket_files/ (with its .htaccess) and the new file.php. Fresh install? ticketdesk_hosting.sql already includes it.
   Office admins now get emails for new tickets, tickets assigned to them and escalations (needs the Brevo settings in step 4).

0c. PROFILE PHOTOS (existing database): run database/upgrade_profile_pic.sql the same way (safe to re-run). Upload avatar.php and the uploads/avatars/ folder (with its .htaccess).

1. DATABASE (only if your database already exists)
   phpMyAdmin -> select your database -> SQL tab -> run database/upgrade_verification.sql  (safe to re-run).
   Fresh install? Import database/ticketdesk_hosting.sql instead - it already includes everything.

2. Upload the whole folder (keep uploads/ and its .htaccess files).

3. Staff (admin) login is hidden. Admins bookmark this link:
   (Historical - no longer used.)
   Students/alumni use the normal page: /auth/login.php

4. Confirmation emails need a Brevo account (free): put the API key in BREVO_API_KEY and the
   verified sender address in MAIL_FROM (config/app.php). Without them, students cannot receive
   their confirmation link.

5. Check SCHOOL_EMAIL_DOMAIN in config/app.php matches your real student email domain.
