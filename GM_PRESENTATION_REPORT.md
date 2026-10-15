# Quest Hotel OJT Management System
## General Manager / Department Heads Brief + Quick Guide

### 1) Executive Summary
The Quest Hotel OJT Management System centralizes trainee attendance, schedules, approvals, and HR communication into one controlled portal. It replaces manual DTR chasing, scattered supervisor approvals, and “he said/she said” overtime situations with a consistent, auditable workflow.

### 2) Why This System Exists (Business Problem)
OJT programs are operationally heavy:
- Supervisors spend time tracking who is on duty, who is absent, and who is late.
- HR spends time chasing paper requests, clarifying OT claims, and consolidating attendance for reporting.
- There is limited real-time visibility for department heads when multiple trainees are distributed across shifts.
- Training compliance requires clean documentation (DTRs, approvals, verification) but collecting it manually is slow and error-prone.

This system was built to make the OJT program measurable, fair, and easy to run across all departments.

### 3) What It Improves (Key Value to Operations)
#### A. Real-time visibility
- Department and HR dashboards show who is currently on duty and which approvals are pending.
- Bottlenecks (requests stuck awaiting supervisor action) are surfaced for accountability.

#### B. Fair and controlled hour-crediting (prevents disputes)
- Standard hours are strictly credited inside the assigned schedule.
- Extra time is recorded but not credited until an approved OT request exists.
- HR approval credits only the OT window that was approved, matching policy and preventing accidental over-crediting.

#### C. Auditability (good governance)
Every critical action (login, approvals, verifications, broadcasts) is logged in an audit trail.
This supports internal governance and makes investigations straightforward if disputes arise.

#### D. Reduced admin workload
Requests, approvals, and exports are centralized. Less follow-up, less duplication, faster reporting.

### 4) How It Works (Simple Workflow)
#### Attendance
1) Trainee Time-In/Time-Out with photo capture (and optional geofence rules).
2) System calculates:
   - Standard hours (within scheduled shift)
   - Extra time (pending OT)
3) Supervisor can verify attendance logs (department-scope).
4) HR finalizes OT credit only when there is an approved OT request.

#### Requests (Leave / OT / Undertime)
1) Trainee submits request with reason and optional attachment.
2) Supervisor approves/rejects (department-scope).
3) HR final approves/rejects and archives the decision.

### 5) Quick Guide by Role
#### For the General Manager
What to check weekly:
- HR dashboard: operational load (active now), pending approvals, and bottlenecks.
- Superadmin dashboard: overall system health, audit activity, and governance toggles.

Best use:
- Use bottleneck visibility as a leadership tool: approvals should not stall operations.

#### For Department Heads
What to check daily:
- Supervisor dashboard: “On duty now”, “Pending requests”, “Pending verification”.
- Supervisor Requests: approve/reject requests quickly to avoid delays to HR.

Best use:
- Treat the dashboard as your daily “shift control panel” for trainees.

#### For Supervisors
Daily actions:
- Approve/Reject trainee requests (Dept Requests).
- Verify attendance logs after reviewing narratives (Pending Verification).
- Post department announcements (Schedule changes, reminders).

#### For HR Admin
Daily actions:
- Approval Queue:
  - HR Pending: finalize approvals after supervisor approval
  - Supervisor Pending / Bottlenecks: monitor stalls and follow up
  - Approved / Rejected / Archive: retrieve finalized records
- Broadcast announcements with image attachments (policy memos, reminders).
- Exports:
  - Export for Records by approval state for tracking and reporting.

#### For Trainees
Daily actions:
- Time-In and Time-Out properly, with narrative required on Time-Out.
- Submit requests before OT is credited.
- Use confidential HR chat when needed for sensitive concerns.

### 6) Reporting to Schools (What Schools Typically Need)
Most schools request:
- A clean Daily Time Record (DTR): date, time-in/out, hours rendered.
- Supervisor verification (sign-off) and department assignment.
- A final summary of total rendered hours vs required hours.

This system supports accurate, exportable documentation and reduces end-of-training paperwork delays.

### 7) Security and Professionalism Notes
- Role-based access control prevents unauthorized pages from being accessed.
- Sensitive files are blocked at the server layer.
- Attachments/photos are served through controlled endpoints with permission checks.
- Audit logs are surfaced for governance without making the UI overly long or unprofessional.

### 8) Suggested “Presentation Talking Points”
- “This system turns OJT into a measurable program, not a manual headache.”
- “It enforces fairness: hours are credited only when policy is followed.”
- “It gives department heads and HR a single source of truth.”
- “It reduces admin time spent chasing forms and clarifying disputes.”

