-- LCCT Ticketing: office-admin upgrade (assignment, internal notes, department presets).
-- Run ONCE in phpMyAdmin (select your database -> SQL tab). Safe to re-run (MariaDB, which is what InfinityFree uses). Back up first (Export tab).

-- Who in the office is handling a ticket
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS assigned_to INT NULL AFTER status;
ALTER TABLE tickets ADD INDEX IF NOT EXISTS idx_assigned (assigned_to);

-- Private notes between office staff (never shown to the student/alumni)
ALTER TABLE replies ADD COLUMN IF NOT EXISTS is_internal TINYINT(1) NOT NULL DEFAULT 0 AFTER message;

-- Faster office audit trail
ALTER TABLE audit_logs ADD INDEX IF NOT EXISTS idx_office_created (office_code, created_at);

-- Department presets from the checklist (Section 3: Minimum Department Coverage).
-- Adds any missing office and its sample ticket categories. Existing offices/categories are never changed or removed (INSERT IGNORE).
INSERT IGNORE INTO offices (office_code, office_name, description, contact, brand_color, brand_icon) VALUES
 ('registrar','Registrar','Records, enrollment, transcripts and document requests','','#7B6BC4','fa-file-lines'),
 ('finance','Finance','Payments, assessments, refunds and billing','','#2F9E6F','fa-money-bill-wave'),
 ('hr','Human Resources','Employment records, payroll, leave and benefits','','#C26A4A','fa-briefcase'),
 ('itdc','ITDC','Network, accounts, software and computer lab support','','#2A8FA8','fa-laptop-code'),
 ('sao','Student Affairs Office','IDs, discipline, org permits, student welfare and campus events','','#5E94CF','fa-user-graduate'),
 ('admission','Admission & Scholarships','Admission, applications, scholarships and financial assistance','','#B8860B','fa-user-graduate'),
 ('main','Maintenance Office','Repairs, utilities and facilities requests','','#8A6D3B','fa-screwdriver-wrench'),
 ('library','Library','Borrowing, database access, overdue notices and research help','','#2F9E6F','fa-book'),
 ('marketing','Marketing','Publications, promotions, social media and branding','','#D1477A','fa-bullhorn'),
 ('aea','External Affairs & Alumni','Alumni records, partnerships, MOAs and alumni events','','#7B6BC4','fa-award'),
 ('guide','Guidance Office','Counseling, career guidance and referrals','','#3E9B9B','fa-people-group'),
 ('acads','Academic Office','Curriculum, scheduling, academic policies (Basic Ed & College)','','#4A6FA5','fa-school');

INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order) VALUES
 ('registrar','Enrollment Records',0,110),
 ('registrar','Transcript Request',0,120),
 ('registrar','Diploma Request',0,130),
 ('registrar','Transfer Credentials',0,140),
 ('registrar','Correction of Records',0,150),
 ('finance','Payment Concern',0,110),
 ('finance','Assessment Query',0,120),
 ('finance','Refund',0,130),
 ('finance','Billing Dispute',0,140),
 ('finance','Scholarship Billing',0,150),
 ('hr','Employment Records',0,110),
 ('hr','Payroll Concern',0,120),
 ('hr','Leave Application',0,130),
 ('hr','Benefits',0,140),
 ('hr','Faculty / Staff Request',0,150),
 ('itdc','Network Access',0,110),
 ('itdc','Wi-Fi Concern',0,120),
 ('itdc','Computer Lab & Office Support',0,130),
 ('itdc','Software Installation',0,140),
 ('itdc','Email & Account Access',0,150),
 ('sao','Student Activities',0,110),
 ('sao','Organization Request',0,120),
 ('sao','Disciplinary Concern',0,130),
 ('sao','Student Welfare',0,140),
 ('sao','Campus Events',0,150),
 ('admission','Admission Requirements',0,110),
 ('admission','Application Status',0,120),
 ('admission','Scholarship Application',0,130),
 ('admission','Scholarship Renewal',0,140),
 ('admission','Financial Assistance',0,150),
 ('main','Classroom Repair',0,110),
 ('main','Electrical Concern',0,120),
 ('main','Plumbing',0,130),
 ('main','Air-Conditioning',0,140),
 ('main','Furniture',0,150),
 ('main','Facilities Request',0,160),
 ('library','Book Reservation',0,110),
 ('library','Library Card Concern',0,120),
 ('library','Database Access',0,130),
 ('library','Overdue Notice',0,140),
 ('library','Research Assistance',0,150),
 ('marketing','Publication Request',0,110),
 ('marketing','Event Promotion',0,120),
 ('marketing','Social Media Content',0,130),
 ('marketing','Branding Materials',0,140),
 ('marketing','Media Inquiry',0,150),
 ('aea','Alumni Records',0,110),
 ('aea','Partnerships',0,120),
 ('aea','Memorandum of Agreement',0,130),
 ('aea','Alumni Events',0,140),
 ('aea','External Linkages',0,150),
 ('guide','Counseling Appointment',0,110),
 ('guide','Career Guidance',0,120),
 ('guide','Psychological Services',0,130),
 ('guide','Student Concern',0,140),
 ('guide','Referral Request',0,150),
 ('acads','Curriculum Concern',0,110),
 ('acads','Class Scheduling',0,120),
 ('acads','Academic Policy',0,130),
 ('acads','Faculty Concern',0,140),
 ('acads','Basic Education Request',0,150),
 ('acads','College Academic Request',0,160);

-- every office keeps its locked 'Other' fallback
INSERT IGNORE INTO categories (office_code, name, is_locked, sort_order) SELECT office_code, 'Other', 0, 999 FROM offices;
