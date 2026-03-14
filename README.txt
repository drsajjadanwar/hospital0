# hospital0 - Core Management System

## Overview
hospital0 is a comprehensive, role-based internal portal designed to manage the daily operations of a medical centre. It integrates clinical sessions, financial ledgers, pharmacy inventory, human resources, and point-of-sale (POS) systems into a single, centralised application.

Some screenshots:
https://hospital0.paperbus.org/01.jpg

## System Architecture

Do NOT forget to extract the coredirectories.zip file to /; overwrite if promoted. 
Also, do not forget to use the database file hospital0database.sql. It has been provided.

===========================================================================================
                                  [ END USERS / ROLES ]
  (Group 1: CMO)      (Group 2: Aestheticians)     (Group 3+: Finance, Admin, Pharmacy)
===========================================================================================
                                            │
                                            ▼
┌─────────────────────────────────────────────────────────────────────────────────────────┐
│                          ROUTING, AUTH & SECURITY LAYER                                 │
│                                                                                         │
│ ➔ login.php         : Session initialization & password verification                    │
│ ➔ config.php        : Environment loading (.env) & PDO Database Connection              │
│ ➔ attendance.php    : HARDCODED IP RESTRICTION (127.0.0.1) for physical clock-ins       │
└───────────────────────────────────────────┬─────────────────────────────────────────────┘
                                            │ (Role-Based Access Control via group_id)
                                            ▼
===========================================================================================
                                  CORE APPLICATION MODULES
===========================================================================================
   ┌───────────────┐      ┌───────────────┐      ┌───────────────┐      ┌───────────────┐
   │  [CLINICAL]   │      │  [FINANCIAL]  │      │ [PHARMACY/POS]│      │  [HR & ADMIN] │
   │               │      │               │      │               │      │               │
   │ appointments  │      │  addrevenue   │      │ api_fetch_sale│      │   adduser     │
   │  (Scheduling) │      │ (Patient Bill)│      │ (Async API)   │      │(Staff Onboard)│
   │               │      │               │      │               │      │               │
   │  aesthetics   │      │  addexpense   │      │    addbar     │      │  attendance   │
   │  (Consent &   │      │ (Outbound)    │      │ (Cafe POS for │      │ (Time Track)  │
   │   Signatures) │      │               │      │ Staff/Patient)│      │               │
   └───────┬───────┘      └───────┬───────┘      └───────┬───────┘      └───────┬───────┘
           │                      │                      │                      │
           └──────────────────────┼──────────────────────┼──────────────────────┘
                                  │
                                  ▼
===========================================================================================
                            DATABASE & INFRASTRUCTURE UTILITIES
===========================================================================================
┌─────────────────────────┐  ┌─────────────────────────┐  ┌─────────────────────────┐
│      MYSQL DATABASE     │  │    PDF GENERATION       │  │   BARCODE GENERATOR     │
│       (portaldb)        │  │      (fpdf.php)         │  │     (code128.php)       │
│                         │  │                         │  │                         │
│ • users & groups        │  │ • Patient Invoices      │  │ • Pharmacy Serials      │
│ • patient records (MRN) │◀─┼─▶ • Prescriptions       │◀─┼─▶ • Patient Documents     │
│ • pharmacyledger        │  │ • Financial Ledgers     │  │                         │
│ • attendance logs       │  │                         │  │                         │
└─────────────────────────┘  └─────────────────────────┘  └─────────────────────────┘

1. Clinical & Patient Modules
Appointments (appointments.php): Manages patient scheduling with dynamic time-revision and status updates.

Clinical Sessions (aesthetics.php): Dedicated portals for specialists (e.g., Aestheticians). Includes digital signature capture (canvas-based) for patient consent and treatment logging.

2. Financial & Billing Modules
Revenue (addrevenue.php): Logs patient billing, automatically pulling the latest patient MRN (Medical Record Number) visits.

Expenses (addexpense.php): Tracks outbound cash flow, restricted to Finance and Admin groups.

3. Pharmacy & Point of Sale (POS)
Pharmacy API (api_fetch_sale.php): An asynchronous endpoint that fetches granular pharmacy sale items via serial numbers, linking to the main pharmacyledger.

Bar/Cafe POS (addbar.php): A secondary POS system for internal cafe/bar purchases, capable of routing sales to either external Patients or internal Employees.

4. Human Resources & Administration
User Management (adduser.php): Admin-only portal to onboard new staff, assign RBAC group IDs, and set salary/shift parameters.

Attendance (attendance.php): Time-tracking module secured by a strict hardcoded IP Address whitelist (e.g., 127.0.0.1 after anonymisation) to ensure staff can only clock in when physically present at the centre.

5. Core Libraries & Infrastructure
PDF Generation (fpdf.php): Used extensively across modules to generate invoices, prescriptions, and ledgers.

Barcodes (code128.php): Generates scannable Code-128 barcodes for pharmacy serials and patient documents.

Environment (.env & config.php): Utilizes vlucas/phpdotenv to securely load database credentials.

Deployment & Setup
Environment Variables:
Rename env.example to .env (or create a .env file) in the root directory and configure your local database settings:

Code snippet
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=dbname
DB_USER=dbuser
DB_PASS=your_secure_password

Dependencies:
Run composer install to pull in necessary PHP libraries (e.g., PHPMailer, Dotenv).

Apache Configuration:
Ensure the included .htaccess file is active. It is strictly configured to deny public web access to the .env file, protecting your database credentials.

Security Notice (Attendance):
If deploying to a live server, ensure the $allowed_ip variable in attendance.php is updated to match your clinic's actual public static IP address.
