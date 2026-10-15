<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('admin, superadmin');

$dept_stmt = $pdo->query("SELECT dept_name FROM departments ORDER BY dept_name ASC");
$DEPARTMENTS_ARRAY = $dept_stmt->fetchAll(PDO::FETCH_COLUMN);

if (isset($_GET['export'])) {
    $export = $_GET['export'];
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $allowed_statuses = ['pending', 'active', 'inactive', 'completed', 'suspended'];
    $status_filter = (isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses, true)) ? $_GET['status'] : '';
    $dept_filter = '';
    if (isset($_GET['dept'])) {
        $dept = trim($_GET['dept']);
        if (isset($DEPARTMENTS) && is_array($DEPARTMENTS) && in_array($dept, $DEPARTMENTS, true)) {
            $dept_filter = $dept;
        }
    }
    $school_filter = isset($_GET['school']) ? trim($_GET['school']) : '';

    if ($export === 'trainees_csv') {
        $query = "SELECT t.id, t.username, t.first_name, t.last_name, t.email, t.phone, t.department, t.school, t.course, t.status, t.required_hours, t.initial_hours, t.created_at,
            (SELECT SUM(hours_worked) FROM attendance WHERE trainee_id = t.id AND time_out IS NOT NULL AND deleted_at IS NULL) as total_hours_standard,
            (SELECT SUM(verified_ot_seconds) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as total_ot_seconds,
            (SELECT SUM(hours_added) FROM sanctions WHERE trainee_id = t.id AND deleted_at IS NULL) as total_sanctions
            FROM trainees t
            WHERE t.role = 'trainee' AND t.deleted_at IS NULL";
        $params = [];
        if ($search !== '') {
            $query .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.username LIKE ? OR t.department LIKE ? OR t.school LIKE ?)";
            $p = "%{$search}%";
            $params = array_merge($params, [$p, $p, $p, $p, $p]);
        }
        if ($status_filter !== '') {
            $query .= " AND t.status = ?";
            $params[] = $status_filter;
        }
        if ($dept_filter !== '') {
            $query .= " AND t.department = ?";
            $params[] = $dept_filter;
        }
        if ($school_filter !== '') {
            $query .= " AND t.school LIKE ?";
            $params[] = "%{$school_filter}%";
        }
        $query .= " ORDER BY t.created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=trainees_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['id', 'username', 'first_name', 'last_name', 'email', 'phone', 'department', 'school', 'course', 'status', 'required_hours', 'initial_hours', 'rendered_hours', 'sanction_hours', 'created_at']);
        foreach ($rows as $r) {
            $initial_hours = (float)($r['initial_hours'] ?: 0);
            $standard_hours = (float)($r['total_hours_standard'] ?: 0);
            $ot_hours = (float)($r['total_ot_seconds'] ?: 0) / 3600;
            $rendered = round($initial_hours + $standard_hours + $ot_hours, 2);
            $sanction = (float)($r['total_sanctions'] ?: 0);
            fputcsv($out, [$r['id'], $r['username'], $r['first_name'], $r['last_name'], $r['email'], $r['phone'], $r['department'], $r['school'], $r['course'], $r['status'], $r['required_hours'], $r['initial_hours'], $rendered, $sanction, $r['created_at']]);
        }
        fclose($out);
        exit();
    }

    if ($export === 'sanctions_csv') {
        $query = "SELECT s.created_at, s.trainee_id, tr.username, tr.first_name, tr.last_name, tr.department, s.hours_added, s.reason, s.admin_id, ad.username as admin_username
                  FROM sanctions s
                  JOIN trainees tr ON s.trainee_id = tr.id
                  LEFT JOIN trainees ad ON s.admin_id = ad.id
                  WHERE s.deleted_at IS NULL AND tr.deleted_at IS NULL";
        $params = [];
        if ($dept_filter !== '') {
            $query .= " AND tr.department = ?";
            $params[] = $dept_filter;
        }
        if ($search !== '') {
            $query .= " AND (tr.first_name LIKE ? OR tr.last_name LIKE ? OR tr.username LIKE ?)";
            $p = "%{$search}%";
            $params = array_merge($params, [$p, $p, $p]);
        }
        $query .= " ORDER BY s.created_at DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sanctions_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['created_at', 'trainee_id', 'username', 'first_name', 'last_name', 'department', 'hours_added', 'reason', 'admin_id', 'admin_username']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['created_at'], $r['trainee_id'], $r['username'], $r['first_name'], $r['last_name'], $r['department'], $r['hours_added'], $r['reason'], $r['admin_id'], $r['admin_username']]);
        }
        fclose($out);
        exit();
    }

    if ($export === 'attendance_summary_csv') {
        $from = isset($_GET['from']) ? trim($_GET['from']) : '';
        $to = isset($_GET['to']) ? trim($_GET['to']) : '';
        $query = "SELECT a.date, COUNT(*) as records, SUM(CASE WHEN a.is_late = 1 THEN 1 ELSE 0 END) as late_count
                  FROM attendance a
                  JOIN trainees t ON a.trainee_id = t.id
                  WHERE a.deleted_at IS NULL AND t.deleted_at IS NULL";
        $params = [];
        if ($dept_filter !== '') {
            $query .= " AND t.department = ?";
            $params[] = $dept_filter;
        }
        if ($from !== '') {
            $query .= " AND a.date >= ?";
            $params[] = $from;
        }
        if ($to !== '') {
            $query .= " AND a.date <= ?";
            $params[] = $to;
        }
        $query .= " GROUP BY a.date ORDER BY a.date ASC";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=attendance_summary_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'records', 'late_count']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['date'], $r['records'], $r['late_count']]);
        }
        fclose($out);
        exit();
    }
}

/**
 * Handle POST Actions
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: trainees.php?error=csrf_failed");
        exit();
    }

    $trainee_id = $_POST['trainee_id'] ?? null;
    $admin_id = $_SESSION['user_id'];
    
    // Verify Admin Password
    $stmt = $pdo->prepare("SELECT password FROM trainees WHERE id = ?");
    $stmt->execute([$admin_id]);
    $admin = $stmt->fetch();
    
    if (!password_verify($_POST['admin_password'], $admin['password'])) {
        header("Location: trainees.php?error=invalid_password");
        exit();
    }

    if ($_POST['action'] === 'approve') {
        $stmt = $pdo->prepare("UPDATE trainees SET status = 'active' WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$trainee_id]);
        logAudit($pdo, $_SESSION['user_id'], 'APPROVE_TRAINEE', "Account ID $trainee_id approved by admin");
        header("Location: trainees.php?msg=approved");
        exit();
    } elseif ($_POST['action'] === 'clear') {
        $stmt = $pdo->prepare("UPDATE trainees SET is_cleared = 1, status = 'completed' WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$trainee_id]);
        logAudit($pdo, $_SESSION['user_id'], 'CLEAR_TRAINEE', "Trainee ID $trainee_id has been cleared for graduation");
        header("Location: trainees.php?msg=cleared");
        exit();
    } elseif ($_POST['action'] === 'unclear') {
        $stmt = $pdo->prepare("UPDATE trainees SET is_cleared = 0, status = 'active' WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$trainee_id]);
        logAudit($pdo, $_SESSION['user_id'], 'UNCLEAR_TRAINEE', "Trainee ID $trainee_id clearance revoked");
        header("Location: trainees.php?msg=uncleared");
        exit();
    } elseif ($_POST['action'] === 'delete') {
        $stmt = $pdo->prepare("UPDATE trainees SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND role = 'trainee' AND deleted_at IS NULL");
        $stmt->execute([$trainee_id]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_TRAINEE', "Account ID $trainee_id soft-deleted by admin");
        header("Location: trainees.php?msg=deleted");
        exit();
    }

    if ($_POST['action'] === 'add_trainee') {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $department = $_POST['department'];
        $school = trim($_POST['school']);
        $course = trim($_POST['course']);
        $achievements = trim($_POST['achievements'] ?? '');
        $req_hours = $_POST['required_hours'];
        $init_hours = $_POST['initial_hours'];

        try {
            $stmt = $pdo->prepare("INSERT INTO trainees (first_name, last_name, username, email, password, department, school, course, achievements, required_hours, initial_hours, status, role) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', 'trainee')");
            $stmt->execute([$first_name, $last_name, $username, $email, $password, $department, $school, $course, $achievements, $req_hours, $init_hours]);
            logAudit($pdo, $admin_id, 'ADD_TRAINEE', "Added new trainee: $first_name $last_name (@$username)");
            header("Location: trainees.php?msg=created");
            exit();
        } catch (PDOException $e) {
            header("Location: trainees.php?error=exists");
        }
        exit();
    }
    elseif ($_POST['action'] === 'edit_trainee') {
        $id = $_POST['trainee_id'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $department = $_POST['department'];
        $school = trim($_POST['school']);
        $course = trim($_POST['course']);
        $achievements = trim($_POST['achievements'] ?? '');
        $status = $_POST['status'];

        $sql = "UPDATE trainees SET first_name = ?, last_name = ?, username = ?, email = ?, department = ?, school = ?, course = ?, achievements = ?, status = ?";
        $params = [$first_name, $last_name, $username, $email, $department, $school, $course, $achievements, $status];

        if (!empty($_POST['password'])) {
            $sql .= ", password = ?";
            $params[] = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        }

        $sql .= " WHERE id = ?";
        $params[] = $id;

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            logAudit($pdo, $admin_id, 'EDIT_TRAINEE', "Updated profile for $first_name $last_name");
            header("Location: trainees.php?msg=updated");
        } catch (PDOException $e) {
            header("Location: trainees.php?error=exists");
        }
        exit();
    }
    elseif ($_POST['action'] === 'transfer') {
        $old_dept = $_POST['old_dept'];
        $new_dept = $_POST['new_dept'];
        $stmt = $pdo->prepare("INSERT INTO transfers (trainee_id, old_department, new_department) VALUES (?, ?, ?)");
        $stmt->execute([$trainee_id, $old_dept, $new_dept]);
        $stmt = $pdo->prepare("UPDATE trainees SET department = ? WHERE id = ?");
        $stmt->execute([$new_dept, $trainee_id]);
        logAudit($pdo, $admin_id, 'TRANSFER_DEPT', "Transferred OJT ID $trainee_id from $old_dept to $new_dept");
        header("Location: trainees.php?msg=transferred");
        exit();
    } 
    elseif ($_POST['action'] === 'sanction') {
        $hours = $_POST['hours'];
        $reason = $_POST['reason'];
        $stmt = $pdo->prepare("INSERT INTO sanctions (trainee_id, hours_added, reason, admin_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$trainee_id, $hours, $reason, $admin_id]);
        logAudit($pdo, $admin_id, 'ADD_SANCTION', "Added $hours penalty hours to OJT ID $trainee_id. Reason: $reason");
        header("Location: trainees.php?msg=sanctioned");
        exit();
    }
    elseif ($_POST['action'] === 'adjust') {
        $required = $_POST['required_hours'];
        $initial = $_POST['initial_hours'];
        $stmt = $pdo->prepare("UPDATE trainees SET required_hours = ?, initial_hours = ? WHERE id = ?");
        $stmt->execute([$required, $initial, $trainee_id]);
        logAudit($pdo, $admin_id, 'ADJUST_HOURS', "Adjusted OJT ID $trainee_id hours: Target=$required, Initial=$initial");
        header("Location: trainees.php?msg=adjusted");
        exit();
    }
    elseif ($_POST['action'] === 'set_schedule') {
        $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
        $dates = array_values(array_filter(array_map('trim', $dates)));
        $perDay = !empty($_POST['shift_starts']) && is_array($_POST['shift_starts'])
            && count($_POST['shift_starts']) === count($dates);
        $pdo->beginTransaction();
        try {
            foreach ($dates as $i => $date) {
                if ($date === '') {
                    continue;
                }
                $start = $perDay ? ($_POST['shift_starts'][$i] ?? '08:00:00') : ($_POST['shift_start'] ?? '08:00:00');
                $end = $perDay ? ($_POST['shift_ends'][$i] ?? '17:00:00') : ($_POST['shift_end'] ?? '17:00:00');
                $status = $perDay ? ($_POST['statuses'][$i] ?? 'duty') : ($_POST['status'] ?? 'duty');
                
                $stmt = $pdo->prepare("INSERT INTO schedules (trainee_id, date, shift_start, shift_end, status) 
                                     VALUES (?, ?, ?, ?, ?) 
                                     ON DUPLICATE KEY UPDATE shift_start = VALUES(shift_start), shift_end = VALUES(shift_end), status = VALUES(status)");
                $stmt->execute([$trainee_id, $date, $start, $end, $status]);
            }
            $pdo->commit();
            logAudit($pdo, $admin_id, 'SET_SCHEDULE', "Set schedule for OJT ID $trainee_id");
            try {
                $stmt = $pdo->prepare("SELECT email, first_name, last_name, department FROM trainees WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$trainee_id]);
                $trow = $stmt->fetch();
                if ($trow && !empty($trow['email'])) {
                    $minDate = min($dates);
                    $maxDate = max($dates);
                    $subject = "Schedule updated";
                    $body = "Your schedule has been updated by HR/Admin.\n\nDepartment: {$trow['department']}\nAffected dates: {$minDate}" . ($minDate !== $maxDate ? " to {$maxDate}" : "") . "\n";
                    enqueueSystemEmail($trow['email'], $subject, $body);
                }
            } catch (Exception $e) {
            }
            header("Location: trainees.php?msg=schedule_set");
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: trainees.php?error=db_error");
        }
        exit();
    }
    elseif ($_POST['action'] === 'clear_schedule_days') {
        $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
        $dates = array_values(array_filter(array_map('trim', $dates)));
        if (!empty($dates)) {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $stmt = $pdo->prepare("DELETE FROM schedules WHERE trainee_id = ? AND date IN ($placeholders)");
            $stmt->execute(array_merge([$trainee_id], $dates));
            logAudit($pdo, $admin_id, 'CLEAR_SCHEDULE', "Cleared schedule days for OJT ID $trainee_id");
        }
        header("Location: trainees.php?msg=schedule_cleared");
        exit();
    }
    elseif ($_POST['action'] === 'rate') {
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];
        $stmt = $pdo->prepare("UPDATE trainees SET rating = ?, rating_comment = ? WHERE id = ?");
        $stmt->execute([$rating, $comment, $trainee_id]);
        logAudit($pdo, $admin_id, 'RATE_TRAINEE', "Rated OJT ID $trainee_id with $rating stars");
        header("Location: trainees.php?msg=rated");
        exit();
    }
}

// Pagination & Search Settings
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$allowed_statuses = ['pending', 'active', 'inactive', 'completed', 'suspended'];
$status_filter = (isset($_GET['status']) && in_array($_GET['status'], $allowed_statuses)) ? $_GET['status'] : '';
$dept_filter = '';
if (isset($_GET['dept'])) {
    $dept = trim($_GET['dept']);
    if (isset($DEPARTMENTS) && is_array($DEPARTMENTS) && in_array($dept, $DEPARTMENTS, true)) {
        $dept_filter = $dept;
    }
}
$school_filter = isset($_GET['school']) ? trim($_GET['school']) : '';

// Build Query
$query = "SELECT t.*, 
    (SELECT SUM(hours_worked) FROM attendance WHERE trainee_id = t.id AND time_out IS NOT NULL AND deleted_at IS NULL) as total_hours_standard,
    (SELECT SUM(verified_ot_seconds) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as total_ot_seconds,
    (SELECT SUM(hours_added) FROM sanctions WHERE trainee_id = t.id AND deleted_at IS NULL) as total_sanctions,
    (SELECT COUNT(*) FROM transfers WHERE trainee_id = t.id AND deleted_at IS NULL) as total_transfers
    FROM trainees t 
    WHERE t.role = 'trainee' AND t.deleted_at IS NULL";

$params = [];
if ($search) {
    $query .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.username LIKE ? OR t.department LIKE ? OR t.school LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param]);
}

if ($status_filter) {
    $query .= " AND t.status = ?";
    $params[] = $status_filter;
}

if ($dept_filter) {
    $query .= " AND t.department = ?";
    $params[] = $dept_filter;
}

if ($school_filter) {
    $query .= " AND t.school LIKE ?";
    $params[] = "%$school_filter%";
}

// Get total count for pagination
$count_query = str_replace("t.*, 
    (SELECT SUM(hours_worked) FROM attendance WHERE trainee_id = t.id AND time_out IS NOT NULL AND deleted_at IS NULL) as total_hours_standard,
    (SELECT SUM(verified_ot_seconds) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as total_ot_seconds,
    (SELECT SUM(hours_added) FROM sanctions WHERE trainee_id = t.id AND deleted_at IS NULL) as total_sanctions,
    (SELECT COUNT(*) FROM transfers WHERE trainee_id = t.id AND deleted_at IS NULL) as total_transfers", "COUNT(*)", $query);
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query .= " ORDER BY t.status DESC, t.created_at DESC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$trainees = $stmt->fetchAll();

// History data for Modals
$trainee_ids = array_column($trainees, 'id');
$transfers_data = [];
$sanctions_data = [];
if (!empty($trainee_ids)) {
    $in_query = implode(',', array_fill(0, count($trainee_ids), '?'));
    $transfers_data = $pdo->prepare("SELECT trainee_id, t.* FROM transfers t WHERE trainee_id IN ($in_query) AND deleted_at IS NULL ORDER BY transfer_date DESC");
    $transfers_data->execute($trainee_ids);
    $transfers_data = $transfers_data->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);

    $sanctions_data = $pdo->prepare("SELECT s.trainee_id, s.*, t.first_name as admin_name FROM sanctions s JOIN trainees t ON s.admin_id = t.id WHERE s.trainee_id IN ($in_query) AND s.deleted_at IS NULL ORDER BY s.created_at DESC");
    $sanctions_data->execute($trainee_ids);
    $sanctions_data = $sanctions_data->fetchAll(PDO::FETCH_GROUP|PDO::FETCH_ASSOC);
}

// Next QST ID
$stmt = $pdo->query("SELECT username FROM trainees WHERE username LIKE 'QST%' ORDER BY username DESC LIMIT 1");
$last_qst = $stmt->fetch();
$next_qst = "QST0001";
if ($last_qst) {
    $last_num = (int)substr($last_qst['username'], 3);
    $next_qst = "QST" . str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
}

$csrf_token = generateCsrfToken();
renderHeader('Trainee Directory');
renderSidebar('trainees');
?>

<div class="space-y-8">
    <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Trainee Directory</h1>
            <p class="text-sm text-slate-500 mt-1">Manage, monitor, and evaluate all hotel trainees.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center lg:justify-end">
            <div class="relative group w-full sm:w-64">
                <input type="text" id="traineeSearch" placeholder="Search trainee..." value="<?php echo htmlspecialchars($search); ?>" class="pl-10 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all w-full group-hover:border-slate-300">
                <svg class="w-4 h-4 absolute left-3.5 top-3 text-slate-400 group-hover:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <select id="statusFilter" class="w-full sm:w-auto px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all">
                <option value="">All Status</option>
                <?php foreach (['pending' => 'Pending', 'active' => 'Active', 'inactive' => 'Inactive', 'completed' => 'Completed', 'suspended' => 'Suspended'] as $k => $lbl): ?>
                    <option value="<?php echo $k; ?>" <?php echo ($status_filter === $k) ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
            <select id="deptFilter" class="w-full sm:w-auto px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all">
                <option value="">All Depts</option>
                <?php if (isset($DEPARTMENTS) && is_array($DEPARTMENTS)): ?>
                    <?php foreach ($DEPARTMENTS as $dpt): ?>
                        <option value="<?php echo htmlspecialchars($dpt); ?>" <?php echo ($dept_filter === $dpt) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dpt); ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <input type="text" id="schoolFilter" value="<?php echo htmlspecialchars($school_filter); ?>" placeholder="School" class="w-full sm:w-40 px-3 py-2 bg-white border border-slate-200 rounded-lg text-sm font-semibold text-slate-700 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all">
            <a href="requests.php" class="px-4 py-2 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Requests
            </a>
            <a href="export_trainees.php?school=<?php echo urlencode($school_filter ?: 'All'); ?>" class="px-4 py-2 bg-purple-50 text-[#6D3089] rounded-lg text-xs font-bold hover:bg-[#6D3089] hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2m32-2v-2a4 4 0 00-4-4h-1a4 4 0 00-4 4v2m-9-4h.01M9 16h.01"></path></svg>
                Export School Report
            </a>
            <a href="trainees.php?export=trainees_csv&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status_filter); ?>&dept=<?php echo urlencode($dept_filter); ?>&school=<?php echo urlencode($school_filter); ?>" class="px-4 py-2 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-bold hover:bg-emerald-700 hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1"></path></svg>
                Export CSV
            </a>
            <button onclick="openExportAllDtrsModal()" class="px-4 py-2 bg-indigo-50 text-indigo-600 rounded-lg text-xs font-bold hover:bg-indigo-600 hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Export All DTRs
            </button>
            <button onclick="openAddTraineeModal()" class="w-full sm:w-auto px-5 py-2.5 bg-[#6D3089] text-white rounded-lg text-sm font-semibold hover:bg-[#5a2771] transition-all flex items-center justify-center gap-2 shadow-lg shadow-purple-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                Add New Trainee
            </button>
        </div>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Trainee</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Deployment</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center">Hours Progress</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center">Status</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($trainees)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-sm text-slate-400 italic">No trainees found.</p>
                                <?php if ($search): ?>
                                    <a href="trainees.php" class="text-xs text-[#6D3089] font-bold uppercase mt-2 inline-block">Clear Search</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <?php foreach ($trainees as $t): 
                        $initial_hours = (float)($t['initial_hours'] ?: 0);
                        $standard_hours = (float)($t['total_hours_standard'] ?: 0);
                        $ot_hours = (float)($t['total_ot_seconds'] ?: 0) / 3600;
                        $rendered = $initial_hours + $standard_hours + $ot_hours;
                        
                        $required_hours = (int)($t['required_hours'] ?: DEFAULT_REQUIRED_HOURS);
                        $sanction_hours = (float)($t['total_sanctions'] ?: 0);
                        $goal = $required_hours + $sanction_hours;
                        
                        $percent = $goal > 0 ? min(100, ($rendered / $goal) * 100) : 0;
                    ?>
                    <tr class="hover:bg-slate-50/50 transition-all group">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-[#6D3089] text-sm uppercase group-hover:bg-[#6D3089] group-hover:text-white transition-all">
                                    <?php echo substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900"><?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?></p>
                                    <p class="text-xs text-slate-500">@<?php echo $t['username']; ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <span class="px-3 py-1 bg-slate-100 text-slate-700 rounded-full text-[10px] font-bold uppercase"><?php echo htmlspecialchars($t['department']); ?></span>
                            <p class="text-[10px] text-slate-400 font-bold uppercase mt-1.5"><?php echo htmlspecialchars($t['course']); ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <div class="w-32 mx-auto">
                                <div class="flex justify-between items-center mb-1 text-[10px] font-bold">
                                    <span class="text-slate-500"><?php echo (round($rendered, 1) == round($rendered)) ? (int)$rendered : round($rendered, 1); ?>/<?php echo $goal; ?>h</span>
                                    <span class="<?php echo $percent >= 100 ? 'text-emerald-600' : 'text-slate-400'; ?>"><?php echo round($percent); ?>%</span>
                                </div>
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full <?php echo $percent >= 100 ? 'bg-emerald-500' : 'bg-[#6D3089]'; ?>" style="width: <?php echo $percent; ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($t['status'] === 'pending'): ?>
                                <span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[10px] font-bold uppercase animate-pulse">Pending</span>
                            <?php elseif ($t['status'] === 'active'): ?>
                                <span class="bg-emerald-100 text-emerald-700 px-2 py-1 rounded text-[10px] font-bold uppercase">Active</span>
                            <?php elseif ($t['status'] === 'inactive'): ?>
                                <span class="bg-gray-100 text-gray-600 px-2 py-1 rounded text-[10px] font-bold uppercase">Inactive</span>
                            <?php elseif ($t['status'] === 'completed'): ?>
                                <span class="bg-indigo-100 text-indigo-700 px-2 py-1 rounded text-[10px] font-bold uppercase">Graduated</span>
                            <?php else: ?>
                                <span class="bg-slate-100 text-slate-600 px-2 py-1 rounded text-[10px] font-bold uppercase"><?php echo $t['status']; ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 text-right">
                            <div class="flex justify-end gap-1.5">
                                <?php if ($t['status'] === 'pending'): ?>
                                    <button onclick="confirmAction('approve', <?php echo $t['id']; ?>)" class="text-xs font-bold text-emerald-600 hover:text-emerald-700 uppercase">Approve</button>
                                <?php else: ?>
                                    <div class="flex gap-1">
                                        <button onclick="viewTraineeProfile(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-[#6D3089] hover:bg-slate-50 rounded-lg transition-all" title="View Professional Profile">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                        </button>
                                        <button onclick="viewAttendancePhotos(<?php echo $t['id']; ?>)" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-50 rounded-lg transition-all" title="View Photos">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h14a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        </button>
                                        <button onclick="openEditTraineeModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-[#6D3089] hover:bg-slate-50 rounded-lg transition-all" title="Edit Profile">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        </button>
                                        <button onclick="openTransferModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-50 rounded-lg transition-all" title="Transfer Dept">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                                        </button>
                                        <button onclick="openSanctionModal(<?php echo $t['id']; ?>)" class="p-2 text-slate-400 hover:text-rose-600 hover:bg-slate-50 rounded-lg transition-all" title="Add Penalty">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                        </button>
                                        <button onclick="openSchedulerModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-slate-50 rounded-lg transition-all" title="Set Schedule">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        </button>
                                        <button onclick="openScheduleCalendar(<?php echo $t['id']; ?>, '<?php echo addslashes($t['first_name'] . ' ' . $t['last_name']); ?>')" class="p-2 text-slate-400 hover:text-[#6D3089] hover:bg-slate-50 rounded-lg transition-all" title="View Schedule Calendar">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        </button>
                                        <button onclick="openAdjustHoursModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-amber-600 hover:bg-slate-50 rounded-lg transition-all" title="Adjust Hours">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                        </button>
                                        <button onclick="openExportDtrModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-blue-600 hover:bg-slate-50 rounded-lg transition-all" title="Export DTR (CSV)">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                        </button>
                                        <?php if ($percent >= 100 && !$t['is_cleared']): ?>
                                            <button onclick="confirmAction('clear', <?php echo $t['id']; ?>)" class="p-2 text-emerald-500 hover:bg-emerald-50 rounded-lg transition-all" title="Grant Graduation Clearance">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                            </button>
                                        <?php elseif ($t['is_cleared']): ?>
                                            <button onclick="confirmAction('unclear', <?php echo $t['id']; ?>)" class="p-2 text-emerald-600 bg-emerald-50 rounded-lg transition-all" title="Revoke Clearance">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <button onclick="confirmAction('delete', <?php echo $t['id']; ?>)" class="p-2 text-rose-500 hover:bg-rose-50 rounded-lg transition-all" title="Soft Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="px-6 py-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between">
            <div class="text-xs font-bold text-slate-500 uppercase tracking-widest">
                Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> OJTs
            </div>
            <div class="flex gap-1">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $status_filter ? '&status='.$status_filter : ''; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-[10px] font-black text-slate-600 hover:bg-slate-50 transition-all uppercase">Prev</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $status_filter ? '&status='.urlencode($status_filter) : ''; ?><?php echo $dept_filter ? '&dept='.urlencode($dept_filter) : ''; ?><?php echo $school_filter ? '&school='.urlencode($school_filter) : ''; ?>" class="px-3 py-1.5 <?php echo $i == $page ? 'bg-[#6D3089] text-white border-[#6D3089]' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'; ?> border rounded text-[10px] font-black transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $status_filter ? '&status='.urlencode($status_filter) : ''; ?><?php echo $dept_filter ? '&dept='.urlencode($dept_filter) : ''; ?><?php echo $school_filter ? '&school='.urlencode($school_filter) : ''; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-[10px] font-black text-slate-600 hover:bg-slate-50 transition-all uppercase">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const DEPARTMENTS = <?php echo json_encode($DEPARTMENTS); ?>;
    const NEXT_QST = '<?php echo $next_qst; ?>';
    const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
    const ALL_TRANSFERS = <?php echo json_encode($transfers_data); ?>;
    const ALL_SANCTIONS = <?php echo json_encode($sanctions_data); ?>;

    // Live Search logic
    let searchTimer;
    const searchInput = document.getElementById('traineeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                const val = e.target.value;
                const url = new URL(window.location.href);
                url.searchParams.set('search', val);
                url.searchParams.delete('page');
                window.location.href = url.toString();
            }, 500);
        });
        if (searchInput.value) {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
    }

    function applyFilters() {
        const url = new URL(window.location.href);
        const status = document.getElementById('statusFilter')?.value || '';
        const dept = document.getElementById('deptFilter')?.value || '';
        const school = document.getElementById('schoolFilter')?.value || '';

        if (status) url.searchParams.set('status', status);
        else url.searchParams.delete('status');

        if (dept) url.searchParams.set('dept', dept);
        else url.searchParams.delete('dept');

        if (school) url.searchParams.set('school', school);
        else url.searchParams.delete('school');

        url.searchParams.delete('page');
        window.location.href = url.toString();
    }

    const statusSel = document.getElementById('statusFilter');
    if (statusSel) statusSel.addEventListener('change', applyFilters);

    const deptSel = document.getElementById('deptFilter');
    if (deptSel) deptSel.addEventListener('change', applyFilters);

    const schoolInp = document.getElementById('schoolFilter');
    if (schoolInp) {
        schoolInp.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                applyFilters();
            }
        });
        schoolInp.addEventListener('blur', () => {
            if (schoolInp.value.trim() !== <?php echo json_encode($school_filter); ?>) {
                applyFilters();
            }
        });
    }

    function viewTraineeProfile(t) {
        const transfers = ALL_TRANSFERS[t.id] || [];
        const sanctions = ALL_SANCTIONS[t.id] || [];
        const initial = parseFloat(t.initial_hours || 0);
        const standard = parseFloat(t.total_hours_standard || 0);
        $ot = parseFloat(t.total_ot_seconds || 0) / 3600;
        const totalRendered = Math.round((initial + standard + $ot) * 100) / 100;
        const totalSanctions = sanctions.reduce((acc, s) => acc + parseFloat(s.hours_added), 0);
        const totalGoal = parseInt(t.required_hours || 400) + totalSanctions;

        const sanctionsHtml = sanctions.length > 0 
            ? sanctions.map(s => `
                <div class="flex justify-between items-start p-3 bg-rose-50 rounded-xl border border-rose-100">
                    <div class="text-left">
                        <p class="text-[10px] font-black text-rose-600 uppercase mb-0.5">${s.reason}</p>
                        <p class="text-[8px] text-rose-400 font-bold uppercase">${new Date(s.created_at).toLocaleDateString()}</p>
                    </div>
                    <span class="text-[10px] font-black text-rose-700">+${s.hours_added}h</span>
                </div>
            `).join('')
            : '<p class="text-[10px] text-slate-400 italic text-center py-4">No sanctions recorded.</p>';

        const transfersHtml = transfers.length > 0
            ? transfers.map(tr => `
                <div class="text-xs p-2 border-l-2 border-indigo-400 bg-indigo-50/30 mb-2">
                    <p class="font-bold text-slate-800">${tr.old_department} → ${tr.new_department}</p>
                    <p class="text-[10px] text-slate-500">${new Date(tr.transfer_date).toLocaleDateString()}</p>
                </div>
            `).join('')
            : '<p class="text-[10px] text-slate-400 italic text-center py-2">No transfers recorded.</p>';

        Swal.fire({
            title: '',
            width: '600px',
            padding: '0',
            background: '#F8FAFC',
            showConfirmButton: false,
            showCloseButton: true,
            html: `
                <div class="overflow-hidden rounded-[2.5rem]">
                    <div class="bg-[#6D3089] p-8 text-white text-left relative overflow-hidden">
                        <div class="absolute top-0 right-0 w-32 h-32 bg-white/10 rounded-full -mr-16 -mt-16"></div>
                        <div class="relative z-10 flex items-center gap-6">
                            <div class="w-20 h-20 rounded-3xl bg-white/20 backdrop-blur-md flex items-center justify-center text-3xl font-black">
                                ${t.first_name[0]}${t.last_name[0]}
                            </div>
                            <div>
                                <h3 class="text-2xl font-black uppercase tracking-tight">${t.first_name} ${t.last_name}</h3>
                                <p class="text-purple-200 text-xs font-bold uppercase tracking-[0.2em] mt-1">${t.department} Division</p>
                                <span class="inline-block mt-3 px-3 py-1 bg-white/20 rounded-full text-[9px] font-black uppercase tracking-widest">${t.username}</span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="p-8 grid grid-cols-2 gap-6">
                        <div class="space-y-6">
                            <div class="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Academic Credentials</h4>
                                <div class="space-y-3">
                                    <div>
                                        <p class="text-[8px] font-black text-slate-400 uppercase">School</p>
                                        <p class="text-xs font-bold text-slate-800">${t.school}</p>
                                    </div>
                                    <div>
                                        <p class="text-[8px] font-black text-slate-400 uppercase">Course</p>
                                        <p class="text-xs font-bold text-slate-800">${t.course}</p>
                                    </div>
                                </div>
                            </div>

                            <div class="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">Department History</h4>
                                ${transfersHtml}
                            </div>
                        </div>

                        <div class="bg-white p-5 rounded-3xl border border-slate-100 shadow-sm flex flex-col">
                            <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4">Sanction Log</h4>
                            <div class="space-y-2 flex-1 overflow-y-auto max-h-[250px] pr-2 scrollbar-thin">
                                ${sanctionsHtml}
                            </div>
                        </div>

                        <div class="col-span-2 bg-slate-900 rounded-3xl p-5 flex items-center justify-between text-white">
                            <div>
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Rendered Hours</p>
                                <p class="text-2xl font-black">${totalRendered} <span class="text-xs text-slate-500">/ ${totalGoal}H</span></p>
                            </div>
                            <div class="text-right">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-1">System Status</p>
                                <span class="px-3 py-1 bg-white/10 rounded-full text-[9px] font-black uppercase tracking-widest text-emerald-400">● ${t.status}</span>
                            </div>
                        </div>
                    </div>
                </div>
            `
        });
    }

    async function openAddTraineeModal() {
        const { value: formValues } = await Swal.fire({
            title: 'Add New Trainee',
            html: `
                <div class="text-left space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">First Name</label><input id="f_name" class="swal2-input w-full m-0 mt-1"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Last Name</label><input id="l_name" class="swal2-input w-full m-0 mt-1"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">ID Number</label><input id="u_name" class="swal2-input w-full m-0 mt-1" value="${NEXT_QST}"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Email</label><input id="email" type="email" class="swal2-input w-full m-0 mt-1"></div>
                    </div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Password</label><input id="pass" type="password" class="swal2-input w-full m-0 mt-1"></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Department</label><select id="dept" class="swal2-input w-full m-0 mt-1">${DEPARTMENTS.map(d => `<option value="${d}">${d}</option>`).join('')}</select></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">School</label><input id="school" class="swal2-input w-full m-0 mt-1"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Course</label><input id="course" class="swal2-input w-full m-0 mt-1"></div>
                    </div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Achievements/Certifications</label><textarea id="achievements" class="swal2-textarea w-full m-0 mt-1" placeholder="e.g. Employee of the Month, TESDA Certified..."></textarea></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Required Hours</label><input id="req_h" type="number" class="swal2-input w-full m-0 mt-1" value="400"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Initial Hours</label><input id="init_h" type="number" step="0.01" class="swal2-input w-full m-0 mt-1" value="0"></div>
                    </div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Admin password required'); return false; }
                return {
                    first_name: document.getElementById('f_name').value,
                    last_name: document.getElementById('l_name').value,
                    username: document.getElementById('u_name').value,
                    email: document.getElementById('email').value,
                    password: document.getElementById('pass').value,
                    department: document.getElementById('dept').value,
                    school: document.getElementById('school').value,
                    course: document.getElementById('course').value,
                    achievements: document.getElementById('achievements').value,
                    required_hours: document.getElementById('req_h').value,
                    initial_hours: document.getElementById('init_h').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('add_trainee', formValues);
    }

    async function openEditTraineeModal(t) {
        const { value: formValues } = await Swal.fire({
            title: 'Edit Trainee',
            html: `
                <div class="text-left space-y-4">
                    <input type="hidden" id="t_id" value="${t.id}">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">First Name</label><input id="f_name" class="swal2-input w-full m-0 mt-1" value="${t.first_name}"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Last Name</label><input id="l_name" class="swal2-input w-full m-0 mt-1" value="${t.last_name}"></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">ID Number</label><input id="u_name" class="swal2-input w-full m-0 mt-1" value="${t.username}"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Email</label><input id="email" type="email" class="swal2-input w-full m-0 mt-1" value="${t.email}"></div>
                    </div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">New Password (Leave blank to keep current)</label><input id="pass" type="password" class="swal2-input w-full m-0 mt-1"></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Department</label><select id="dept" class="swal2-input w-full m-0 mt-1">${DEPARTMENTS.map(d => `<option value="${d}" ${d == t.department ? 'selected' : ''}>${d}</option>`).join('')}</select></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="text-xs font-bold text-slate-400 uppercase">School</label><input id="school" class="swal2-input w-full m-0 mt-1" value="${t.school}"></div>
                        <div><label class="text-xs font-bold text-slate-400 uppercase">Course</label><input id="course" class="swal2-input w-full m-0 mt-1" value="${t.course}"></div>
                    </div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Achievements/Certifications</label><textarea id="achievements" class="swal2-textarea w-full m-0 mt-1">${t.achievements || ''}</textarea></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Status</label><select id="status" class="swal2-input w-full m-0 mt-1"><option value="pending" ${t.status == 'pending' ? 'selected' : ''}>Pending</option><option value="active" ${t.status == 'active' ? 'selected' : ''}>Active</option><option value="inactive" ${t.status == 'inactive' ? 'selected' : ''}>Inactive</option><option value="completed" ${t.status == 'completed' ? 'selected' : ''}>Completed</option><option value="suspended" ${t.status == 'suspended' ? 'selected' : ''}>Suspended</option></select></div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Admin password required'); return false; }
                return {
                    trainee_id: document.getElementById('t_id').value,
                    first_name: document.getElementById('f_name').value,
                    last_name: document.getElementById('l_name').value,
                    username: document.getElementById('u_name').value,
                    email: document.getElementById('email').value,
                    password: document.getElementById('pass').value,
                    department: document.getElementById('dept').value,
                    school: document.getElementById('school').value,
                    course: document.getElementById('course').value,
                    achievements: document.getElementById('achievements').value,
                    status: document.getElementById('status').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('edit_trainee', formValues);
    }

    async function openTransferModal(t) {
        const { value: formValues } = await Swal.fire({
            title: 'Transfer Department',
            html: `
                <div class="text-left space-y-4">
                    <p class="text-xs text-slate-500">Move <strong>${t.first_name}</strong> to a different department.</p>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Current</label><input class="swal2-input w-full m-0 mt-1 bg-slate-50" value="${t.department}" disabled></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">New Department</label><select id="new_dept" class="swal2-input w-full m-0 mt-1">${DEPARTMENTS.filter(d => d != t.department).map(d => `<option value="${d}">${d}</option>`).join('')}</select></div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Admin password required'); return false; }
                return {
                    trainee_id: t.id,
                    old_dept: t.department,
                    new_dept: document.getElementById('new_dept').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('transfer', formValues);
    }

    async function openSanctionModal(id) {
        const { value: formValues } = await Swal.fire({
            title: 'Add Penalty Hours',
            html: `
                <div class="text-left space-y-4">
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Hours to Add</label><input id="s_hours" type="number" step="0.01" class="swal2-input w-full m-0 mt-1" value="1"></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Reason for Penalty</label><textarea id="s_reason" class="swal2-textarea w-full m-0 mt-1" placeholder="e.g. Unexcused Absence"></textarea></div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Admin password required'); return false; }
                return {
                    trainee_id: id,
                    hours: document.getElementById('s_hours').value,
                    reason: document.getElementById('s_reason').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('sanction', formValues);
    }

    function mondayOfDate(d) {
        const x = new Date(d + 'T12:00:00');
        const day = x.getDay();
        const diff = day === 0 ? -6 : 1 - day;
        x.setDate(x.getDate() + diff);
        return x.toISOString().split('T')[0];
    }
    function addDaysStr(isoDate, n) {
        const x = new Date(isoDate + 'T12:00:00');
        x.setDate(x.getDate() + n);
        return x.toISOString().split('T')[0];
    }
    async function openSchedulerModal(t) {
        const defaultMon = mondayOfDate(new Date().toISOString().split('T')[0]);
        const weekLabels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        let rows = '';
        for (let i = 0; i < 7; i++) {
            rows += `<div class="grid grid-cols-12 gap-1 items-center text-[10px] mb-1 border-b border-slate-100 pb-1">
                <span class="col-span-2 font-bold text-slate-600">${weekLabels[i]}</span>
                <input type="time" id="pd_start_${i}" class="swal2-input col-span-3 m-0 p-1 text-xs" value="08:00">
                <input type="time" id="pd_end_${i}" class="swal2-input col-span-3 m-0 p-1 text-xs" value="17:00">
                <select id="pd_status_${i}" class="swal2-input col-span-3 m-0 p-1 text-xs">
                    <option value="duty">On Duty</option>
                    <option value="day_off">Day Off</option>
                </select>
                <label class="col-span-1 flex justify-center"><input type="checkbox" id="pd_use_${i}" checked class="rounded"></label>
            </div>`;
        }
        const { value: formValues } = await Swal.fire({
            title: 'Weekly schedule',
            width: '640px',
            html: `
                <div class="text-left space-y-3 max-h-[70vh] overflow-y-auto pr-1">
                    <p class="text-xs text-slate-500">OJT: <strong>${t.first_name} ${t.last_name}</strong></p>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase">Week starting (Monday)</label>
                        <input id="week_monday" type="date" class="swal2-input w-full m-0 mt-1" value="${defaultMon}">
                    </div>
                    <div class="flex gap-4 text-xs">
                        <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="sched_mode" id="mode_same" value="same" checked> Same shift on selected days</label>
                        <label class="flex items-center gap-2 cursor-pointer"><input type="radio" name="sched_mode" id="mode_custom" value="custom"> Different time/status per day</label>
                    </div>
                    <div id="same_block" class="space-y-2">
                        <p class="text-[10px] font-bold text-slate-400 uppercase">Apply to</p>
                        <div class="flex flex-wrap gap-2" id="dow_checks">
                            ${[0,1,2,3,4,5,6].map(i => `<label class="text-[10px] flex items-center gap-1"><input type="checkbox" id="dow_${i}" ${i < 5 ? 'checked' : ''}> ${weekLabels[i]}</label>`).join('')}
                        </div>
                        <div class="grid grid-cols-3 gap-3">
                            <div><label class="text-[10px] font-bold text-slate-400 uppercase">Shift start</label><input id="s_start" type="time" class="swal2-input w-full m-0 mt-1" value="08:00"></div>
                            <div><label class="text-[10px] font-bold text-slate-400 uppercase">Shift end</label><input id="s_end" type="time" class="swal2-input w-full m-0 mt-1" value="17:00"></div>
                            <div>
                                <label class="text-[10px] font-bold text-slate-400 uppercase">Status</label>
                                <select id="s_status" class="swal2-input w-full m-0 mt-1 text-sm">
                                    <option value="duty">On Duty</option>
                                    <option value="day_off">Day Off</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div id="custom_block" class="hidden space-y-1">
                        <p class="text-[10px] font-bold text-slate-400 uppercase mb-1">Uncheck a day to skip; settings apply to that weekday of the chosen week</p>
                        ${rows}
                    </div>
                    <label class="flex items-center gap-2 text-rose-600 text-xs font-bold cursor-pointer border border-rose-100 rounded-lg p-2 bg-rose-50/50">
                        <input type="checkbox" id="sched_clear_only"> Remove saved shifts for the selected days (clear only)
                    </label>
                    <div class="pt-2 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin password</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            didOpen: () => {
                const same = document.getElementById('same_block');
                const custom = document.getElementById('custom_block');
                const sync = () => {
                    const customMode = document.getElementById('mode_custom').checked;
                    same.classList.toggle('hidden', customMode);
                    custom.classList.toggle('hidden', !customMode);
                };
                document.getElementById('mode_same').addEventListener('change', sync);
                document.getElementById('mode_custom').addEventListener('change', sync);
            },
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if (!pass) { Swal.showValidationMessage('Password required'); return false; }
                const mon = document.getElementById('week_monday').value;
                if (!mon) { Swal.showValidationMessage('Pick a week'); return false; }
                const clearOnly = document.getElementById('sched_clear_only').checked;
                const customMode = document.getElementById('mode_custom').checked;
                let dates = [];
                if (customMode) {
                    for (let i = 0; i < 7; i++) {
                        if (!document.getElementById('pd_use_' + i).checked) continue;
                        dates.push(addDaysStr(mon, i));
                    }
                } else {
                    for (let i = 0; i < 7; i++) {
                        const el = document.getElementById('dow_' + i);
                        if (el && el.checked) dates.push(addDaysStr(mon, i));
                    }
                }
                if (dates.length === 0) { Swal.showValidationMessage('Select at least one day'); return false; }
                if (clearOnly) {
                    return { trainee_id: t.id, dates, admin_password: pass, _action: 'clear_schedule_days' };
                }
                if (customMode) {
                    const shift_starts = [];
                    const shift_ends = [];
                    const statuses = [];
                    for (let i = 0; i < 7; i++) {
                        if (!document.getElementById('pd_use_' + i).checked) continue;
                        shift_starts.push(document.getElementById('pd_start_' + i).value);
                        shift_ends.push(document.getElementById('pd_end_' + i).value);
                        statuses.push(document.getElementById('pd_status_' + i).value);
                    }
                    return { trainee_id: t.id, dates, shift_starts, shift_ends, statuses, admin_password: pass, _action: 'set_schedule' };
                }
                return {
                    trainee_id: t.id,
                    dates,
                    shift_start: document.getElementById('s_start').value,
                    shift_end: document.getElementById('s_end').value,
                    status: document.getElementById('s_status').value,
                    admin_password: pass,
                    _action: 'set_schedule'
                };
            }
        });
        if (!formValues) return;
        if (formValues._action === 'clear_schedule_days') {
            const { _action, ...rest } = formValues;
            submitPostAction('clear_schedule_days', rest);
        } else {
            const { _action, ...rest } = formValues;
            submitPostAction('set_schedule', rest);
        }
    }

    async function openAdjustHoursModal(t) {
        const { value: formValues } = await Swal.fire({
            title: 'Adjust Hour Requirements',
            html: `
                <div class="text-left space-y-4">
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Required Target Hours</label><input id="a_req" type="number" class="swal2-input w-full m-0 mt-1" value="${t.required_hours}"></div>
                    <div><label class="text-xs font-bold text-slate-400 uppercase">Initial Hours Rendered</label><input id="a_init" type="number" step="0.01" class="swal2-input w-full m-0 mt-1" value="${t.initial_hours}"></div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Admin Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Admin password required'); return false; }
                return {
                    trainee_id: t.id,
                    required_hours: document.getElementById('a_req').value,
                    initial_hours: document.getElementById('a_init').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('adjust', formValues);
    }

    async function openExportDtrModal(trainee) {
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear();
        const currentMonth = String(currentDate.getMonth() + 1).padStart(2, '0');
        
        const months = [
            { value: '01', label: 'January' }, { value: '02', label: 'February' },
            { value: '03', label: 'March' }, { value: '04', label: 'April' },
            { value: '05', label: 'May' }, { value: '06', label: 'June' },
            { value: '07', label: 'July' }, { value: '08', label: 'August' },
            { value: '09', label: 'September' }, { value: '10', label: 'October' },
            { value: '11', label: 'November' }, { value: '12', label: 'December' }
        ];
        
        const years = [];
        for (let y = currentYear - 5; y <= currentYear + 1; y++) {
            years.push({ value: y, label: y });
        }
        
        const { value: formValues } = await Swal.fire({
            title: `Export DTR for ${trainee.first_name} ${trainee.last_name}`,
            html: `
                <div class="text-left space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase">Month</label>
                            <select id="exp_month" class="swal2-input w-full m-0 mt-1">
                                ${months.map(m => `<option value="${m.value}" ${m.value === currentMonth ? 'selected' : ''}>${m.label}</option>`).join('')}
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase">Year</label>
                            <select id="exp_year" class="swal2-input w-full m-0 mt-1">
                                ${years.map(y => `<option value="${y.value}" ${y.value === currentYear ? 'selected' : ''}>${y.label}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-400 uppercase">Period</label>
                        <select id="exp_period" class="swal2-input w-full m-0 mt-1">
                            <option value="1st_half">1st Half (1-15)</option>
                            <option value="2nd_half">2nd Half (16-end)</option>
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Export',
            confirmButtonColor: '#6D3089',
            preConfirm: () => {
                return {
                    month: document.getElementById('exp_month').value,
                    year: document.getElementById('exp_year').value,
                    period: document.getElementById('exp_period').value
                };
            }
        });
        
        if (formValues) {
            const url = new URL('../export_dtr.php', window.location.href);
            url.searchParams.set('id', trainee.id);
            const monthStr = `${formValues.year}-${formValues.month}`;
            url.searchParams.set('month', monthStr);
            url.searchParams.set('period', formValues.period);
            window.location.href = url.toString();
        }
    }

    async function openExportAllDtrsModal() {
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear();
        const currentMonth = String(currentDate.getMonth() + 1).padStart(2, '0');
        
        const months = [
            { value: '01', label: 'January' }, { value: '02', label: 'February' },
            { value: '03', label: 'March' }, { value: '04', label: 'April' },
            { value: '05', label: 'May' }, { value: '06', label: 'June' },
            { value: '07', label: 'July' }, { value: '08', label: 'August' },
            { value: '09', label: 'September' }, { value: '10', label: 'October' },
            { value: '11', label: 'November' }, { value: '12', label: 'December' }
        ];
        
        const years = [];
        for (let y = currentYear - 5; y <= currentYear + 1; y++) {
            years.push({ value: y, label: y });
        }
        
        const { value: formValues } = await Swal.fire({
            title: 'Export All DTRs',
            html: `
                <div class="text-left space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase">Month</label>
                            <select id="exp_month" class="swal2-input w-full m-0 mt-1">
                                ${months.map(m => `<option value="${m.value}" ${m.value === currentMonth ? 'selected' : ''}>${m.label}</option>`).join('')}
                            </select>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase">Year</label>
                            <select id="exp_year" class="swal2-input w-full m-0 mt-1">
                                ${years.map(y => `<option value="${y.value}" ${y.value === currentYear ? 'selected' : ''}>${y.label}</option>`).join('')}
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-400 uppercase">Period</label>
                        <select id="exp_period" class="swal2-input w-full m-0 mt-1">
                            <option value="full">Full Month</option>
                            <option value="1st_half">1st Half (1-15)</option>
                            <option value="2nd_half">2nd Half (16-end)</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-400 uppercase">Department (optional)</label>
                        <select id="exp_dept" class="swal2-input w-full m-0 mt-1">
                            <option value="">All Departments</option>
                            ${DEPARTMENTS.map(d => `<option value="${d}">${d}</option>`).join('')}
                        </select>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Export',
            confirmButtonColor: '#6D3089',
            preConfirm: () => {
                return {
                    month: document.getElementById('exp_month').value,
                    year: document.getElementById('exp_year').value,
                    period: document.getElementById('exp_period').value,
                    dept: document.getElementById('exp_dept').value
                };
            }
        });
        
        if (formValues) {
            const url = new URL('export_all_dtrs.php', window.location.href);
            url.searchParams.set('month', formValues.month);
            url.searchParams.set('year', formValues.year);
            url.searchParams.set('period', formValues.period);
            if (formValues.dept) url.searchParams.set('dept', formValues.dept);
            window.location.href = url.toString();
        }
    }

    function confirmAction(action, id) {
        const config = {
            approve: { title: 'Approve Trainee?', text: 'This will activate the trainee account.', icon: 'question', color: '#10B981' },
            clear: { title: 'Grant Clearance?', text: 'This trainee has completed their hours. Mark as cleared?', icon: 'success', color: '#10B981' },
            unclear: { title: 'Revoke Clearance?', text: 'Are you sure you want to revoke this clearance?', icon: 'warning', color: '#F43F5E' },
            delete: { title: 'Delete Account?', text: 'This will soft-delete the trainee record.', icon: 'warning', color: '#F43F5E' }
        };
        const c = config[action];
        Swal.fire({
            title: c.title, text: c.text, icon: c.icon, showCancelButton: true, confirmButtonColor: c.color, confirmButtonText: 'Yes, proceed'
        }).then((result) => { if (result.isConfirmed) submitPostAction(action, { trainee_id: String(id) }); });
    }

    async function submitPostAction(action, data) {
        if (!data.admin_password || data.admin_password === 'bypass_for_now') {
            const { value: password } = await Swal.fire({
                title: 'Admin Authorization',
                input: 'password',
                inputLabel: 'Enter your password to authorize this action',
                inputPlaceholder: 'Enter your password',
                inputAttributes: {
                    autocapitalize: 'off',
                    autocorrect: 'off'
                },
                showCancelButton: true
            });
            if (!password) return;
            data.admin_password = password;
        }

        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'trainees.php';
        
        const actionInput = document.createElement('input');
        actionInput.type = 'hidden';
        actionInput.name = 'action';
        actionInput.value = action;
        form.appendChild(actionInput);

        const csrfInput = document.createElement('input');
        csrfInput.type = 'hidden';
        csrfInput.name = 'csrf_token';
        csrfInput.value = CSRF_TOKEN;
        form.appendChild(csrfInput);

        for (const key in data) {
            if (Array.isArray(data[key])) {
                data[key].forEach(val => {
                    const input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = `${key}[]`;
                    input.value = val;
                    form.appendChild(input);
                });
            } else {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = data[key];
                form.appendChild(input);
            }
        }

        document.body.appendChild(form);
        form.submit();
    }

    async function viewAttendancePhotos(traineeId) {
        Swal.fire({
            title: 'Attendance Photos',
            html: '<div id="photo_viewer" class="grid grid-cols-2 gap-4 max-h-[400px] overflow-y-auto p-2">Loading photos...</div>',
            width: '600px',
            didOpen: () => {
                fetch(`get_photos.php?trainee_id=${traineeId}`)
                    .then(res => res.json())
                    .then(data => {
                        const container = document.getElementById('photo_viewer');
                        if (data.length === 0) {
                            container.innerHTML = '<p class="col-span-2 text-center text-slate-400">No photos found.</p>';
                            return;
                        }
                        container.innerHTML = data.map(p => `
                            <div class="space-y-1">
                                <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest text-center">${p.date} (${p.type})</p>
                                <img src="../serve_image.php?file=${p.photo}&trainee_id=${traineeId}" class="w-full h-32 object-cover rounded-xl border border-slate-100">
                            </div>
                        `).join('');
                    });
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        
        if (urlParams.has('msg')) {
            const msg = urlParams.get('msg');
            let title = 'Success!';
            let text = 'Action completed successfully.';
            let icon = 'success';

            switch(msg) {
                case 'created':
                    text = 'New trainee has been successfully added.';
                    break;
                case 'updated':
                    text = 'Trainee profile has been updated.';
                    break;
                case 'transferred':
                    text = 'Trainee has been moved to the new department.';
                    break;
                case 'sanctioned':
                    title = 'Sanction Added';
                    text = 'Penalty hours have been applied.';
                    icon = 'warning';
                    break;
                case 'adjusted':
                    text = 'Trainee hours have been adjusted.';
                    break;
                case 'schedule_set':
                    text = 'Shift schedule has been saved.';
                    break;
                case 'approved':
                    text = 'Trainee account is now active.';
                    break;
                case 'deleted':
                    title = 'Deleted';
                    text = 'Trainee has been soft-deleted.';
                    icon = 'info';
                    break;
            }

            Swal.fire({
                title: title,
                text: text,
                icon: icon,
                timer: 3000,
                showConfirmButton: false,
                timerProgressBar: true
            });

            window.history.replaceState({}, document.title, window.location.pathname);
        }
    });
</script>

<?php 
include __DIR__ . '/../includes/components/schedule_calendar_modal.php';
renderFooter(); 
?>