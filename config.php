<?php
// ------------------------------------------------------------
// myRDA+PLUS Web  -  settings   (EDIT THIS FILE before uploading)
//
// InfinityFree: open Client Area -> your account -> "MySQL Databases".
//   db_host = the host shown there (looks like sql123.infinityfree.com, NOT localhost)
//   db_name = the full database name (looks like if0_12345678_myrda)
//   db_user = your MySQL username (looks like if0_12345678)
//   db_pass = your MySQL password
//
// Laragon (your own computer): host 127.0.0.1, user root, empty password.
//   Tip: put your Laragon values in a file called config.local.php (same format,
//   only the keys you want to override) and leave this file for the live site.
// ------------------------------------------------------------
$config = [
    'db_host' => 'sql104.infinityfree.com',
    'db_port' => 3306,
    'db_name' => 'if0_43107666_SALRC',
    'db_user' => 'if0_43107666',
    'db_pass' => 'fEUTCJCopjz',

    // Logins (admin, librarian, staff) are in the database table `Users` - import users.sql once.

    // Circulation rules (borrowing). Fines per day come from the OverdueCharge table in the database.
    'circulation' => [
        'loan_days' => 7,                    // regular books
        'overnight_days' => 1,               // items whose location contains "Reserve"
        'nonbook_days' => 3,                 // non-book materials
        'max_loans' => 3,                    // items one borrower may hold at once
        'block_if_overdue' => true,          // no new loans while something is overdue
        'no_loan_keywords' => ['Reference'], // locations that cannot be borrowed
        'reserve_keyword' => 'Reserve',
        'reservation_days' => 3,             // how long a reserved item is held
        'hold_days' => 1,                    // how long a held item waits for its borrower (open days)
        'block_if_unpaid' => false,          // true = no new loans while a borrower has an unpaid fine
        'default_fine_per_day' => 5,
        'equipment_fine_per_hour' => 5,      // fine for each late hour of an equipment loan (goes on the borrower's balance)
        'currency' => '₱',
    ],

    'per_page' => 25,
    'library_name' => 'St. Augustine Learning Resource Center',
    'debug' => false,   // set true ONLY while fixing problems; shows real error messages
];
if (is_file(__DIR__ . '/config.local.php')) { $config = array_merge($config, require __DIR__ . '/config.local.php'); }
return $config;
