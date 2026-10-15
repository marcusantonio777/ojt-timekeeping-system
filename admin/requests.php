<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/attendance_helper.php';
require_once '../includes/layouts/main_layout.php';
require_once __DIR__.'/../components/requests_ui.php';

checkAccess('admin, superadmin');

$user_id = $_SESSION['user_id'];
$csrf_token = generateCsrfToken();

if (isset($_GET['export']) && $_GET['export'] === 'requests_csv') {
    $dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $scope = isset($_GET['scope']) ? trim($_GET['scope']) : '';
    $group = isset($_GET['group']) ? trim((string)$_GET['group']) : '';
    $from = isset($_GET['from']) ? trim($_GET['from']) : '';
    $to = isset($_GET['to']) ? trim($_GET['to']) : '';

    $allowedScopes = ['hr_pending', 'supervisor_pending', 'approved', 'rejected', 'archived', 'delayed'];
    if (!in_array($scope, $allowedScopes, true)) {
        $scope = 'hr_pending';
    }

    $query = "SELECT r.id, r.type, r.start_date, r.end_date, r.start_time, r.end_time, r.reason,
                     r.supervisor_status, r.supervisor_note, r.supervisor_approved_at,
                     r.hr_status, r.hr_note,
                     r.created_at, r.archived_at,
                     t.username, t.first_name, t.last_name, t.department, t.school
              FROM requests r
              JOIN trainees t ON r.trainee_id = t.id
              WHERE r.deleted_at IS NULL AND t.deleted_at IS NULL";
    $params = [];

    if ($scope === 'hr_pending') {
        $query .= " AND r.supervisor_status = 'approved' AND r.hr_status = 'pending' AND r.archived_at IS NULL";
    } elseif ($scope === 'supervisor_pending') {
        $query .= " AND r.supervisor_status = 'pending' AND r.archived_at IS NULL";
    } elseif ($scope === 'delayed') {
        $query .= " AND r.supervisor_status = 'pending' AND r.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) AND r.archived_at IS NULL";
    } elseif ($scope === 'approved') {
        $query .= " AND r.hr_status = 'approved' AND r.archived_at IS NOT NULL";
    } elseif ($scope === 'rejected') {
        $query .= " AND r.hr_status = 'rejected' AND r.archived_at IS NOT NULL";
    } elseif ($scope === 'archived') {
        $query .= " AND r.archived_at IS NOT NULL";
    }
    if ($dept !== '') {
        $query .= " AND t.department = ?";
        $params[] = $dept;
    }
    if ($type !== '' && in_array($type, ['leave', 'overtime', 'undertime'], true)) {
        $query .= " AND r.type = ?";
        $params[] = $type;
    }
    if ($from !== '') {
        $query .= " AND r.start_date >= ?";
        $params[] = $from;
    }
    if ($to !== '') {
        $query .= " AND r.start_date <= ?";
        $params[] = $to;
    }
    if ($group === 'school') {
        $query .= " ORDER BY t.school ASC, t.department ASC, t.last_name ASC, t.first_name ASC, r.start_date ASC, r.created_at ASC";
    } else {
        $query .= " ORDER BY r.created_at DESC";
    }
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    $filenamePrefix = $group === 'school' ? 'school_requests' : 'requests';
    header('Content-Disposition: attachment; filename=' . $filenamePrefix . '_' . $scope . '_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    if ($group === 'school') {
        fputcsv($out, ['school', 'trainee_name', 'trainee_id', 'department', 'request_type', 'start_date', 'end_date', 'start_time', 'end_time', 'supervisor_status', 'supervisor_approved_at', 'hr_status', 'decision_date', 'reason', 'supervisor_note', 'hr_note']);
    } else {
        fputcsv($out, ['id', 'trainee_username', 'trainee_name', 'school', 'department', 'type', 'start_date', 'end_date', 'start_time', 'end_time', 'supervisor_status', 'supervisor_approved_at', 'supervisor_note', 'hr_status', 'hr_note', 'created_at', 'archived_at', 'reason']);
    }
    foreach ($rows as $r) {
        if ($group === 'school') {
            fputcsv($out, [
                $r['school'],
                trim($r['first_name'] . ' ' . $r['last_name']),
                $r['username'],
                $r['department'],
                $r['type'],
                $r['start_date'],
                $r['end_date'],
                $r['start_time'],
                $r['end_time'],
                $r['supervisor_status'],
                $r['supervisor_approved_at'],
                $r['hr_status'],
                $r['archived_at'],
                $r['reason'],
                $r['supervisor_note'],
                $r['hr_note']
            ]);
        } else {
            fputcsv($out, [
                $r['id'],
                $r['username'],
                trim($r['first_name'] . ' ' . $r['last_name']),
                $r['school'],
                $r['department'],
                $r['type'],
                $r['start_date'],
                $r['end_date'],
                $r['start_time'],
                $r['end_time'],
                $r['supervisor_status'],
                $r['supervisor_approved_at'],
                $r['supervisor_note'],
                $r['hr_status'],
                $r['hr_note'],
                $r['created_at'],
                $r['archived_at'],
                $r['reason']
            ]);
        }
    }
    fclose($out);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: requests.php?error=csrf_failed");
        exit();
    }

    if ($_POST['action'] === 'approve_request') {
        $req_id = (int) $_POST['request_id'];
        $note = trim($_POST['note'] ?? '');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE requests SET hr_status = 'approved', hr_id = ?, hr_note = ?, archived_at = CURRENT_TIMESTAMP WHERE id = ? AND supervisor_status = 'approved' AND archived_at IS NULL");
            $stmt->execute([$user_id, $note, $req_id]);

            // --- OT CREDIT LOGIC ---
            // Fetch request details
            $stmt = $pdo->prepare("SELECT trainee_id, type, start_date, start_time, end_time FROM requests WHERE id = ?");
            $stmt->execute([$req_id]);
            $req = $stmt->fetch();

            if ($req && $req['type'] === 'overtime') {
                $approvedSeconds = calculateApprovedOtSeconds($pdo, (int)$req['trainee_id'], (string)$req['start_date'], (string)($req['start_time'] ?? ''), (string)($req['end_time'] ?? ''));
                if ($approvedSeconds > 0) {
                    $stmt = $pdo->prepare("
                        UPDATE attendance
                        SET verified_ot_seconds = verified_ot_seconds + ?,
                            pending_ot_seconds = CASE WHEN pending_ot_seconds >= ? THEN pending_ot_seconds - ? ELSE 0 END
                        WHERE trainee_id = ? AND date = ? AND deleted_at IS NULL
                    ");
                    $stmt->execute([$approvedSeconds, $approvedSeconds, $approvedSeconds, (int)$req['trainee_id'], (string)$req['start_date']]);
                }
            }

            $pdo->commit();
            logAudit($pdo, $user_id, 'HR_APPROVE', "HR Approved request ID $req_id");
            
            // Email Notification
            try {
                $stmt = $pdo->prepare("SELECT r.type, r.start_date, r.end_date, r.start_time, r.end_time, t.email FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE r.id = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL");
                $stmt->execute([$req_id]);
                $row = $stmt->fetch();
                if ($row && !empty($row['email'])) {
                    $subject = "Request approved by HR ({$row['type']})";
                    $body = "Your request has been approved by HR.\n\nType: {$row['type']}\nDate: {$row['start_date']}" . ($row['end_date'] ? " to {$row['end_date']}" : "") . "\n" . ($row['start_time'] ? "Time: {$row['start_time']} - {$row['end_time']}\n" : "") . ($note ? "HR Note: {$note}\n" : "");
                    enqueueSystemEmail($row['email'], $subject, $body);
                }
            } catch (Exception $e) {}
            
            header("Location: requests.php?status=approved");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: requests.php?error=db_error");
            exit();
        }
    }

    if ($_POST['action'] === 'reject_request') {
        $req_id = (int) $_POST['request_id'];
        $note = trim($_POST['note'] ?? '');

        $stmt = $pdo->prepare("UPDATE requests SET hr_status = 'rejected', hr_id = ?, hr_note = ?, archived_at = CURRENT_TIMESTAMP WHERE id = ? AND supervisor_status = 'approved' AND archived_at IS NULL");
        $stmt->execute([$user_id, $note, $req_id]);

        logAudit($pdo, $user_id, 'HR_REJECT', "HR Rejected request ID $req_id");
        try {
            $stmt = $pdo->prepare("SELECT r.type, r.start_date, r.end_date, r.start_time, r.end_time, t.email FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE r.id = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL");
            $stmt->execute([$req_id]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                $subject = "Request rejected by HR ({$row['type']})";
                $body = "Your request has been rejected by HR.\n\nType: {$row['type']}\nDate: {$row['start_date']}" . ($row['end_date'] ? " to {$row['end_date']}" : "") . "\n" . ($row['start_time'] ? "Time: {$row['start_time']} - {$row['end_time']}\n" : "") . ($note ? "HR Note: {$note}\n" : "");
                enqueueSystemEmail($row['email'], $subject, $body);
            }
        } catch (Exception $e) {
        }
        header("Location: requests.php?status=rejected");
        exit();
    }

    if ($_POST['action'] === 'bulk_approve_requests') {
        $ids = $_POST['request_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_filter(array_map('intval', $ids));
        $note = trim($_POST['bulk_note'] ?? '');
        
        if (!empty($ids)) {
            $pdo->beginTransaction();
            try {
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                
                // 1. Get OT requests in this batch
                $stmt = $pdo->prepare("SELECT trainee_id, start_date, start_time, end_time FROM requests WHERE id IN ($placeholders) AND type = 'overtime' AND supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL");
                $stmt->execute($ids);
                $ot_requests = $stmt->fetchAll();

                // 2. Update status
                $sql = "UPDATE requests SET hr_status = 'approved', hr_id = ?, hr_note = ?, archived_at = CURRENT_TIMESTAMP
                    WHERE id IN ($placeholders) AND supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL";
                $params = array_merge([$user_id, $note], $ids);
                $pdo->prepare($sql)->execute($params);

                // 3. Credit OT hours for each
                foreach ($ot_requests as $req) {
                    $approvedSeconds = calculateApprovedOtSeconds($pdo, (int)$req['trainee_id'], (string)$req['start_date'], (string)($req['start_time'] ?? ''), (string)($req['end_time'] ?? ''));
                    if ($approvedSeconds > 0) {
                        $stmt = $pdo->prepare("
                            UPDATE attendance
                            SET verified_ot_seconds = verified_ot_seconds + ?,
                                pending_ot_seconds = CASE WHEN pending_ot_seconds >= ? THEN pending_ot_seconds - ? ELSE 0 END
                            WHERE trainee_id = ? AND date = ? AND deleted_at IS NULL
                        ");
                        $stmt->execute([$approvedSeconds, $approvedSeconds, $approvedSeconds, (int)$req['trainee_id'], (string)$req['start_date']]);
                    }
                }

                $pdo->commit();
                logAudit($pdo, $user_id, 'HR_BULK_APPROVE', 'Bulk approved requests: ' . implode(',', $ids));
                header("Location: requests.php?status=bulk_approved");
                exit();
            } catch (Exception $e) {
                $pdo->rollBack();
                header("Location: requests.php?error=db_error");
                exit();
            }
        }
    }

    if ($_POST['action'] === 'bulk_reject_requests') {
        $ids = $_POST['request_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_filter(array_map('intval', $ids));
        $note = trim($_POST['bulk_note'] ?? '');
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE requests SET hr_status = 'rejected', hr_id = ?, hr_note = ?, archived_at = CURRENT_TIMESTAMP
                WHERE id IN ($placeholders) AND supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL";
            $params = array_merge([$user_id, $note], $ids);
            $pdo->prepare($sql)->execute($params);
            logAudit($pdo, $user_id, 'HR_BULK_REJECT', 'Bulk rejected requests: ' . implode(',', $ids));
        }
        header("Location: requests.php?status=bulk_rejected");
        exit();
    }
}

$filter = isset($_GET['filter']) ? trim((string)$_GET['filter']) : '';
$allowedFilters = ['', 'hr_pending', 'supervisor_pending', 'delayed', 'approved', 'rejected', 'archived'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = '';
}
$filterView = $filter === '' ? 'hr_pending' : $filter;

$countHrPending = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn() ?: 0);
$countSupervisorPending = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn() ?: 0);
$countDelayed = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'pending' AND created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn() ?: 0);
$countApproved = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE hr_status = 'approved' AND deleted_at IS NULL AND archived_at IS NOT NULL")->fetchColumn() ?: 0);
$countRejected = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE hr_status = 'rejected' AND deleted_at IS NULL AND archived_at IS NOT NULL")->fetchColumn() ?: 0);
$countThisMonth = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn() ?: 0);

$query = "
    SELECT r.*, t.first_name, t.last_name, t.username, t.department
    FROM requests r
    JOIN trainees t ON r.trainee_id = t.id
    WHERE r.deleted_at IS NULL AND t.deleted_at IS NULL
";

if ($filterView === 'supervisor_pending') {
    $query .= " AND r.supervisor_status = 'pending' AND r.archived_at IS NULL";
} elseif ($filterView === 'delayed') {
    $query .= " AND r.supervisor_status = 'pending' AND r.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR) AND r.archived_at IS NULL";
} elseif ($filterView === 'approved') {
    $query .= " AND r.hr_status = 'approved' AND r.archived_at IS NOT NULL";
} elseif ($filterView === 'rejected') {
    $query .= " AND r.hr_status = 'rejected' AND r.archived_at IS NOT NULL";
} elseif ($filterView === 'archived') {
    $query .= " AND r.archived_at IS NOT NULL";
} else {
    $query .= " AND r.supervisor_status = 'approved' AND r.hr_status = 'pending' AND r.archived_at IS NULL";
}

$query .= " ORDER BY r.created_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute();
$hr_requests = $stmt->fetchAll();

$archived_requests = [];
if (in_array($filterView, ['hr_pending', 'supervisor_pending', 'delayed'], true)) {
    $stmt = $pdo->query("
        SELECT r.*, t.first_name, t.last_name, t.username, t.department
        FROM requests r
        JOIN trainees t ON r.trainee_id = t.id
        WHERE r.archived_at IS NOT NULL AND r.deleted_at IS NULL AND t.deleted_at IS NULL
        ORDER BY r.archived_at DESC
        LIMIT 50
    ");
    $archived_requests = $stmt->fetchAll();
}

$pageTitle = 'Approval Queue';
if ($filterView === 'hr_pending') $pageTitle = 'HR Final Approval';
if ($filterView === 'supervisor_pending') $pageTitle = 'Pending Supervisor Approval';
if ($filterView === 'delayed') $pageTitle = 'Supervisor Bottlenecks';
if ($filterView === 'approved') $pageTitle = 'Approved Requests';
if ($filterView === 'rejected') $pageTitle = 'Rejected Requests';
if ($filterView === 'archived') $pageTitle = 'Archive';
renderHeader($pageTitle);
renderSidebar('requests');
?>

<div class="min-h-screen bg-slate-50 pb-24">

<?php echo requestFlashBanner(); ?>

<div class="relative overflow-hidden bg-gradient-to-br from-[#6D3089] via-[#8B4CAA] to-[#5A2771]">
    <div class="absolute inset-0 opacity-20">
        <div class="absolute -top-24 -right-24 h-64 w-64 rounded-full bg-white/20 blur-3xl"></div>
        <div class="absolute -bottom-16 -left-16 h-48 w-48 rounded-full bg-amber-300/20 blur-3xl"></div>
    </div>
    <div class="relative px-6 lg:px-10 py-8">
        <div class="flex flex-wrap items-center justify-between gap-6">
            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-3 mb-3">
                    <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-white/15 backdrop-blur-sm border border-white/20 text-white text-[10px] font-black uppercase tracking-[0.22em]">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        HR Workspace
                    </span>
                    <nav class="flex items-center gap-2 text-[11px] font-semibold text-white/70">
                        <a href="index.php" class="hover:text-white transition-colors">Admin Home</a>
                        <svg class="w-3 h-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        <span class="text-white">HR Queue</span>
                    </nav>
                </div>
                <h1 class="text-3xl lg:text-4xl font-black tracking-tight text-white mb-2"><?php echo $pageTitle; ?></h1>
                <p class="text-sm text-white/80 font-medium max-w-xl leading-relaxed">2-stage final review for supervisor-endorsed requests</p>
            </div>
            <div class="flex items-center gap-3 flex-shrink-0">
                <a href="requests.php?export=requests_csv&scope=<?php echo urlencode($filterView); ?>&group=school" class="inline-flex items-center gap-2 px-5 py-3 rounded-2xl bg-white text-[#6D3089] text-[11px] font-black uppercase tracking-[0.2em] shadow-lg shadow-black/10 hover:shadow-xl hover:bg-white/95 hover:-translate-y-0.5 transition-all">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Export CSV
                </a>
                <a href="requests.php<?php echo $filter !== '' ? '?filter='.urlencode($filter) : ''; ?>" class="inline-flex items-center justify-center w-12 h-12 rounded-2xl bg-white/15 backdrop-blur-sm border border-white/20 text-white hover:bg-white/25 hover:rotate-180 transition-all duration-500" title="Refresh">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                </a>
            </div>
        </div>
    </div>
</div>

<div class="px-6 lg:px-10 -mt-6 relative z-10">
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
        <?php echo requestKpiTile('HR Pending', $countHrPending, 'Supervisor-endorsed, waiting final sign-off', 'purple', 'doc'); ?>
        <?php echo requestKpiTile('Supervisor Queue', $countSupervisorPending, 'Awaiting department endorsement', 'amber', 'doc'); ?>
        <?php echo requestKpiTile('Bottlenecks', $countDelayed, 'Pending > 48h at supervisor stage', 'rose', 'doc'); ?>
        <?php echo requestKpiTile('Approved', $countApproved, 'Finalized approvals archived', 'emerald', 'doc'); ?>
        <?php echo requestKpiTile('Rejected', $countRejected, 'HR-returned or declined requests', 'slate', 'doc'); ?>
        <?php echo requestKpiTile('This Month', $countThisMonth, 'All requests filed in last 30 days', 'blue', 'doc'); ?>
    </div>
</div>

<div class="px-6 lg:px-10 mt-8 space-y-6">

    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="px-5 lg:px-7 pt-5 lg:pt-6 pb-4 border-b border-slate-100">
            <div class="flex flex-wrap items-center gap-2 mb-5">
                <a href="requests.php" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'hr_pending' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200'; ?>">
                    HR Pending
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[9px] font-black <?php echo $filterView === 'hr_pending' ? 'bg-white/20 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'; ?>"><?php echo $countHrPending; ?></span>
                </a>
                <a href="requests.php?filter=supervisor_pending" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'supervisor_pending' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200'; ?>">
                    Supervisor Pending
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[9px] font-black <?php echo $filterView === 'supervisor_pending' ? 'bg-white/20 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'; ?>"><?php echo $countSupervisorPending; ?></span>
                </a>
                <a href="requests.php?filter=delayed" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'delayed' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-100'; ?>">
                    Delayed
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[9px] font-black <?php echo $filterView === 'delayed' ? 'bg-white/20 text-white' : 'bg-white text-rose-600 ring-1 ring-rose-100'; ?>"><?php echo $countDelayed; ?></span>
                </a>
                <a href="requests.php?filter=approved" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'approved' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-emerald-50 text-emerald-600 hover:bg-emerald-100 border border-emerald-100'; ?>">
                    Approved
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[9px] font-black <?php echo $filterView === 'approved' ? 'bg-white/20 text-white' : 'bg-white text-emerald-600 ring-1 ring-emerald-100'; ?>"><?php echo $countApproved; ?></span>
                </a>
                <a href="requests.php?filter=rejected" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'rejected' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-rose-50 text-rose-600 hover:bg-rose-100 border border-rose-100'; ?>">
                    Rejected
                    <span class="inline-flex items-center justify-center min-w-[22px] h-[22px] px-1.5 rounded-full text-[9px] font-black <?php echo $filterView === 'rejected' ? 'bg-white/20 text-white' : 'bg-white text-rose-600 ring-1 ring-rose-100'; ?>"><?php echo $countRejected; ?></span>
                </a>
                <a href="requests.php?filter=archived" class="group inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all <?php echo $filterView === 'archived' ? 'bg-[#6D3089] text-white shadow-lg shadow-[#6D3089]/25' : 'bg-slate-50 text-slate-600 hover:bg-slate-100 border border-slate-200'; ?>">
                    Archived
                </a>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-center">
                <div class="md:col-span-4 relative">
                    <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input type="text" id="toolbarSearch" placeholder="Search name, department..." class="w-full pl-11 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white transition-all">
                </div>
                <div class="md:col-span-2">
                    <select id="toolbarType" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white transition-all appearance-none cursor-pointer">
                        <option value="">All Types</option>
                        <option value="leave">Leave</option>
                        <option value="overtime">Overtime</option>
                        <option value="undertime">Undertime</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <input type="date" id="toolbarFrom" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white transition-all">
                </div>
                <div class="md:col-span-2">
                    <input type="date" id="toolbarTo" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-2xl text-xs font-semibold text-slate-700 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white transition-all">
                </div>
                <div class="md:col-span-2 flex items-center gap-2">
                    <button type="button" id="toolbarClear" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-slate-100 text-slate-600 text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 transition-all flex-1 justify-center">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Clear
                    </button>
                    <button type="button" id="toolbarSearchBtn" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-2xl bg-[#6D3089] text-white text-[10px] font-black uppercase tracking-widest hover:bg-[#5a2771] shadow-lg shadow-[#6D3089]/20 transition-all flex-1 justify-center">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        Go
                    </button>
                </div>
            </div>
        </div>

        <form id="bulk_hr_form" method="post" class="hidden">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" id="bulk_hr_action" value="">
            <input type="hidden" name="bulk_note" id="bulk_hr_note" value="">
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left" id="requestsTable">
                <thead class="sticky top-0 z-20">
                    <tr class="bg-gradient-to-r from-slate-50 to-slate-100/80 backdrop-blur border-b border-slate-200">
                        <th class="px-4 py-3.5 w-12 first:rounded-tl-2xl">
                            <label class="inline-flex items-center">
                                <input type="checkbox" id="selectAllToggle" class="w-4 h-4 rounded border-slate-300 text-[#6D3089] focus:ring-[#6D3089] cursor-pointer">
                            </label>
                        </th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em]">Request</th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em]">Approval Flow</th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em]">Date / Time</th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em]">Reason</th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em]">Age</th>
                        <th class="px-5 py-3.5 text-[10px] font-black text-slate-500 uppercase tracking-[0.22em] text-right last:rounded-tr-2xl">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($hr_requests)): ?>
                        <tr>
                            <td colspan="7" class="px-8 py-14">
                                <?php
                                    $emptyTitle = 'No requests pending';
                                    $emptySub = 'Requests will appear here once trainees submit and supervisors endorse.';
                                    if ($filterView === 'delayed') { $emptyTitle = 'No bottlenecks detected'; $emptySub = 'All supervisor queues are within the 48-hour SLA window.'; }
                                    if ($filterView === 'supervisor_pending') { $emptyTitle = 'No supervisor-pending requests'; $emptySub = 'Nothing is currently waiting for department review.'; }
                                    if ($filterView === 'approved') { $emptyTitle = 'No approvals yet'; $emptySub = 'Approved requests will be archived here for audit trail.'; }
                                    if ($filterView === 'rejected') { $emptyTitle = 'No rejections'; $emptySub = 'Declined or returned requests will land here.'; }
                                    if ($filterView === 'archived') { $emptyTitle = 'Archive is empty'; $emptySub = 'Finalized requests will accumulate here over time.'; }
                                    echo requestEmptyState($emptyTitle, $emptySub, $filterView === 'delayed' ? 'rose' : ($filterView === 'approved' ? 'emerald' : 'purple'));
                                ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($hr_requests as $idx => $r):
                        $createdTime = strtotime($r['created_at']);
                        $ageHours = (time() - $createdTime) / 3600;
                        $isDelayed = $ageHours > 48;
                        $ageDays = floor($ageHours / 24);
                        $ageHoursRem = floor($ageHours % 24);
                        $ageLabel = $ageDays > 0 ? $ageDays.'d '.$ageHoursRem.'h' : ($ageHoursRem > 0 ? floor($ageHoursRem).'h' : '<1h');
                        $rowNum = $idx;
                    ?>
                    <tr class="group/row even:bg-slate-50/40 hover:bg-purple-50/30 hover:shadow-[inset_4px_0_0_0_#6D3089] transition-all cursor-pointer request-row"
                        data-row="<?php echo htmlspecialchars(json_encode($r)); ?>"
                        data-search="<?php echo htmlspecialchars(strtolower($r['first_name'].' '.$r['last_name'].' '.$r['department'].' '.$r['username'].' '.$r['type'])); ?>"
                        data-type="<?php echo htmlspecialchars($r['type']); ?>"
                        data-date="<?php echo htmlspecialchars($r['start_date']); ?>"
                        onclick="handleRowClick(event, <?php echo $rowNum; ?>)">
                        <td class="px-4 py-3.5 align-top" onclick="event.stopPropagation()">
                            <?php if ($r['hr_status'] === 'pending' && $filterView === 'hr_pending'): ?>
                                <label class="inline-flex items-center">
                                    <input type="checkbox" name="request_ids[]" value="<?php echo (int) $r['id']; ?>" form="bulk_hr_form" class="hr_cb w-4 h-4 rounded border-slate-300 text-[#6D3089] focus:ring-[#6D3089] cursor-pointer" data-hr-pending="1" onchange="updateBatchToolbar()">
                                </label>
                            <?php else: ?>
                                <span class="block w-4 h-4"></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3.5 align-top min-w-[260px]">
                            <div class="flex items-start gap-3">
                                <div class="flex-shrink-0 pt-0.5">
                                    <?php echo requestTypeBadge($r['type'], 'md'); ?>
                                </div>
                                <div class="min-w-0 flex-1">
                                    <p class="text-sm font-black text-slate-800 leading-tight mb-0.5 truncate"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></p>
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 text-[9px] font-black uppercase tracking-widest border border-slate-200"><?php echo htmlspecialchars($r['department']); ?></span>
                                        <span class="text-[10px] font-bold text-slate-400 truncate">@<?php echo htmlspecialchars($r['username']); ?></span>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 align-top min-w-[280px]">
                            <?php echo requestApprovalStepper($r['supervisor_status'], $r['hr_status'], true); ?>
                        </td>
                        <td class="px-5 py-3.5 align-top min-w-[170px]">
                            <div class="space-y-0.5">
                                <p class="text-xs font-bold text-slate-800 leading-tight"><?php echo date('M d', strtotime($r['start_date'])); ?><?php echo $r['end_date'] && $r['end_date'] !== $r['start_date'] ? ' → '.date('M d, Y', strtotime($r['end_date'])) : ', '.date('Y', strtotime($r['start_date'])); ?></p>
                                <?php if ($r['start_time']): ?>
                                    <p class="text-[10px] font-semibold text-slate-500 uppercase tracking-wide"><?php echo date('h:i A', strtotime($r['start_time'])); ?> – <?php echo date('h:i A', strtotime($r['end_time'])); ?></p>
                                <?php endif; ?>
                                <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1 opacity-70">Filed <?php echo date('M d, h:i A', strtotime($r['created_at'])); ?></p>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 align-top min-w-[220px]">
                            <div class="group/reason">
                                <p class="text-xs text-slate-600 font-medium leading-snug line-clamp-1 truncate group-hover/reason:line-clamp-none group-hover/reason:whitespace-normal group-hover/reason:break-words transition-all" title="<?php echo htmlspecialchars($r['reason']); ?>">
                                    <?php echo htmlspecialchars($r['reason'] ?: '—'); ?>
                                </p>
                            </div>
                        </td>
                        <td class="px-5 py-3.5 align-top min-w-[110px]">
                            <?php if ($isDelayed): ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-rose-50 text-rose-600 border border-rose-100 text-[10px] font-black uppercase tracking-widest">
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <?php echo $ageLabel; ?>
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-blue-50 text-blue-600 border border-blue-100 text-[10px] font-black uppercase tracking-widest">
                                    <?php echo $ageLabel; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-5 py-3.5 align-top text-right min-w-[140px]" onclick="event.stopPropagation()">
                            <div class="inline-flex items-center gap-1.5">
                                <details class="relative inline-block group/drop">
                                    <summary class="list-none cursor-pointer select-none inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-slate-50 text-slate-600 border border-slate-200 hover:bg-slate-100 hover:border-slate-300 transition-all text-[10px] font-black uppercase tracking-widest">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                        Actions
                                        <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                                    </summary>
                                    <div class="absolute right-0 mt-2 w-52 bg-white border border-slate-200 rounded-2xl shadow-2xl shadow-slate-900/15 overflow-hidden z-40 origin-top-right">
                                        <div class="p-1.5 space-y-0.5">
                                            <?php if ($r['hr_status'] === 'pending' && $filterView === 'hr_pending'): ?>
                                                <button type="button" onclick="event.preventDefault(); openDrawerQuickApprove(<?php echo $rowNum; ?>)" class="w-full text-left inline-flex items-center gap-2 px-3 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest text-emerald-700 hover:bg-emerald-50 transition-all">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                                                    Approve
                                                </button>
                                                <button type="button" onclick="event.preventDefault(); openDrawerQuickReject(<?php echo $rowNum; ?>)" class="w-full text-left inline-flex items-center gap-2 px-3 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest text-rose-700 hover:bg-rose-50 transition-all">
                                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                    Reject
                                                </button>
                                            <?php endif; ?>
                                            <button type="button" onclick="event.preventDefault(); openRequestDrawer(<?php echo $rowNum; ?>)" class="w-full text-left inline-flex items-center gap-2 px-3 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 hover:bg-slate-50 transition-all">
                                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                                Preview
                                            </button>
                                        </div>
                                    </div>
                                </details>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($archived_requests)): ?>
    <div class="bg-white rounded-3xl border border-slate-100 shadow-sm overflow-hidden">
        <div class="px-6 lg:px-8 py-5 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-sm font-black text-slate-700 uppercase tracking-[0.18em]">Recent Archive</h2>
                <p class="text-[11px] font-semibold text-slate-400 mt-1">HR-finalized records (last 50)</p>
            </div>
            <a href="requests.php?filter=archived" class="inline-flex items-center gap-2 px-4 py-2 rounded-2xl bg-slate-50 text-slate-600 text-[10px] font-black uppercase tracking-widest hover:bg-slate-100 border border-slate-200 transition-all">
                View all
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
            </a>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50/70 border-b border-slate-100">
                        <th class="px-6 py-3 text-[9px] font-black text-slate-400 uppercase tracking-[0.22em]">Trainee</th>
                        <th class="px-6 py-3 text-[9px] font-black text-slate-400 uppercase tracking-[0.22em]">Dept</th>
                        <th class="px-6 py-3 text-[9px] font-black text-slate-400 uppercase tracking-[0.22em]">Type</th>
                        <th class="px-6 py-3 text-[9px] font-black text-slate-400 uppercase tracking-[0.22em]">Status</th>
                        <th class="px-6 py-3 text-[9px] font-black text-slate-400 uppercase tracking-[0.22em] text-right">Archived</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-50">
                    <?php foreach ($archived_requests as $r): ?>
                    <tr class="hover:bg-slate-50/50 transition-all">
                        <td class="px-6 py-3 text-xs font-bold text-slate-700"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td class="px-6 py-3 text-[10px] text-slate-500 font-semibold uppercase"><?php echo htmlspecialchars($r['department']); ?></td>
                        <td class="px-6 py-3"><?php echo requestTypeBadge($r['type'], 'sm'); ?></td>
                        <td class="px-6 py-3">
                            <?php if ($r['hr_status'] === 'approved'): ?>
                                <span class="inline-flex items-center gap-1.5 text-emerald-600 text-[10px] font-black uppercase tracking-widest">
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Approved
                                </span>
                            <?php else: ?>
                                <span class="inline-flex items-center gap-1.5 text-rose-600 text-[10px] font-black uppercase tracking-widest">
                                    <span class="w-1.5 h-1.5 rounded-full bg-rose-500"></span>Rejected
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-3 text-right text-[10px] font-semibold text-slate-500"><?php echo $r['archived_at'] ? date('M d, Y · g:i A', strtotime($r['archived_at'])) : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

</div>

<div id="drawerBackdrop" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 opacity-0 pointer-events-none transition-opacity duration-300" onclick="closeDrawer()"></div>
<aside id="requestDrawer" class="fixed top-0 right-0 bottom-0 z-50 w-full lg:w-[448px] bg-white shadow-2xl shadow-slate-900/30 translate-x-full transition-transform duration-300 ease-out flex flex-col">
    <div class="flex items-center justify-between px-6 py-5 border-b border-slate-100 flex-shrink-0 bg-gradient-to-r from-white to-slate-50">
        <div>
            <p class="text-[10px] font-black uppercase tracking-[0.24em] text-[#6D3089]">Request Preview</p>
            <h3 class="text-lg font-black text-slate-800 mt-1" id="drawerTitle">Loading...</h3>
        </div>
        <button type="button" onclick="closeDrawer()" class="inline-flex items-center justify-center w-10 h-10 rounded-2xl text-slate-500 hover:bg-slate-100 hover:text-slate-700 transition-all">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"></path></svg>
        </button>
    </div>
    <div class="flex-1 overflow-y-auto px-6 py-5 space-y-6" id="drawerContent"></div>
    <div id="drawerStickyFooter" class="flex-shrink-0 border-t border-slate-200 bg-white/95 backdrop-blur px-5 py-4 hidden">
        <div class="space-y-3">
            <div id="drawerNoteWrap">
                <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-1.5">HR Note (Optional)</label>
                <textarea id="drawerNote" rows="2" placeholder="Add final decision note..." class="w-full px-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white resize-none transition-all"></textarea>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <form id="drawerRejectForm" method="post" class="m-0 p-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="reject_request">
                    <input type="hidden" name="request_id" id="drawerRejectId" value="">
                    <input type="hidden" name="note" id="drawerRejectNote" value="">
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl bg-rose-500 text-white text-[10px] font-black uppercase tracking-[0.18em] shadow-lg shadow-rose-500/25 hover:bg-rose-600 hover:-translate-y-0.5 transition-all" onclick="event.preventDefault(); submitDrawerAction('reject')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
                        Reject
                    </button>
                </form>
                <form id="drawerApproveForm" method="post" class="m-0 p-0">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="approve_request">
                    <input type="hidden" name="request_id" id="drawerApproveId" value="">
                    <input type="hidden" name="note" id="drawerApproveNote" value="">
                    <button type="submit" class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl bg-emerald-500 text-white text-[10px] font-black uppercase tracking-[0.18em] shadow-lg shadow-emerald-500/25 hover:bg-emerald-600 hover:-translate-y-0.5 transition-all" onclick="event.preventDefault(); submitDrawerAction('approve')">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        Approve
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>

<div id="batchToolbar" class="fixed bottom-6 left-1/2 -translate-x-1/2 z-40 translate-y-24 opacity-0 pointer-events-none transition-all duration-300">
    <div class="flex items-center gap-3 px-5 py-3.5 rounded-2xl bg-slate-900 text-white shadow-2xl shadow-slate-900/40 ring-1 ring-white/10 backdrop-blur">
        <div class="flex items-center gap-2.5 pr-3 border-r border-white/10">
            <span class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-[#6D3089] text-white text-[11px] font-black" id="batchCount">0</span>
            <div>
                <p class="text-[10px] font-black uppercase tracking-[0.22em] text-white/60">Selected</p>
                <p class="text-[11px] font-bold leading-tight">Batch actions</p>
            </div>
        </div>
        <button type="button" onclick="bulkHrAction('bulk_reject_requests')" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-rose-500/90 hover:bg-rose-500 text-white text-[10px] font-black uppercase tracking-widest transition-all">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"></path></svg>
            Reject all
        </button>
        <button type="button" onclick="bulkHrAction('bulk_approve_requests')" class="inline-flex items-center gap-2 px-4 py-2 rounded-xl bg-emerald-500/90 hover:bg-emerald-500 text-white text-[10px] font-black uppercase tracking-widest transition-all">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
            Approve all
        </button>
    </div>
</div>

<div id="bulkModal" class="fixed inset-0 z-50 hidden items-center justify-center p-4">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeBulkModal()"></div>
    <div class="relative w-full max-w-xl bg-white rounded-[2rem] shadow-2xl shadow-slate-900/30 overflow-hidden">
        <div class="px-7 py-6 border-b border-slate-100 bg-gradient-to-br from-slate-50 to-white">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[10px] font-black uppercase tracking-[0.24em] text-[#6D3089]" id="bulkModalKindLabel">Batch Approve</p>
                    <h3 class="text-xl font-black text-slate-800 mt-1" id="bulkModalTitle">Review batch before proceeding</h3>
                </div>
                <button type="button" onclick="closeBulkModal()" class="inline-flex items-center justify-center w-10 h-10 rounded-2xl text-slate-400 hover:bg-slate-100 hover:text-slate-700 transition-all">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            <div class="mt-6 flex items-center gap-3" id="bulkStepper">
                <div class="flex items-center gap-3 flex-1">
                    <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-[#6D3089] text-white text-[11px] font-black shadow-lg shadow-[#6D3089]/25 ring-4 ring-[#6D3089]/10" id="step1Dot">1</div>
                    <div class="flex-1 h-0.5 rounded-full bg-slate-200" id="stepLine1"><div class="h-full w-0 rounded-full bg-[#6D3089] transition-all duration-500" id="stepLine1Fill"></div></div>
                </div>
                <div class="flex items-center gap-3 flex-1">
                    <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 text-slate-400 text-[11px] font-black border border-slate-200" id="step2Dot">2</div>
                    <div class="flex-1 h-0.5 rounded-full bg-slate-200" id="stepLine2"><div class="h-full w-0 rounded-full bg-[#6D3089] transition-all duration-500" id="stepLine2Fill"></div></div>
                </div>
                <div class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 text-slate-400 text-[11px] font-black border border-slate-200" id="step3Dot">3</div>
            </div>
            <div class="mt-3 grid grid-cols-3 gap-2 text-center">
                <p class="text-[9px] font-black uppercase tracking-widest text-[#6D3089]" id="step1Label">Select</p>
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400" id="step2Label">Add Note</p>
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400" id="step3Label">Confirm</p>
            </div>
        </div>
        <div class="px-7 py-6" id="bulkStepContent">
            <div id="bulkStep1" class="space-y-4">
                <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-4">
                    <div class="flex items-center justify-between mb-3">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-500">Requests in this batch</p>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-[#6D3089]/10 text-[#6D3089] text-[10px] font-black" id="bulkCountBadge">0 selected</span>
                    </div>
                    <div class="max-h-48 overflow-y-auto space-y-1.5 pr-1" id="bulkListItems"></div>
                </div>
                <button type="button" onclick="advanceBulkStep(2)" id="bulkStep1Btn" class="w-full inline-flex items-center justify-center gap-2 px-5 py-3 rounded-2xl bg-[#6D3089] text-white text-[11px] font-black uppercase tracking-[0.22em] shadow-lg shadow-[#6D3089]/25 hover:bg-[#5a2771] hover:-translate-y-0.5 transition-all">
                    Continue
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
                </button>
            </div>
            <div id="bulkStep2" class="space-y-4 hidden">
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500 block mb-2">Shared HR Note (applies to all)</label>
                    <textarea id="bulkModalNote" rows="4" placeholder="This note will be attached to every request in this batch. Explain the rationale for batch decision..." class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-2xl text-sm text-slate-800 placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-[#6D3089]/20 focus:border-[#6D3089]/40 focus:bg-white resize-none transition-all"></textarea>
                </div>
                <div class="flex gap-3">
                    <button type="button" onclick="advanceBulkStep(1)" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl bg-slate-100 text-slate-600 text-[11px] font-black uppercase tracking-[0.22em] hover:bg-slate-200 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path></svg>
                        Back
                    </button>
                    <button type="button" onclick="advanceBulkStep(3)" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl bg-[#6D3089] text-white text-[11px] font-black uppercase tracking-[0.22em] shadow-lg shadow-[#6D3089]/25 hover:bg-[#5a2771] transition-all">
                        Continue
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M17 8l4 4m0 0l-4 4m4-4H3"></path></svg>
                    </button>
                </div>
            </div>
            <div id="bulkStep3" class="space-y-5 hidden">
                <div id="bulkConfirmBox" class="rounded-2xl border p-5 bg-slate-50 border-slate-200"></div>
                <div class="flex gap-3">
                    <button type="button" onclick="advanceBulkStep(2)" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl bg-slate-100 text-slate-600 text-[11px] font-black uppercase tracking-[0.22em] hover:bg-slate-200 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M7 16l-4-4m0 0l4-4m-4 4h18"></path></svg>
                        Back
                    </button>
                    <button type="button" id="bulkConfirmBtn" class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-white text-[11px] font-black uppercase tracking-[0.22em] shadow-lg transition-all hover:-translate-y-0.5">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                        Confirm Batch
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

</div>

<script>
const CSRF_HR = <?php echo json_encode($csrf_token); ?>;
const REQUESTS_DATA = [<?php
    $first = true;
    foreach ($hr_requests as $r) {
        if (!$first) echo ',';
        echo json_encode($r);
        $first = false;
    }
?>];
let CURRENT_DRAWER_INDEX = -1;
let BULK_ACTION = null;
let BULK_STEP = 1;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function toggleSelectAllHrPending() {
    const boxes = document.querySelectorAll('input.hr_cb[data-hr-pending="1"]');
    const anyUnchecked = Array.from(boxes).some(b => !b.checked);
    boxes.forEach(b => { b.checked = anyUnchecked; });
    updateBatchToolbar();
}

document.getElementById('selectAllToggle')?.addEventListener('change', function() {
    const boxes = document.querySelectorAll('input.hr_cb[data-hr-pending="1"]');
    boxes.forEach(b => { b.checked = this.checked; });
    updateBatchToolbar();
});

function updateBatchToolbar() {
    const boxes = document.querySelectorAll('input.hr_cb:checked');
    const count = boxes.length;
    const toolbar = document.getElementById('batchToolbar');
    const countEl = document.getElementById('batchCount');
    if (countEl) countEl.textContent = String(count);
    if (count > 0) {
        toolbar.classList.remove('translate-y-24', 'opacity-0', 'pointer-events-none');
        toolbar.classList.add('translate-y-0', 'opacity-100', 'pointer-events-auto');
    } else {
        toolbar.classList.add('translate-y-24', 'opacity-0', 'pointer-events-none');
        toolbar.classList.remove('translate-y-0', 'opacity-100', 'pointer-events-auto');
    }
}

async function bulkHrAction(action) {
    const boxes = document.querySelectorAll('input.hr_cb:checked');
    if (!boxes.length) {
        Swal.fire({ icon: 'warning', title: 'None selected', text: 'Select one or more pending requests.', confirmButtonColor: '#6D3089' });
        return;
    }
    BULK_ACTION = action;
    BULK_STEP = 1;
    openBulkModal();
    renderBulkStep1();
}

function openBulkModal() {
    const modal = document.getElementById('bulkModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    const isApprove = BULK_ACTION?.indexOf('approve') !== -1;
    document.getElementById('bulkModalKindLabel').textContent = isApprove ? 'Batch Approve' : 'Batch Reject';
    document.getElementById('bulkModalKindLabel').className = 'text-[10px] font-black uppercase tracking-[0.24em] ' + (isApprove ? 'text-emerald-600' : 'text-rose-600');
    document.getElementById('bulkModalTitle').textContent = isApprove ? 'Approve selected requests' : 'Reject selected requests';
    const confirmBtn = document.getElementById('bulkConfirmBtn');
    confirmBtn.className = 'flex-1 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-2xl text-white text-[11px] font-black uppercase tracking-[0.22em] shadow-lg transition-all hover:-translate-y-0.5 ' + (isApprove ? 'bg-emerald-500 shadow-emerald-500/25 hover:bg-emerald-600' : 'bg-rose-500 shadow-rose-500/25 hover:bg-rose-600');
    confirmBtn.onclick = submitBulkModal;
    advanceBulkStep(1);
    document.getElementById('bulkModalNote').value = '';
}

function closeBulkModal() {
    const modal = document.getElementById('bulkModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
    BULK_ACTION = null;
    BULK_STEP = 1;
}

function renderBulkStep1() {
    const boxes = document.querySelectorAll('input.hr_cb:checked');
    const ids = Array.from(boxes).map(b => parseInt(b.value));
    const items = REQUESTS_DATA.filter(r => ids.includes(parseInt(r.id)));
    document.getElementById('bulkCountBadge').textContent = items.length + ' selected';
    const list = document.getElementById('bulkListItems');
    list.innerHTML = items.map(r => `
        <div class="flex items-center gap-3 px-3 py-2 rounded-xl bg-white border border-slate-100">
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-purple-50 to-slate-50 flex items-center justify-center text-[10px] font-black text-[#6D3089] border border-slate-100 flex-shrink-0">${escapeHtml(String(r.first_name || '?')[0])}${escapeHtml(String(r.last_name || '?')[0])}</div>
            <div class="min-w-0 flex-1">
                <p class="text-xs font-bold text-slate-800 truncate">${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}</p>
                <p class="text-[9px] text-slate-500 font-semibold uppercase truncate">${escapeHtml(r.department)} · ${escapeHtml(r.type)}</p>
            </div>
            <p class="flex-shrink-0 text-[10px] font-bold text-slate-400">#${r.id}</p>
        </div>
    `).join('');
}

function advanceBulkStep(step) {
    BULK_STEP = step;
    ['1','2','3'].forEach(s => {
        const n = parseInt(s);
        const dot = document.getElementById('step' + s + 'Dot');
        const label = document.getElementById('step' + s + 'Label');
        const lineFill = s !== '3' ? document.getElementById('stepLine' + s + 'Fill') : null;
        if (n <= step) {
            dot.className = 'flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-[#6D3089] text-white text-[11px] font-black shadow-lg shadow-[#6D3089]/25 ring-4 ring-[#6D3089]/10';
            label.className = 'text-[9px] font-black uppercase tracking-widest text-[#6D3089]';
            if (n < step && lineFill) lineFill.style.width = '100%';
        } else {
            dot.className = 'flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full bg-slate-100 text-slate-400 text-[11px] font-black border border-slate-200';
            label.className = 'text-[9px] font-black uppercase tracking-widest text-slate-400';
            if (lineFill) lineFill.style.width = '0%';
        }
    });
    document.getElementById('bulkStep1').classList.toggle('hidden', step !== 1);
    document.getElementById('bulkStep2').classList.toggle('hidden', step !== 2);
    document.getElementById('bulkStep3').classList.toggle('hidden', step !== 3);
    if (step === 3) renderBulkStep3();
}

function renderBulkStep3() {
    const isApprove = BULK_ACTION?.indexOf('approve') !== -1;
    const boxes = document.querySelectorAll('input.hr_cb:checked');
    const count = boxes.length;
    const note = document.getElementById('bulkModalNote').value || '(No note provided)';
    const accentCls = isApprove ? 'bg-emerald-50 border-emerald-100' : 'bg-rose-50 border-rose-100';
    const accentText = isApprove ? 'text-emerald-700' : 'text-rose-700';
    const accentTextStrong = isApprove ? 'text-emerald-600' : 'text-rose-600';
    const accentBg = isApprove ? 'bg-emerald-100' : 'bg-rose-100';
    document.getElementById('bulkConfirmBox').className = 'rounded-2xl border p-5 ' + accentCls;
    document.getElementById('bulkConfirmBox').innerHTML = `
        <div class="space-y-4">
            <div class="flex items-center gap-3 pb-4 border-b ${isApprove ? 'border-emerald-100' : 'border-rose-100'}">
                <div class="w-12 h-12 rounded-2xl ${accentBg} ${accentTextStrong} flex items-center justify-center flex-shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>
                </div>
                <div>
                    <p class="text-[10px] font-black uppercase tracking-widest ${accentTextStrong}">About to finalize</p>
                    <p class="text-base font-black ${accentText} mt-0.5">${isApprove ? 'Approve' : 'Reject'} <span class="text-slate-900">${count}</span> request${count !== 1 ? 's' : ''}</p>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3 text-[11px]">
                <div class="rounded-xl bg-white/70 border ${isApprove ? 'border-emerald-100' : 'border-rose-100'} p-3">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Action</p>
                    <p class="font-black ${accentTextStrong} mt-1">${isApprove ? 'HR Approve' : 'HR Reject'}</p>
                </div>
                <div class="rounded-xl bg-white/70 border ${isApprove ? 'border-emerald-100' : 'border-rose-100'} p-3">
                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Affected</p>
                    <p class="font-black text-slate-800 mt-1">${count} record${count !== 1 ? 's' : ''}</p>
                </div>
            </div>
            <div class="rounded-xl bg-white/70 border ${isApprove ? 'border-emerald-100' : 'border-rose-100'} p-4">
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Shared HR Note</p>
                <p class="text-xs text-slate-700 font-semibold leading-relaxed italic">"${escapeHtml(note)}"</p>
            </div>
        </div>
    `;
}

function submitBulkModal() {
    const boxes = document.querySelectorAll('input.hr_cb:checked');
    if (!boxes.length) { closeBulkModal(); return; }
    const note = document.getElementById('bulkModalNote').value || '';
    document.getElementById('bulk_hr_action').value = BULK_ACTION;
    document.getElementById('bulk_hr_note').value = note;
    document.getElementById('bulk_hr_form').submit();
}

function handleRowClick(ev, idx) {
    openRequestDrawer(idx);
}

function openRequestDrawer(idx) {
    CURRENT_DRAWER_INDEX = idx;
    const r = REQUESTS_DATA[idx];
    if (!r) return;
    document.getElementById('drawerTitle').textContent = r.type.charAt(0).toUpperCase() + r.type.slice(1) + ' · #' + r.id;
    const canAct = (r.hr_status === 'pending' && r.supervisor_status === 'approved' && '<?php echo $filterView; ?>' === 'hr_pending');
    document.getElementById('drawerApproveId').value = String(r.id);
    document.getElementById('drawerRejectId').value = String(r.id);
    document.getElementById('drawerNote').value = '';
    const footer = document.getElementById('drawerStickyFooter');
    if (canAct) footer.classList.remove('hidden'); else footer.classList.add('hidden');
    const isApprove = canAct;
    if (canAct) {
        document.getElementById('drawerNoteWrap').style.display = '';
    } else {
        document.getElementById('drawerNoteWrap').style.display = 'none';
    }
    const initials = (String(r.first_name || '?')[0] + String(r.last_name || '?')[0]).toUpperCase();
    const traineeName = escapeHtml(r.first_name + ' ' + r.last_name);
    const timelineHtml = requestViewTimelineJs(r);
    const reasonHtml = escapeHtml(r.reason || 'No reason provided.');
    const attachmentHtml = r.attachment_path ? `
        <div class="rounded-2xl border border-slate-100 bg-slate-50/70 p-4">
            <p class="text-[10px] font-black uppercase tracking-widest text-slate-500 mb-3">Attachment</p>
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-indigo-100 text-indigo-600 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-black text-slate-800 truncate">${escapeHtml(r.attachment_name || 'Attachment')}</p>
                    <p class="text-[10px] text-slate-500 font-semibold mt-0.5">Attached file</p>
                </div>
                <a href="../serve_image.php?request_id=${r.id}" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-indigo-50 text-indigo-700 text-[10px] font-black uppercase tracking-widest hover:bg-indigo-100 transition-all flex-shrink-0">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                    Download
                </a>
            </div>
        </div>
    ` : '';
    const supNoteHtml = (r.supervisor_note || r.supervisor_approved_at) ? `
        <div class="rounded-2xl border border-amber-100 bg-amber-50/70 p-4">
            <div class="flex items-center justify-between mb-2">
                <p class="text-[10px] font-black uppercase tracking-widest text-amber-700">Supervisor ${escapeHtml(r.supervisor_status || 'Review')}</p>
                ${r.supervisor_approved_at ? `<p class="text-[9px] font-bold text-amber-600 bg-amber-100/70 px-2 py-0.5 rounded-full">${new Date(r.supervisor_approved_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>` : ''}
            </div>
            <p class="text-xs text-amber-800 font-semibold leading-relaxed italic">"${escapeHtml(r.supervisor_note || 'No note provided by supervisor.')}"</p>
        </div>
    ` : '';
    const hrNoteHtml = r.hr_note ? `
        <div class="rounded-2xl border ${r.hr_status === 'approved' ? 'border-emerald-100 bg-emerald-50/70' : 'border-rose-100 bg-rose-50/70'} p-4">
            <p class="text-[10px] font-black uppercase tracking-widest ${r.hr_status === 'approved' ? 'text-emerald-700' : 'text-rose-700'} mb-2">HR Final Note</p>
            <p class="text-xs ${r.hr_status === 'approved' ? 'text-emerald-800' : 'text-rose-800'} font-semibold leading-relaxed italic">"${escapeHtml(r.hr_note)}"</p>
        </div>
    ` : '';
    const dateTimeHtml = `
        <div class="grid grid-cols-2 gap-3">
            <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-3.5">
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Start</p>
                <p class="text-xs font-black text-slate-800 mt-1">${new Date(r.start_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</p>
                ${r.start_time ? `<p class="text-[10px] text-slate-500 font-semibold mt-0.5">${new Date('2000-01-01T'+r.start_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>` : ''}
            </div>
            <div class="rounded-2xl border border-slate-100 bg-slate-50/60 p-3.5">
                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">End</p>
                <p class="text-xs font-black text-slate-800 mt-1">${r.end_date ? new Date(r.end_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'Same day'}</p>
                ${r.end_time ? `<p class="text-[10px] text-slate-500 font-semibold mt-0.5">${new Date('2000-01-01T'+r.end_time).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}</p>` : ''}
            </div>
        </div>
    `;
    document.getElementById('drawerContent').innerHTML = `
        <div class="rounded-3xl border border-slate-100 bg-gradient-to-br from-slate-50 via-white to-purple-50/40 p-5">
            <div class="flex items-start gap-4">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-[#6D3089] to-[#8B4CAA] text-white flex items-center justify-center text-lg font-black shadow-lg shadow-[#6D3089]/25 flex-shrink-0 ring-4 ring-white">
                    ${initials}
                </div>
                <div class="min-w-0 flex-1 pt-1">
                    <p class="text-base font-black text-slate-900 leading-tight">${traineeName}</p>
                    <p class="text-[11px] font-bold text-slate-500 mt-0.5">@${escapeHtml(r.username)}</p>
                    <div class="flex flex-wrap items-center gap-1.5 mt-2">
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full bg-white border border-slate-200 text-slate-700 text-[9px] font-black uppercase tracking-widest shadow-sm">${escapeHtml(r.department)}</span>
                        <span class="text-[10px] text-slate-400 font-semibold">ID #${r.id}</span>
                    </div>
                </div>
            </div>
        </div>
        <div>${dateTimeHtml}</div>
        <div class="rounded-3xl border border-slate-100 bg-white p-5">
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 mb-3">Reason / Justification</p>
            <p class="text-sm text-slate-700 font-semibold leading-relaxed whitespace-pre-wrap">${reasonHtml}</p>
        </div>
        ${attachmentHtml}
        ${supNoteHtml}
        ${hrNoteHtml}
        <div class="rounded-3xl border border-slate-100 bg-gradient-to-br from-white to-slate-50/60 p-5">
            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-500 mb-5">Approval Timeline</p>
            ${timelineHtml}
        </div>
    `;
    const bd = document.getElementById('drawerBackdrop');
    const dr = document.getElementById('requestDrawer');
    bd.classList.remove('opacity-0', 'pointer-events-none');
    bd.classList.add('opacity-100', 'pointer-events-auto');
    dr.classList.remove('translate-x-full');
    setTimeout(() => { document.getElementById('drawerContent').scrollTop = 0; }, 50);
}

function requestViewTimelineJs(r) {
    const stages = [];
    stages.push({ title: 'Request Filed', sub: 'Trainee submitted request form', date: r.created_at || '', state: 'done' });
    if (r.supervisor_approved_at) {
        stages.push({ title: 'Supervisor ' + (r.supervisor_status || '').charAt(0).toUpperCase() + (r.supervisor_status || '').slice(1), sub: r.supervisor_note || 'Department reviewed', date: r.supervisor_approved_at, state: r.supervisor_status === 'approved' ? 'done' : 'rejected' });
    } else if (r.supervisor_status) {
        stages.push({ title: 'Pending Supervisor Review', sub: r.supervisor_note || 'Awaiting department action', date: '', state: r.supervisor_status === 'rejected' ? 'rejected' : 'active' });
    }
    if (r.archived_at && r.hr_status) {
        stages.push({ title: 'HR ' + r.hr_status.charAt(0).toUpperCase() + r.hr_status.slice(1), sub: r.hr_note || 'HR finalized', date: r.archived_at, state: r.hr_status === 'approved' ? 'done' : 'rejected' });
    } else if (r.supervisor_status === 'approved') {
        stages.push({ title: 'Pending HR Final Review', sub: r.hr_note || 'Endorsed by supervisor; HR queue', date: '', state: 'active' });
    }
    let out = '<ol class="relative border-s-slate-100 border-s ml-1 space-y-5">';
    stages.forEach((s, i) => {
        const stateColor = s.state === 'done' ? 'bg-emerald-500 ring-emerald-100 text-white' : (s.state === 'rejected' ? 'bg-rose-500 ring-rose-100 text-white' : 'bg-white ring-[#6D3089]/20 border-[#6D3089] text-[#6D3089] border-2');
        const icon = s.state === 'done'
            ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>'
            : (s.state === 'rejected'
                ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>'
                : '<span class="text-[10px] font-black">' + (i+1) + '</span>');
        const dateStr = s.date ? new Date(s.date).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '';
        out += `<li class="ms-6">
            <span class="absolute -start-[13px] inline-flex items-center justify-center rounded-full h-7 w-7 ring-4 ${stateColor}">${icon}</span>
            <div class="rounded-2xl border border-slate-100 bg-slate-50/50 p-3.5"><div class="flex items-start justify-between gap-3"><div class="min-w-0">
                <p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-800">${escapeHtml(s.title)}</p>
                <p class="mt-1 text-[11px] text-slate-500 font-semibold leading-snug">${escapeHtml(s.sub)}</p>
            </div>` + (dateStr ? `<p class="flex-shrink-0 text-[9px] font-bold uppercase tracking-widest text-slate-400 whitespace-nowrap mt-0.5">${dateStr}</p>` : '') + `
            </div></div></li>`;
    });
    out += '</ol>';
    return out;
}

function closeDrawer() {
    const bd = document.getElementById('drawerBackdrop');
    const dr = document.getElementById('requestDrawer');
    bd.classList.add('opacity-0', 'pointer-events-none');
    bd.classList.remove('opacity-100', 'pointer-events-auto');
    dr.classList.add('translate-x-full');
    CURRENT_DRAWER_INDEX = -1;
}

function openDrawerQuickApprove(idx) {
    CURRENT_DRAWER_INDEX = idx;
    openRequestDrawer(idx);
    setTimeout(() => { document.getElementById('drawerNote').focus(); }, 350);
}

function openDrawerQuickReject(idx) {
    CURRENT_DRAWER_INDEX = idx;
    openRequestDrawer(idx);
    setTimeout(() => { document.getElementById('drawerNote').focus(); }, 350);
}

function submitDrawerAction(kind) {
    const idx = CURRENT_DRAWER_INDEX;
    if (idx < 0) return;
    const r = REQUESTS_DATA[idx];
    if (!r) return;
    const note = (document.getElementById('drawerNote').value || '').trim();
    const formId = kind === 'approve' ? 'drawerApproveForm' : 'drawerRejectForm';
    const noteInputId = kind === 'approve' ? 'drawerApproveNote' : 'drawerRejectNote';
    const idInputId = kind === 'approve' ? 'drawerApproveId' : 'drawerRejectId';
    document.getElementById(noteInputId).value = note;
    document.getElementById(idInputId).value = String(r.id);
    document.getElementById(formId).submit();
}

async function hrReviewRequest(r) {
    const idx = REQUESTS_DATA.findIndex(x => String(x.id) === String(r.id));
    if (idx >= 0) {
        openRequestDrawer(idx);
        setTimeout(() => { document.getElementById('drawerNote')?.focus(); }, 350);
    }
}

function viewRequestDetails(r) {
    const idx = REQUESTS_DATA.findIndex(x => String(x.id) === String(r.id));
    if (idx >= 0) { openRequestDrawer(idx); return; }
    Swal.fire({
        title: 'Request Details',
        html: `
            <div class="text-left space-y-4 p-2">
                <div class="grid grid-cols-2 gap-4">
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Trainee</p><p class="text-xs font-bold text-slate-700">${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}</p></div>
                    <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Type</p><p class="text-xs font-bold text-slate-700 uppercase">${r.type}</p></div>
                </div>
                <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Period</p><p class="text-xs font-bold text-slate-700">${r.start_date} ${r.end_date ? ' to ' + r.end_date : ''}</p>${r.start_time ? `<p class="text-[10px] text-slate-500 font-bold">${r.start_time} - ${r.end_time}</p>` : ''}</div>
                <div><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Reason</p><p class="text-xs text-slate-600 bg-slate-50 p-3 rounded-xl mt-1 leading-relaxed">${escapeHtml(r.reason)}</p></div>
                ${r.attachment_path ? `<div class="border-t pt-4"><p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">Attachment</p><a href="../serve_image.php?request_id=${r.id}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-700 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-700 hover:text-white transition-all"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1"></path></svg>Download</a></div>` : ''}
                <div class="p-3 bg-amber-50 rounded-xl border border-amber-100"><div class="flex justify-between items-center mb-1"><p class="text-[8px] font-black text-amber-600 uppercase tracking-widest">Supervisor Approval</p>${r.supervisor_approved_at ? `<p class="text-[9px] font-bold text-amber-500 italic bg-amber-100/50 px-2 py-0.5 rounded-full">${new Date(r.supervisor_approved_at).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}</p>` : ''}</div><p class="text-xs text-amber-700 mt-1 italic">"${escapeHtml(r.supervisor_note || 'No note')}"</p></div>
                ${r.hr_note ? `<div class="p-3 bg-emerald-50 rounded-xl border border-emerald-100"><p class="text-[8px] font-black text-emerald-600 uppercase tracking-widest">HR Final Note</p><p class="text-xs text-emerald-700 mt-1 italic">"${escapeHtml(r.hr_note)}"</p></div>` : ''}
            </div>
        `,
        confirmButtonText: 'CLOSE',
        confirmButtonColor: '#6D3089'
    });
}

document.getElementById('toolbarClear')?.addEventListener('click', function() {
    document.getElementById('toolbarSearch').value = '';
    document.getElementById('toolbarType').value = '';
    document.getElementById('toolbarFrom').value = '';
    document.getElementById('toolbarTo').value = '';
    applyToolbarFilters();
});

document.getElementById('toolbarSearchBtn')?.addEventListener('click', applyToolbarFilters);
document.getElementById('toolbarSearch')?.addEventListener('input', applyToolbarFilters);
document.getElementById('toolbarType')?.addEventListener('change', applyToolbarFilters);
document.getElementById('toolbarFrom')?.addEventListener('change', applyToolbarFilters);
document.getElementById('toolbarTo')?.addEventListener('change', applyToolbarFilters);

function applyToolbarFilters() {
    const q = (document.getElementById('toolbarSearch').value || '').toLowerCase().trim();
    const type = (document.getElementById('toolbarType').value || '').toLowerCase();
    const from = document.getElementById('toolbarFrom').value;
    const to = document.getElementById('toolbarTo').value;
    const rows = document.querySelectorAll('#requestsTable tbody tr.request-row');
    rows.forEach(tr => {
        const search = (tr.dataset.search || '').toLowerCase();
        const rtype = (tr.dataset.type || '').toLowerCase();
        const rdate = tr.dataset.date || '';
        let match = true;
        if (q && !search.includes(q)) match = false;
        if (type && rtype !== type) match = false;
        if (from && rdate && rdate < from) match = false;
        if (to && rdate && rdate > to) match = false;
        tr.style.display = match ? '' : 'none';
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeDrawer();
        closeBulkModal();
    }
});
</script>

<?php renderFooter(); ?>
