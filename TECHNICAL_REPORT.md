# Quest Hotel OJT Management System — Technical Report

## 1) System Overview
This application is a PHP + MySQL (PDO) web system intended to manage trainee attendance, schedules, requests (leave/OT/undertime), announcements, and an HR communication channel. The UI uses Tailwind (CDN) and a shared layout renderer.

### Primary Roles
- Trainee: time in/out (kiosk or authenticated), view schedule, submit requests, chat to HR, view progress.
- Supervisor: department-only oversight, approve/reject requests, verify attendance logs, post department announcements.
- Admin (HR): global oversight, final approval of supervisor-approved requests, announcements, trainee management.
- Superadmin: system governance, maintenance mode, global user controls, audit visibility.

## 2) High-Level Architecture
### Runtime Structure
- Routing: file-per-page PHP (no framework/router), with direct entry points under the web root and role folders.
- Data access: PDO via [db.php](file:///c:/xampp/htdocs/quest_ojt_system/config/db.php) (single connection file, shared `$pdo`).
- Auth/session: [auth_helper.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php) (sessions, role access, CSRF, audit logging).
- UI composition: [main_layout.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/layouts/main_layout.php) (renderHeader/renderSidebar/renderFooter).

### Key Entry Points (HTTP)
- Kiosk: [index.php](file:///c:/xampp/htdocs/quest_ojt_system/index.php)
- Login: [login.php](file:///c:/xampp/htdocs/quest_ojt_system/login.php) → [auth_process.php](file:///c:/xampp/htdocs/quest_ojt_system/auth_process.php)
- Attendance POST: [attendance_process.php](file:///c:/xampp/htdocs/quest_ojt_system/attendance_process.php)
- Trainee dashboard: [dashboard.php](file:///c:/xampp/htdocs/quest_ojt_system/dashboard.php)
- Requests:
  - Trainee: [requests.php](file:///c:/xampp/htdocs/quest_ojt_system/requests.php)
  - Supervisor: [supervisor/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/requests.php)
  - HR: [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)
- Messaging: [messages.php](file:///c:/xampp/htdocs/quest_ojt_system/messages.php)
- Secure file proxy (attendance photos + request attachments): [serve_image.php](file:///c:/xampp/htdocs/quest_ojt_system/serve_image.php)
- JSON API (admin-only): [api/v1.php](file:///c:/xampp/htdocs/quest_ojt_system/api/v1.php)

## 3) Database Layer & Schema Management
### Connection and Settings
- Connection uses PDO with `ERRMODE_EXCEPTION` and `EMULATE_PREPARES=false` in [db.php](file:///c:/xampp/htdocs/quest_ojt_system/config/db.php#L33-L38).
- Environment variables are loaded from `.env` if present (DB host/name/user/pass and SMTP settings).

### Auto-Migration
- Core tables are created if missing, and selected columns/indexes are added dynamically at runtime in [db.php](file:///c:/xampp/htdocs/quest_ojt_system/config/db.php#L43-L228).
- This ensures a deploy can self-heal missing schema pieces, but should be treated as a controlled “bootstrap migration” approach (enterprise deployments typically move this into one-time migrations).

## 4) Core Business Workflows
### 4.1 Authentication, Session Integrity, and Access Control
- Login verifies password hashes and sets `session_token` in DB to allow server-side invalidation in [login()](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L12-L43).
- `checkAccess()` enforces role access and idle session timeout in [auth_helper.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L98-L133).
- CSRF token generation + validation is provided by [generateCsrfToken()](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L138-L143) and [verifyCsrfToken()](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L145-L150).

### 4.2 Attendance (Strict Schedule Lock + Pending OT)
- Attendance is processed by [processAttendance()](file:///c:/xampp/htdocs/quest_ojt_system/includes/attendance_helper.php#L21-L210).
- Time-in:
  - prevents duplicate active sessions per day
  - optional geofence check based on system settings
  - marks late based on schedule start + a configurable grace window
- Time-out:
  - clamps “standard seconds” inside the shift window (effective_in/effective_out)
  - computes extra time as `pending_ot_seconds` (before shift and/or after shift, or full duration on day-off)
  - writes `hours_worked` (standard only) and keeps “extra time” pending until HR-approved OT

### 4.3 Requests and Approvals (Supervisor → HR)
- Request submission (trainee): [requests.php](file:///c:/xampp/htdocs/quest_ojt_system/requests.php)
- Supervisor approval (department-scoped): [supervisor/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/requests.php)
- HR final approval: [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)

Approval state fields (in `requests` table):
- `supervisor_status`: pending/approved/rejected
- `hr_status`: pending/approved/rejected
- `archived_at`: marks finalized records

OT credit policy (enterprise rule):
- Extra time is stored as `pending_ot_seconds`.
- When HR approves an OT request, only the overlap between the OT request window and actual “extra time” is converted into `verified_ot_seconds` via [calculateApprovedOtSeconds()](file:///c:/xampp/htdocs/quest_ojt_system/includes/attendance_helper.php#L212-L279).
- Trainee “Rendered Hours” uses standard hours + verified OT only, preventing unapproved loitering from being credited.

### 4.4 Confidential HR Messaging
- Messaging UI and logic are in [messages.php](file:///c:/xampp/htdocs/quest_ojt_system/messages.php).
- Trainees are routed to a preferred HR/admin contact (prioritizing department = Human Resource when available).
- Admins can only message trainees (receiver validation enforced).

### 4.5 Announcements (with Image Uploads)
- Stored in `system_notifications` with optional `image_path`.
- Upload is validated as a real image (mime + getimagesize), size-limited, and stored under `uploads/announcements` via [handleUploadedImage()](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L176-L220).

## 5) Security Controls Summary
- SQL injection mitigation: prepared statements and parameter binding are used for user-input queries.
- CSRF: token generation + POST validation across key mutating actions.
- XSS: server-side `e()` helper for HTML contexts and client-side escaping in request modals to prevent stored-XSS from “reason/note” fields.
- Secure file access: photos/attachments are served only through [serve_image.php](file:///c:/xampp/htdocs/quest_ojt_system/serve_image.php) with role checks.
- Sensitive file blocking: web root `.htaccess` blocks `.env`, dumps, and removed maintenance scripts.

## 6) Operational Notes
- For production, move `.env` outside the document root and enforce HTTPS.
- Centralize repeated JS modal rendering and use a shared “escapeHtml” utility consistently across pages that render DB content into HTML templates.

