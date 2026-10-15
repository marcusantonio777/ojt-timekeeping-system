# Developer Log — Audit Findings and Fixes

This log records issues found during the project audit and the fixes applied to elevate the system toward an enterprise-ready baseline.

## Security & Hardening
### Removed publicly reachable “danger scripts”
- Issue: Public web-root scripts could create accounts or mutate schema without authentication.
- Fix:
  - Deleted:
    - `create_admin.php` (contained hardcoded DB creds and a known default password)
    - `apply_migration.php`
    - `check_db.php`

### Blocked sensitive files at the web server layer
- Issue: `.env` and SQL dumps exist in the web root and are dangerous if ever served.
- Fix:
  - Added [/.htaccess](file:///c:/xampp/htdocs/quest_ojt_system/.htaccess) to deny access to `.env`, SQL dumps, and removed maintenance scripts.

### Upload folder execution hardening
- Issue: Announcements upload directory did not exist and did not have explicit execution blocking.
- Fix:
  - Created `uploads/announcements/` and added [uploads/announcements/.htaccess](file:///c:/xampp/htdocs/quest_ojt_system/uploads/announcements/.htaccess) to deny script-like extensions.

### API authorization tightened
- Issue: [api/v1.php](file:///c:/xampp/htdocs/quest_ojt_system/api/v1.php) required only a session, allowing trainees to access admin-style metrics.
- Fix:
  - Added role-gating (admin/superadmin only) for metrics endpoints in [api/v1.php](file:///c:/xampp/htdocs/quest_ojt_system/api/v1.php).

### CSRF verification hardened
- Issue: CSRF token comparison used direct equality.
- Fix:
  - Updated [verifyCsrfToken()](file:///c:/xampp/htdocs/quest_ojt_system/includes/auth/auth_helper.php#L145-L150) to use `hash_equals`.

### Stored-XSS reductions in request modals
- Issue: “Reason” and “Notes” fields were inserted into SweetAlert HTML via template strings without HTML escaping.
- Fix:
  - Added client-side escaping and applied it to modal renderers in:
    - [requests.php](file:///c:/xampp/htdocs/quest_ojt_system/requests.php)
    - [supervisor/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/supervisor/requests.php)
    - [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)

## Attendance & OT Policy Enforcement
### Time-out request type mismatch
- Issue: [attendance_process.php](file:///c:/xampp/htdocs/quest_ojt_system/attendance_process.php) required narrative for `time_out`, but kiosk submits `out`.
- Fix:
  - Added `time_in`/`time_out` → `in`/`out` normalization and enforced narrative for `out`.

### OT credit bounded to approved OT window
- Issue: HR approval previously moved all `pending_ot_seconds` to verified OT for the date, potentially over-crediting.
- Fix:
  - Implemented [calculateApprovedOtSeconds()](file:///c:/xampp/htdocs/quest_ojt_system/includes/attendance_helper.php#L212-L279) to approve only the overlap between:
    - the OT request time window
    - the actual “extra time” outside the scheduled shift
  - Updated HR approval logic to move only the approved seconds in [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php).

## Approval Visibility (HR/Superadmin)
### Real-time visibility for pending supervisor approval
- Issue: HR had limited visibility into “pending supervisor” items (only the >48h delayed subset was highlighted).
- Fix:
  - Added a global count metric and a dedicated queue view via filter in:
    - [admin/dashboard.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/dashboard.php)
    - [admin/requests.php](file:///c:/xampp/htdocs/quest_ojt_system/admin/requests.php)

## Confidential HR Messaging
### Routing and UX truthfulness
- Issue: Trainees were routed to an arbitrary first admin; UI claimed end-to-end encryption though messages are stored plaintext in DB.
- Fix:
  - Prefer HR department admin/superadmin as the default contact in [messages.php](file:///c:/xampp/htdocs/quest_ojt_system/messages.php).
  - Removed misleading “End-to-End Encrypted” wording in the UI and replaced it with access-control language.

## UI/UX
### Duplicate head injection
- Issue: [renderHeader()](file:///c:/xampp/htdocs/quest_ojt_system/includes/layouts/main_layout.php#L6-L19) printed `$extra_head` twice.
- Fix:
  - Removed the duplicate output in [main_layout.php](file:///c:/xampp/htdocs/quest_ojt_system/includes/layouts/main_layout.php).

## Verification
- PHP syntax linting was run against modified files using `C:\xampp\php\php.exe -l` and passed with no syntax errors.
