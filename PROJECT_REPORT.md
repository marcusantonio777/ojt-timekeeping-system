# PROJECT REPORT & CHANGELOG
## Quest Hotel OJT Management System (Attendance + Requests + HR)
**Last Updated:** May 12, 2026

### 1) What the System Solves
- Centralizes OJT attendance, schedules, approvals (Supervisor → HR), and school/HR documentation.
- Enforces fairness: schedule-based hours, plus OT crediting only after approval.
- Provides accountability: audit trails for critical actions and bottleneck visibility.

### 2) Major Backend + Security Improvements Implemented
- **Removed dangerous public scripts** (account/bootstrap/migration helpers) and blocked sensitive files via [.htaccess](file:///c:/xampp/htdocs/quest_ojt_system/.htaccess).
- **Role-gated admin JSON API** in [api/v1.php](file:///c:/xampp/htdocs/quest_ojt_system/api/v1.php) so trainees cannot access admin metrics.
- **Converted supervisor verification to POST** (no state-changing GET) in [supervisor/dashboard.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/dashboard.php).
- **Hardened session enforcement** by expanding `checkAccess()` usage on key endpoints:
  - Print DTR access control fixed in [print_dtr.php](file:///c:/xampp/htdocs/quest_ojt_system/print_dtr.php)
  - Schedule AJAX access control + supervisor department scope fixed in [get_scheduled_ojts.php](file:///c:/xampp/htdocs/quest_ojt_system/ajax/get_scheduled_ojts.php)
  - Photo JSON endpoint now uses role enforcement in [get_photos.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/get_photos.php)
- **Attendance photo IDOR fix**: `serve_image.php?file=...` now requires `trainee_id` and validates the file belongs to that trainee’s attendance record in [serve_image.php](file:///c:/xampp/htdocs/quest_ojt_system/serve_image.php).
- **CSRF timing-safe compare** using `hash_equals()` in [auth_helper.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php).
- **XSS mitigation in request modals**: added HTML escaping when rendering DB content into modal HTML in:
  - [requests.php](file:///c:/xampp/htdocs/quest_ojt_system/requests.php)
  - [supervisor/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/requests.php)
  - [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)

### 3) Attendance + OT Policy (Enterprise Rule)
- **Strict standard hours** are clamped to shift schedule.
- **Extra time** becomes `pending_ot_seconds` and is not credited until HR approves an OT request.
- **OT crediting is now bounded** to the overlap between approved OT window and actual extra time via:
  - [calculateApprovedOtSeconds()](file:///c:/xampp/htdocs/quest_ojt_system/includes/attendance_helper.php#L212-L279)
  - HR approval logic in [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)

### 4) Requests + Approval Visibility
- HR Approval Queue has clear state views: HR Pending, Supervisor Pending, Bottlenecks, Approved, Rejected, Archive in [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php).
- Rejected requests are now finalized into archive consistently (so “rejected” is visible, not missing).
- Exports are now designed for real compliance needs:
  - Requests: “Export by School” inside the admin requests actions menu.
  - Trainees: “Export School Report” (hours compliance view) in [admin/trainees.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/trainees.php) using [export_trainees.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/export_trainees.php).

### 5) UI/UX Improvements
- Request pages now use dropdown action menus (more advanced UI) instead of scattered action buttons:
  - Trainee: [requests.php](file:///c:/xampp/htdocs/quest_ojt_system/requests.php)
  - Supervisor: [supervisor/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/requests.php)
  - HR: [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)
- Audit logs UI is now scrollable and does not create excessively long pages:
  - Superadmin dashboard audit feed: [superadmin/dashboard.php](file:///c:/xampp/htdocs/quest_ojt_system/superadmin/dashboard.php)
  - HR settings audit table: [admin/settings.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/settings.php)
- Sidebar dashboard routing is now role-aware (prevents dashboard click from redirecting to kiosk) in [main_layout.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/layouts/main_layout.php).

### 6) Database / SQL Readiness
- Auto-migration in [db.php](file:///c:/xampp/htdocs/quest_ojt_system/config/db.php) now includes creation for `trainees` and `attendance` plus missing soft-delete columns (`sanctions.deleted_at`, `audit_logs.deleted_at`) to prevent new-install SQL errors.

### 7) Documentation Produced
- Technical report: [TECHNICAL_REPORT.md](file:///c:/xampp/htdocs/quest_ojt_system/TECHNICAL_REPORT.md)
- Developer log: [DEVELOPER_LOG.md](file:///c:/xampp/htdocs/quest_ojt_system/DEVELOPER_LOG.md)
- GM/Dept Heads guide: [GM_PRESENTATION_REPORT.md](file:///c:/xampp/htdocs/quest_ojt_system/GM_PRESENTATION_REPORT.md)
