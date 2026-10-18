# Library Management System — Second Update & System Enhancements

**Type:** Web Application (PHP + MySQL + Modern CSS + JS)  
**Course:** Database Systems Project (UIU)  


## 🌟 Key Features & Module Overview

### 1. Admin & Librarian Separation & Views
- **Librarian Operations Desk (`librarian_dashboard.php`)**: Dedicated operational dashboard for librarians with Express Barcode Check-In scanner, book issue desk, reservation fulfillment queue, copy status & maintenance manager, fine & penalty settings, and circulation audit logs.
- **Admin Control Panel (`admin_dashboard.php`)**: Complete administrative authority with executive KPIs, master system settings, user management, and a dedicated **Librarian Activities & Oversight tab** to monitor all issues, returns, fine waivers, and actions performed by librarians.
- **Portal View Switchers**:
  - **Admin**: Quick toggles between `⚙️ Admin Panel`, `📖 Librarian View`, and `👤 User View`.
  - **Librarian**: Quick toggle between `📖 Librarian Desk` and `👤 User View`.

### 2. Community & Book Exchange (Strict Access Control)
- **Airtight Access Enforcement (`community.php`)**:
  - Connected members can view and exchange each other's personal library books.
  - **If two members disconnect / remove their relationship**, neither user can view or access the other's book exchange posts in the Hub, and pending exchange requests between them are automatically cancelled.
  - Direct exchange request submissions strictly verify that an active, accepted connection exists before allowing any swap request.

### 3. Premium Membership Applications (`membership.php`)
- **User-Side Upgrade Portal**:
  - Privilege comparison table (Regular: 3 books, 14 days, 1 renewal vs. Premium: 6 books, 30 days, 3 renewals, fast-track reservation queue).
  - Term selection (1 Month: 200 BDT, 3 Months: 550 BDT, 6 Months: 1000 BDT, 12 Months: 1800 BDT).
  - Sandbox / Dummy Transaction generator for instant demonstration.
  - Real-time application tracking table with approval status and admin feedback.
- **Admin Review Desk (`admin_dashboard.php?tab=premium_requests`)**:
  - Admin views all incoming membership applications.
  - 1-click **Approve** (activates Premium tier, sets end date, records transaction).
  - **Reject** with custom feedback note to the applicant.

### 4. Interactive Payment Checkout & Dummy Transaction Gateway (`payment.php`)
- Settle overdue late return fines and library fees with a realistic payment flow.
- Choose between Card (Visa/Mastercard) and Mobile Financial Services (bKash/Nagad/Rocket).
- **One-Click Dummy Transaction Simulator**: Generates unique transaction references (`DEMO-TXN-XXXXXX`) and test credentials with sandbox demonstration badge.
- Instant digital payment receipt & tax invoice with printable view (`window.print()`).
- In-person counter cash collection modal for Librarians.

### 5. Expanded Librarian Fine & Penalty Settings & Controls
- **Configurable Policies (`librarian_dashboard.php?tab=fines`)**:
  - Daily overdue fine rate (`fine_per_day`)
  - Grace period before fines accumulate (`fine_grace_period_days`, e.g., 2 days)
  - Fine ceiling per book (`max_fine_per_book`, e.g., 500 BDT)
  - Standard lost book replacement fee (`lost_book_fee`, e.g., 400 BDT)
  - Standard damaged book penalty (`damaged_book_fee`, e.g., 150 BDT)
  - Overdue debt suspension threshold (`borrow_suspension_threshold`, e.g., 100 BDT)
- **Manual Penalty Assessment**: Impose fines for damaged binding, torn pages, or lost books with recorded reason.
- **Justified Fine Waivers**: Waive fines with required justification (Medical Excuse, Faculty Exemption, System Glitch) recorded in audit logs.
- **Automatic Borrowing Restriction**: Borrowing is blocked when unpaid fines exceed the suspension threshold.

---

## 🔑 Login Credentials for Testing

| Role | Email | Password | Landing Page |
|---|---|---|---|
| **System Admin** | `admin@gmail.com` | `Admin@123` | `admin_dashboard.php` |
| **Chief Librarian** | `librarian@gmail.com` | `Librarian@123` | `librarian_dashboard.php` |
| **Member 1** | `muntaha.prapti137@gmail.com` | *(user password or register new)* | `dashboard.php` |
| **Member 2** | `member2@gmail.com` | `Member@123` | `dashboard.php` |

---

## 🚀 How to Run & Verify

1. Ensure **Apache** and **MySQL** are running in the XAMPP Control Panel.
2. Open your web browser and navigate to:
   ```
   http://localhost/lms/login.php
   ```
3. Run the automated integration test suite via PHP CLI:
   ```powershell
   C:\xampp\php\php.exe tests/test_all_features.php
   ```
