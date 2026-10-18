import docx
from docx.shared import Inches, Pt, RGBColor
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.enum.table import WD_TABLE_ALIGNMENT, WD_ALIGN_VERTICAL
from docx.oxml import OxmlElement, parse_xml
from docx.oxml.ns import nsdecls, qn

def set_cell_background(cell, fill_hex):
    tcPr = cell._tc.get_or_add_tcPr()
    shd = parse_xml(f'<w:shd {nsdecls("w")} w:fill="{fill_hex}"/>')
    tcPr.append(shd)

def set_cell_margins(cell, top=100, bottom=100, left=150, right=150):
    tcPr = cell._tc.get_or_add_tcPr()
    tcMar = OxmlElement('w:tcMar')
    for m, val in [('top', top), ('bottom', bottom), ('left', left), ('right', right)]:
        node = OxmlElement(f'w:{m}')
        node.set(qn('w:w'), str(val))
        node.set(qn('w:type'), 'dxa')
        tcMar.append(node)
    tcPr.append(tcMar)

def add_code_block(doc, sql_text):
    tbl = doc.add_table(rows=1, cols=1)
    tbl.alignment = WD_TABLE_ALIGNMENT.CENTER
    tbl.autofit = False
    
    cell = tbl.cell(0, 0)
    cell.width = Inches(6.5)
    set_cell_background(cell, "F8F9FA")
    set_cell_margins(cell, top=140, bottom=140, left=200, right=200)
    
    # Border
    tcPr = cell._tc.get_or_add_tcPr()
    borders = parse_xml(f'''
        <w:tcBorders {nsdecls("w")}>
            <w:top w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
            <w:left w:val="single" w:sz="12" w:space="0" w:color="3B82F6"/>
            <w:bottom w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
            <w:right w:val="single" w:sz="4" w:space="0" w:color="E2E8F0"/>
        </w:tcBorders>
    ''')
    tcPr.append(borders)
    
    p = cell.paragraphs[0]
    p.paragraph_format.space_before = Pt(2)
    p.paragraph_format.space_after = Pt(2)
    p.paragraph_format.line_spacing = 1.15
    run = p.add_run(sql_text)
    run.font.name = 'Consolas'
    run.font.size = Pt(9.5)
    run.font.color.rgb = RGBColor(0x1E, 0x29, 0x3B)
    
    doc.add_paragraph().paragraph_format.space_after = Pt(6)

doc = docx.Document()

# Page Margins
sections = doc.sections
for section in sections:
    section.top_margin = Inches(1.0)
    section.bottom_margin = Inches(1.0)
    section.left_margin = Inches(1.0)
    section.right_margin = Inches(1.0)

# -------------------------------------------------------------
# COVER PAGE
# -------------------------------------------------------------
p_title = doc.add_paragraph()
p_title.alignment = WD_ALIGN_PARAGRAPH.CENTER
p_title.paragraph_format.space_before = Pt(36)
p_title.paragraph_format.space_after = Pt(24)
r_proj = p_title.add_run("PROJECT REPORT\n\n")
r_proj.font.name = 'Arial'
r_proj.font.size = Pt(22)
r_proj.font.bold = True
r_proj.font.color.rgb = RGBColor(0x0F, 0x17, 0x2A)

r_main = p_title.add_run("Library Management System & Peer Book Exchange\n")
r_main.font.name = 'Arial'
r_main.font.size = Pt(18)
r_main.font.bold = True
r_main.font.color.rgb = RGBColor(0x1E, 0x3A, 0x8A)

r_sub = p_title.add_run("A Comprehensive Relational DBMS for Automated Circulation, Fine Governance & Community Sharing\n")
r_sub.font.name = 'Calibri'
r_sub.font.size = Pt(12)
r_sub.font.italic = True
r_sub.font.color.rgb = RGBColor(0x47, 0x55, 0x69)

p_meta = doc.add_paragraph()
p_meta.paragraph_format.space_before = Pt(72)
p_meta.paragraph_format.space_after = Pt(12)

def add_meta_line(p, label, val):
    r1 = p.add_run(f"{label}: ")
    r1.font.name = 'Calibri'
    r1.font.size = Pt(11)
    r1.font.bold = True
    r2 = p.add_run(f"{val}\n")
    r2.font.name = 'Calibri'
    r2.font.size = Pt(11)

add_meta_line(p_meta, "Course", "Database Management Systems Laboratory")
add_meta_line(p_meta, "Faculty", "Mahmudul Hasan")
add_meta_line(p_meta, "Section", "L")
add_meta_line(p_meta, "Team Name", "LMS Innovators")

p_team_h = doc.add_paragraph()
p_team_h.paragraph_format.space_before = Pt(24)
p_team_h.paragraph_format.space_after = Pt(6)
r_th = p_team_h.add_run("Team Members")
r_th.font.name = 'Calibri'
r_th.font.size = Pt(12)
r_th.font.bold = True

p_team = doc.add_paragraph()
p_team.paragraph_format.space_before = Pt(4)
p_team.paragraph_format.space_after = Pt(24)

members = [
    "1. Thirtha Dey (0112410445)",
    "2. MD Ahsun Bhuiyan Anam (0112410444)",
    "3. Asad Miah (0112410460)",
    "4. Muntaha Jahan Prapti (0112330919)"
]
for m in members:
    r = p_team.add_run(m + "\n")
    r.font.name = 'Calibri'
    r.font.size = Pt(11)

doc.add_page_break()

# Helper for section headings
def add_heading_1(text):
    h = doc.add_paragraph()
    h.paragraph_format.space_before = Pt(18)
    h.paragraph_format.space_after = Pt(8)
    r = h.add_run(text)
    r.font.name = 'Arial'
    r.font.size = Pt(15)
    r.font.bold = True
    r.font.color.rgb = RGBColor(0x0F, 0x17, 0x2A)
    return h

def add_heading_2(text):
    h = doc.add_paragraph()
    h.paragraph_format.space_before = Pt(14)
    h.paragraph_format.space_after = Pt(6)
    r = h.add_run(text)
    r.font.name = 'Arial'
    r.font.size = Pt(12)
    r.font.bold = True
    r.font.color.rgb = RGBColor(0x1E, 0x29, 0x3B)
    return h

def add_heading_3(text):
    h = doc.add_paragraph()
    h.paragraph_format.space_before = Pt(10)
    h.paragraph_format.space_after = Pt(4)
    r = h.add_run(text)
    r.font.name = 'Calibri'
    r.font.size = Pt(11)
    r.font.bold = True
    r.font.color.rgb = RGBColor(0x33, 0x41, 0x55)
    return h

def add_body_p(text):
    p = doc.add_paragraph()
    p.paragraph_format.space_before = Pt(0)
    p.paragraph_format.space_after = Pt(8)
    p.paragraph_format.line_spacing = 1.15
    r = p.add_run(text)
    r.font.name = 'Calibri'
    r.font.size = Pt(11)
    r.font.color.rgb = RGBColor(0x1E, 0x29, 0x3B)
    return p

def add_bullet_p(title, text):
    p = doc.add_paragraph(style='List Bullet')
    p.paragraph_format.space_before = Pt(2)
    p.paragraph_format.space_after = Pt(4)
    p.paragraph_format.line_spacing = 1.15
    r_bold = p.add_run(f"{title}: ")
    r_bold.font.name = 'Calibri'
    r_bold.font.size = Pt(11)
    r_bold.font.bold = True
    r_bold.font.color.rgb = RGBColor(0x0F, 0x17, 0x2A)
    
    r_txt = p.add_run(text)
    r_txt.font.name = 'Calibri'
    r_txt.font.size = Pt(11)
    r_txt.font.color.rgb = RGBColor(0x33, 0x41, 0x55)
    return p

# -------------------------------------------------------------
# 1. INTRODUCTION
# -------------------------------------------------------------
add_heading_1("Introduction")
add_body_p(
    "Academic institutions and learning communities rely heavily on equitable and timely access to physical and digital "
    "knowledge resources. However, conventional library setups and standalone book management systems across educational "
    "environments continue to struggle with acute operational bottlenecks, opaque inventory visibility, non-standardized "
    "fine penalties, and the total lack of decentralized peer-to-peer book sharing channels. Students, researchers, and educators "
    "frequently face substantial friction when searching for urgently needed references, dealing with rigid physical checkout "
    "counters, arbitrary overdues, and disjointed communication channels."
)
add_body_p(
    "On the administrative side, librarians and system managers are burdened with cumbersome manual ledger recording, disjointed "
    "membership renewals, and inaccurate record reconciliation across distinct operational tiers. Moreover, vast personal "
    "book collections owned by enrolled students and faculty members remain idle on private bookshelves, completely disconnected "
    "from other peers who need them. This creates artificial scarcity of academic resources, driving up textbook expenditures "
    "and inhibiting academic productivity."
)
add_body_p(
    "To comprehensively resolve these foundational challenges, we engineered the Library Management & Peer Book Exchange System (LMS). "
    "At its core, LMS is a full-lifecycle, multi-tenant relational platform built with strict 3NF database normalization, robust foreign-key "
    "referential integrity, transactional consistency, and granular Role-Based Access Control (RBAC). It bridges the historical chasm "
    "between institutional library inventories and individual community collections. From automated book copy barcode tracking, real-time "
    "reservation queues, dynamic fine computation, and staff desk workflows, to mutual peer connection validation and secure book exchanges, "
    "LMS delivers a tamper-proof, transparent, and user-centric knowledge ecosystem."
)

# -------------------------------------------------------------
# 2. MOTIVATION
# -------------------------------------------------------------
add_heading_1("Motivation")
add_body_p(
    "The LMS project was born out of genuine systemic necessity within university and public learning environments. The guiding philosophy "
    "is straightforward: every learner, researcher, and educator deserves equal, frictionless access to educational literature, fair and automated "
    "circulation rules, and a vibrant community sharing network."
)
add_body_p(
    "In traditional institutions, the lack of centralized digital databases produces extensive discrepancies. Copies are often reported lost "
    "or unavailable due to manual paperwork delays, while overdue penalties are imposed inconsistently or lack verifiable audit trails. "
    "Furthermore, commercial book pricing continues to escalate rapidly, placing a severe financial strain on students. When an essential "
    "engineering or medical textbook is checked out by one member, all other peers are locked out until return, even if multiple students on campus "
    "own that exact edition privately."
)
add_body_p(
    "LMS transforms this paradigm by coupling institutional library operations with a verified Peer-to-Peer (P2P) Community Exchange Network. "
    "By establishing clear role segregation across System Admins, Chief Librarians, and Members, and enforcing strict relational database integrity, "
    "the platform eliminates operational ambiguity, empowers librarians with dedicated management desks, ensures transparent fine governance, "
    "and enables trusted peer book exchanges backed by mutual connection validation."
)

# -------------------------------------------------------------
# 3. OBJECTIVES
# -------------------------------------------------------------
add_heading_1("Objectives")
add_body_p("The core objectives of the LMS project are structured to eliminate traditional vulnerabilities in institutional resource circulation:")

add_bullet_p(
    "Relational Normalization & Complete Data Integrity",
    "Design and implement an enterprise-grade 3NF normalized schema encompassing 18+ interconnected entities with cascading constraints, indexes, and transactional consistency."
)
add_bullet_p(
    "Strict Role-Based Access Control (RBAC) & Dedicated Portals",
    "Provide segregated, secure portals for Administrators, Librarians, and Members with dedicated login routing, privilege validation, and CSRF/XSS protection."
)
add_bullet_p(
    "Automated Circulation, Reservation & Queue Management",
    "Streamline issue, return, renewal, and reservation workflows with copy-level barcode tracking, automated availability toggles, and FIFO reservation queue fulfillment."
)
add_bullet_p(
    "Dynamic Fine Computation & Financial Governance",
    "Automate overdue fine calculation utilizing centralized configuration parameters, maintain immutable payment records, and support authorized fine waivers with audit reasoning."
)
add_bullet_p(
    "Verified Peer-to-Peer Community Book Exchange",
    "Implement a trust-based community exchange platform that enforces mutual friendship/connection verification before exchange requests can be initiated."
)
add_bullet_p(
    "Audit Logging & Operational Analytics",
    "Capture comprehensive activity logs for sensitive system operations, while delivering real-time dashboards and statistical reporting on circulation velocities and inventory utilization."
)

# -------------------------------------------------------------
# 4. MAIN FEATURES
# -------------------------------------------------------------
add_heading_1("Main Features")

add_heading_2("Features for the Core User & Member Portals")

add_heading_3("1. Member / Student Portal")
add_bullet_p("OPAC Catalog Discovery", "Search and filter books by title, author, category, ISBN, or publication year with real-time copy availability indicators.")
add_bullet_p("Personal Circulation Dashboard", "Monitor active loans, issue dates, due dates, renewal counts, and estimated overdue fines in real-time.")
add_bullet_p("Self-Service Renewal & Reservations", "Renew eligible borrowings within configured limits or place automated reservations on fully borrowed titles.")
add_bullet_p("Fine Settlement & Payment History", "Inspect itemized pending fines, view waiver histories, and track verified payment receipts.")
add_bullet_p("Personal Book Collection Hub", "Register privately owned books with physical condition grading (New, Good, Fair, Poor) for community sharing.")

add_heading_3("2. Community & Peer Book Exchange Module")
add_bullet_p("Peer Discovery & Connection Engine", "Search registered community members, send connection requests, and manage incoming/outgoing relationship states (Pending, Accepted, Rejected).")
add_bullet_p("Mutual Connection Enforcement", "Strict cryptographic and relational validation ensuring only connected peers can view exchange actions and propose book swaps.")
add_bullet_p("Interactive Exchange Hub", "Submit structured exchange requests with custom notes, manage incoming requests with Accept/Reject actions, and track exchange completion states.")
add_bullet_p("Locked Inventory Security Visuals", "Display locked status and direct connection triggers for books owned by non-connected peers to maintain community privacy.")

add_heading_2("Features for the Operational Staff & Governance Portals")

add_heading_3("3. Dedicated Librarian Desk Portal")
add_bullet_p("Standalone Operations Command Center", "Dedicated, fully independent workspace isolated from the Admin Panel, tailored specifically for library staff.")
add_bullet_p("Rapid Circulation Desk (Issue / Return / Renew)", "Quick barcode scanning and user selection modal for fast checkouts, returns, and authorized loan extensions.")
add_bullet_p("Real-Time Reservation Fulfillment Queue", "Live tracking of pending book reservations, enabling librarians to fulfill holds as soon as copies are checked in.")
add_bullet_p("Fine Imposition & Waiver Administration", "Impose manual damage/miscellaneous fines with mandatory reason fields, or grant authorized fine waivers with logged justification.")
add_bullet_p("Copy & Barcode Inventory Manager", "Add, edit, inspect, and update individual book copy statuses (Available, Borrowed, Reserved, Maintenance, Lost).")

add_heading_3("4. System Administrator Portal")
add_bullet_p("Comprehensive System Overview", "Macro dashboard showing total books, registered users, active loans, overdue accounts, and cumulative fine revenue.")
add_bullet_p("User & Staff Role Management", "Create, promote, activate, or suspend user accounts across Admin, Librarian, and Member tiers.")
add_bullet_p("Master Catalog Administration", "Full CRUD management of Books, Authors, Categories, and Publishers with relational cascade safeguards.")
add_bullet_p("System Settings & Dynamic Policy Engine", "Configure global operational variables including fine rates per day, max active reservations, default loan periods, and currency symbols.")
add_bullet_p("Activity Log & Audit Trail Inspector", "Review chronological event logs capturing user actions, administrative interventions, and system-level events.")

# -------------------------------------------------------------
# 5. TECHNOLOGY STACK
# -------------------------------------------------------------
add_heading_1("Technology Stack")
add_bullet_p("Front-end", "HTML5, Vanilla CSS3 (Custom Design System with responsive grid, glassmorphism, and dark/light accents), Modern JavaScript (ES6+), FontAwesome Icons")
add_bullet_p("Back-end / Application Layer", "PHP 8.2+ (Modular procedural & OOP architecture, PDO Prepared Statements, Session Security, CSRF Protection)")
add_bullet_p("Database", "MySQL / MariaDB (Enterprise Relational Storage, InnoDB Engine, UTF8mb4 Character Set, Foreign Key Constraints, Indexes, 3NF Normalization)")
add_bullet_p("Server Environment", "Apache Web Server, XAMPP Local Development Stack")

# -------------------------------------------------------------
# 6. ER DIAGRAM & RELATIONAL SCHEMA ARCHITECTURE
# -------------------------------------------------------------
add_heading_1("ER Diagram & Relational Schema Architecture")
add_body_p(
    "The relational database schema for the Library Management System is normalized to Third Normal Form (3NF) to guarantee "
    "zero data redundancy, atomic attribute storage, and strict referential integrity across all 18 interrelated entities. "
    "Primary foreign key cascades, unique constraints, and composite indexes are deployed to optimize query latency and ensure "
    "fault-tolerant transactional processing."
)
add_body_p(
    "Core entity clusters in the data model include: (1) Identity & Membership Management (`users`, `memberships`, `membership_types`, `password_resets`), "
    "(2) Master Catalog & Physical Inventory (`books`, `authors`, `categories`, `publishers`, `book_copies`), (3) Circulation & Queuing "
    "(`borrowings`, `reservations`), (4) Financial Auditing (`fines`, `payments`, `library_settings`), (5) P2P Community Exchange "
    "(`community_connections`, `member_books`, `exchange_requests`), and (6) System Observability (`activity_logs`)."
)

# -------------------------------------------------------------
# 7. DML STATEMENTS & CORE STRUCTURAL QUERIES
# -------------------------------------------------------------
add_heading_1("DML (Data Modification Language) Statements & Core Structural Queries")
add_body_p("The following 30 production-grade SQL queries power the core business logic, automated circulation algorithms, fine calculation engines, and peer-to-peer exchange rules across the LMS platform:")

queries = [
    (
        "1. Real-Time Book Catalog Search with Dynamic Copy Availability",
        """SELECT 
    b.book_id, b.title, b.isbn, b.publication_year,
    a.author_name, c.category_name, p.publisher_name,
    COUNT(bc.copy_id) AS total_copies,
    SUM(CASE WHEN bc.status = 'available' THEN 1 ELSE 0 END) AS available_copies
FROM books b
JOIN authors a ON b.author_id = a.author_id
JOIN categories c ON b.category_id = c.category_id
JOIN publishers p ON b.publisher_id = p.publisher_id
LEFT JOIN book_copies bc ON b.book_id = bc.book_id
WHERE b.title LIKE :query OR a.author_name LIKE :query OR b.isbn LIKE :query
GROUP BY b.book_id, b.title, b.isbn, b.publication_year, a.author_name, c.category_name, p.publisher_name
ORDER BY b.title ASC;"""
    ),
    (
        "2. Fetch Active User Borrowings with Overdue Calculations",
        """SELECT 
    bw.borrowing_id, bw.user_id, bw.issue_date, bw.due_date, bw.renewal_count,
    bk.title, bk.isbn, bc.barcode, bc.location,
    GREATEST(DATEDIFF(CURDATE(), bw.due_date), 0) AS days_overdue,
    GREATEST(DATEDIFF(CURDATE(), bw.due_date), 0) * CAST((
        SELECT setting_value FROM library_settings WHERE setting_key = 'fine_per_day'
    ) AS DECIMAL(8,2)) AS estimated_fine
FROM borrowings bw
JOIN book_copies bc ON bw.copy_id = bc.copy_id
JOIN books bk ON bc.book_id = bk.book_id
WHERE bw.user_id = :user_id AND bw.return_date IS NULL
ORDER BY bw.due_date ASC;"""
    ),
    (
        "3. Automated Overdue Loan Identification for Fine Batch Escalation",
        """SELECT 
    bw.borrowing_id, u.user_id, u.full_name, u.email,
    b.title, c.barcode, bw.issue_date, bw.due_date,
    DATEDIFF(CURDATE(), bw.due_date) AS days_late,
    DATEDIFF(CURDATE(), bw.due_date) * (
        SELECT CAST(setting_value AS DECIMAL(8,2)) FROM library_settings WHERE setting_key = 'fine_per_day'
    ) AS calculated_fine
FROM borrowings bw
JOIN users u ON bw.user_id = u.user_id
JOIN book_copies c ON bw.copy_id = c.copy_id
JOIN books b ON c.book_id = b.book_id
WHERE bw.return_date IS NULL AND bw.due_date < CURDATE()
ORDER BY days_late DESC;"""
    ),
    (
        "4. Validate Mutual Connection Status Before Book Exchange",
        """SELECT connection_id, status 
FROM community_connections 
WHERE ((sender_id = :curr_user AND receiver_id = :owner_id) 
    OR (sender_id = :owner_id AND receiver_id = :curr_user))
  AND status = 'accepted'
LIMIT 1;"""
    ),
    (
        "5. Fetch Available Community Books with Verified Peer Connection Status",
        """SELECT 
    mb.owned_book_id, mb.owner_id, mb.title, mb.author_name, 
    mb.book_condition, mb.description, mb.created_at,
    u.full_name AS owner_name, u.email AS owner_email,
    EXISTS (
        SELECT 1 FROM community_connections cc
        WHERE ((cc.sender_id = :user_id AND cc.receiver_id = mb.owner_id)
            OR (cc.sender_id = mb.owner_id AND cc.receiver_id = :user_id))
          AND cc.status = 'accepted'
    ) AS is_connected
FROM member_books mb
JOIN users u ON mb.owner_id = u.user_id
WHERE mb.status = 'available' AND mb.owner_id != :user_id
ORDER BY mb.created_at DESC;"""
    ),
    (
        "6. Fetch Pending and Completed Exchange Requests for a Member",
        """SELECT 
    er.exchange_id, er.requester_id, er.owned_book_id, er.message, er.status, er.created_at,
    mb.title AS book_title, mb.author_name, mb.owner_id,
    req.full_name AS requester_name, req.email AS requester_email,
    own.full_name AS owner_name, own.email AS owner_email
FROM exchange_requests er
JOIN member_books mb ON er.owned_book_id = mb.owned_book_id
JOIN users req ON er.requester_id = req.user_id
JOIN users own ON mb.owner_id = own.user_id
WHERE er.requester_id = :user_id OR mb.owner_id = :user_id
ORDER BY er.created_at DESC;"""
    ),
    (
        "7. Librarian Desk: Active Borrowings and Overdue Return Management Queue",
        """SELECT 
    bw.borrowing_id, bw.issue_date, bw.due_date, bw.renewal_count,
    u.user_id, u.full_name AS member_name, u.email AS member_email,
    b.title AS book_title, c.barcode, c.copy_id,
    GREATEST(DATEDIFF(CURDATE(), bw.due_date), 0) AS days_overdue
FROM borrowings bw
JOIN users u ON bw.user_id = u.user_id
JOIN book_copies c ON bw.copy_id = c.copy_id
JOIN books b ON c.book_id = b.book_id
WHERE bw.return_date IS NULL
ORDER BY bw.due_date ASC;"""
    ),
    (
        "8. Dynamic Fine Ledger with Waiver Reasons and Imposing Authority",
        """SELECT 
    f.fine_id, f.borrowing_id, f.amount, f.reason, f.imposed_date, f.status, f.paid_date, f.waiver_reason,
    u.full_name AS member_name, u.email AS member_email,
    staff.full_name AS staff_name,
    bk.title AS book_title, bc.barcode
FROM fines f
JOIN borrowings bw ON f.borrowing_id = bw.borrowing_id
JOIN users u ON bw.user_id = u.user_id
JOIN book_copies bc ON bw.copy_id = bc.copy_id
JOIN books bk ON bc.book_id = bk.book_id
LEFT JOIN users staff ON f.imposed_by = staff.user_id
ORDER BY f.imposed_date DESC;"""
    ),
    (
        "9. Check Out Book Copy (Execute New Loan Transaction)",
        """INSERT INTO borrowings (user_id, copy_id, issue_date, due_date, renewal_count, issued_by)
VALUES (
    :user_id, 
    :copy_id, 
    CURDATE(), 
    DATE_ADD(CURDATE(), INTERVAL (
        SELECT mt.loan_days 
        FROM memberships m 
        JOIN membership_types mt ON m.membership_type_id = mt.membership_type_id 
        WHERE m.user_id = :user_id AND m.status = 'active' 
        LIMIT 1
    ) DAY), 
    0, 
    :staff_id
);

UPDATE book_copies 
SET status = 'borrowed' 
WHERE copy_id = :copy_id;"""
    ),
    (
        "10. Process Book Return & Automated Copy Status Transition",
        """UPDATE borrowings 
SET return_date = CURDATE() 
WHERE borrowing_id = :borrowing_id AND return_date IS NULL;

UPDATE book_copies 
SET status = CASE 
    WHEN EXISTS (
        SELECT 1 FROM reservations r 
        JOIN book_copies bc ON bc.book_id = r.book_id 
        WHERE bc.copy_id = :copy_id AND r.status = 'pending'
    ) THEN 'reserved' 
    ELSE 'available' 
END 
WHERE copy_id = :copy_id;"""
    ),
    (
        "11. Process Loan Renewal with Limit Enforcement",
        """UPDATE borrowings 
SET due_date = DATE_ADD(due_date, INTERVAL 14 DAY),
    renewal_count = renewal_count + 1
WHERE borrowing_id = :borrowing_id 
  AND return_date IS NULL
  AND renewal_count < (
      SELECT mt.renewal_limit 
      FROM memberships m 
      JOIN membership_types mt ON m.membership_type_id = mt.membership_type_id 
      WHERE m.user_id = :user_id AND m.status = 'active'
      LIMIT 1
  );"""
    ),
    (
        "12. Fulfill Book Reservation from Waitlist Queue",
        """UPDATE reservations 
SET status = 'fulfilled' 
WHERE reservation_id = (
    SELECT r.reservation_id FROM (
        SELECT reservation_id 
        FROM reservations 
        WHERE book_id = :book_id AND status = 'pending' 
        ORDER BY reservation_date ASC 
        LIMIT 1
    ) AS r
);"""
    ),
    (
        "13. Register New Book Title into Master Catalog",
        """INSERT INTO books (title, isbn, author_id, category_id, publisher_id, publication_year, language, description)
VALUES (:title, :isbn, :author_id, :category_id, :publisher_id, :pub_year, :language, :description);"""
    ),
    (
        "14. Register Physical Inventory Copy with Barcode and Shelf Location",
        """INSERT INTO book_copies (book_id, barcode, status, location, acquired_date)
VALUES (:book_id, :barcode, 'available', :location, CURDATE());"""
    ),
    (
        "15. Waive Fine with Mandated Reason and Staff Audit Record",
        """UPDATE fines 
SET status = 'waived', 
    waiver_reason = :waiver_reason, 
    imposed_by = :staff_id 
WHERE fine_id = :fine_id AND status = 'pending';"""
    ),
    (
        "16. Record Fine Settlement Payment Transaction",
        """INSERT INTO payments (fine_id, user_id, amount, payment_method, payment_date)
VALUES (:fine_id, :user_id, :amount, :payment_method, NOW());

UPDATE fines 
SET status = 'paid', 
    paid_date = NOW() 
WHERE fine_id = :fine_id;"""
    ),
    (
        "17. Most Borrowed Books Statistical Analytics",
        """SELECT 
    b.book_id, b.title, a.author_name, c.category_name,
    COUNT(bw.borrowing_id) AS borrow_count
FROM borrowings bw
JOIN book_copies bc ON bw.copy_id = bc.copy_id
JOIN books b ON bc.book_id = b.book_id
JOIN authors a ON b.author_id = a.author_id
JOIN categories c ON b.category_id = c.category_id
GROUP BY b.book_id, b.title, a.author_name, c.category_name
ORDER BY borrow_count DESC
LIMIT 10;"""
    ),
    (
        "18. Monthly Fine Revenue & Collection Performance Breakdown",
        """SELECT 
    DATE_FORMAT(p.payment_date, '%Y-%m') AS payment_month,
    COUNT(p.payment_id) AS total_transactions,
    SUM(p.amount) AS total_collected
FROM payments p
GROUP BY DATE_FORMAT(p.payment_date, '%Y-%m')
ORDER BY payment_month DESC;"""
    ),
    (
        "19. Active User Membership Validation & Borrow Limit Check",
        """SELECT 
    u.user_id, u.full_name, u.status AS user_status,
    m.status AS membership_status, mt.type_name, mt.max_books, mt.loan_days,
    (
        SELECT COUNT(*) 
        FROM borrowings bw 
        WHERE bw.user_id = u.user_id AND bw.return_date IS NULL
    ) AS current_borrowed_count
FROM users u
JOIN memberships m ON u.user_id = m.user_id
JOIN membership_types mt ON m.membership_type_id = mt.membership_type_id
WHERE u.user_id = :user_id AND m.status = 'active';"""
    ),
    (
        "20. Audit Trail: Log Sensitive User & Administrative Actions",
        """INSERT INTO activity_logs (user_id, action, details, created_at)
VALUES (:user_id, :action, :details, NOW());"""
    ),
    (
        "21. Fetch Recent System Activity Logs with User Context",
        """SELECT 
    al.log_id, al.action, al.details, al.created_at,
    COALESCE(u.full_name, 'System / Guest') AS actor_name,
    COALESCE(u.role, 'system') AS actor_role
FROM activity_logs al
LEFT JOIN users u ON al.user_id = u.user_id
ORDER BY al.created_at DESC
LIMIT 25;"""
    ),
    (
        "22. Fetch Pending Community Connection Requests for a User",
        """SELECT 
    cc.connection_id, cc.sender_id, cc.created_at,
    u.full_name AS sender_name, u.email AS sender_email
FROM community_connections cc
JOIN users u ON cc.sender_id = u.user_id
WHERE cc.receiver_id = :user_id AND cc.status = 'pending'
ORDER BY cc.created_at DESC;"""
    ),
    (
        "23. Accept Incoming Community Connection Request",
        """UPDATE community_connections 
SET status = 'accepted' 
WHERE connection_id = :conn_id AND receiver_id = :user_id;"""
    ),
    (
        "24. Fetch Active Connected Friends List for a User",
        """SELECT 
    u.user_id, u.full_name, u.email, cc.created_at AS connected_since
FROM users u
JOIN community_connections cc 
  ON (cc.sender_id = u.user_id AND cc.receiver_id = :user_id) 
  OR (cc.receiver_id = u.user_id AND cc.sender_id = :user_id)
WHERE cc.status = 'accepted' AND u.user_id != :user_id
ORDER BY u.full_name ASC;"""
    ),
    (
        "25. Complete Community Book Exchange and Update Book Ownership Status",
        """UPDATE exchange_requests 
SET status = 'completed' 
WHERE exchange_id = :exchange_id;

UPDATE member_books 
SET status = 'exchanged' 
WHERE owned_book_id = (
    SELECT owned_book_id FROM exchange_requests WHERE exchange_id = :exchange_id
);"""
    ),
    (
        "26. Physical Copy Inventory Breakdown by Shelf Location and Status",
        """SELECT 
    COALESCE(location, 'Unassigned') AS shelf_location,
    COUNT(copy_id) AS total_copies,
    SUM(CASE WHEN status = 'available' THEN 1 ELSE 0 END) AS available_copies,
    SUM(CASE WHEN status = 'borrowed' THEN 1 ELSE 0 END) AS borrowed_copies,
    SUM(CASE WHEN status = 'reserved' THEN 1 ELSE 0 END) AS reserved_copies,
    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_copies
FROM book_copies
GROUP BY location
ORDER BY total_copies DESC;"""
    ),
    (
        "27. Category-Wise Distribution of Titles and Physical Copies",
        """SELECT 
    c.category_id, c.category_name,
    COUNT(DISTINCT b.book_id) AS total_titles,
    COUNT(bc.copy_id) AS total_physical_copies
FROM categories c
LEFT JOIN books b ON c.category_id = b.category_id
LEFT JOIN book_copies bc ON b.book_id = bc.book_id
GROUP BY c.category_id, c.category_name
ORDER BY total_titles DESC;"""
    ),
    (
        "28. Active User Fine Liability Summary for Member Clearance",
        """SELECT 
    u.user_id, u.full_name, u.email,
    COUNT(f.fine_id) AS unpaid_fines_count,
    COALESCE(SUM(f.amount), 0.00) AS total_unpaid_amount
FROM users u
JOIN borrowings bw ON u.user_id = bw.user_id
JOIN fines f ON bw.borrowing_id = f.borrowing_id
WHERE f.status = 'pending'
GROUP BY u.user_id, u.full_name, u.email
HAVING total_unpaid_amount > 0
ORDER BY total_unpaid_amount DESC;"""
    ),
    (
        "29. Fetch All Dynamic Global System Configuration Parameters",
        """SELECT setting_key, setting_value, description 
FROM library_settings 
ORDER BY setting_key ASC;"""
    ),
    (
        "30. Update Global Fine Rate & Lending Configuration",
        """UPDATE library_settings 
SET setting_value = :setting_value 
WHERE setting_key = :setting_key;"""
    )
]

for title, sql in queries:
    add_heading_2(title)
    add_code_block(doc, sql)

# -------------------------------------------------------------
# 8. FUTURE WORK
# -------------------------------------------------------------
add_heading_1("Future Work")
add_body_p(
    "To ensure that the Library Management System continues to scale and adapt to modern digital campus standards, the following roadmap "
    "enhancements are planned for future releases:"
)

add_bullet_p(
    "AI-Powered Book Recommendation & Discovery Engine",
    "Integrate machine learning algorithms to analyze user borrowing histories, peer exchange trends, and category preferences to deliver personalized academic reading suggestions."
)
add_bullet_p(
    "Hardware RFID & Barcode Handheld Scanner Integration",
    "Implement WebSocket-based hardware integration with handheld RFID/barcode scanners for instant automated shelf checkouts, drop-box returns, and physical inventory auditing."
)
add_bullet_p(
    "In-App Real-Time Messaging & Video Book Inspection",
    "Develop real-time chat and media sharing capabilities between connected community peers to inspect book condition photos and coordinate physical handovers safely."
)
add_bullet_p(
    "Inter-University Lending & Multi-Campus Consortium",
    "Scale the relational architecture to support multi-campus library networks with federated authentication, inter-library loan logistics, and distributed inventory pooling."
)

# -------------------------------------------------------------
# 9. CONCLUSION
# -------------------------------------------------------------
add_heading_1("Conclusion")
add_body_p(
    "The Library Management & Peer Book Exchange System (LMS) demonstrates the effective application of advanced Database Management System (DBMS) "
    "principles to solve real-world resource circulation and community collaboration challenges. By enforcing strict 3NF schema normalization, "
    "foreign-key constraints, indexed retrieval pipelines, and Role-Based Access Control, the platform guarantees high transactional reliability, "
    "auditability, and data security."
)
add_body_p(
    "Beyond traditional administrative automation, the integration of a verified peer-to-peer book exchange network democratizes access to academic "
    "literature, turning isolated student bookshelves into a dynamic, connected knowledge ecosystem. The standalone Librarian Desk empowers staff with "
    "high-speed circulation workflows, while automated fine computations and transparent waiver logging establish sound financial governance. "
    "In conclusion, LMS serves as a robust, scalable, and socially impactful digital framework that sets a standard for modern institutional resource management."
)

output_path = r"c:\xampp\htdocs\Project\final_dbms_lab\lms\LMS_Project_Report.docx"
doc.save(output_path)
print(f"Report generated successfully at {output_path}")
