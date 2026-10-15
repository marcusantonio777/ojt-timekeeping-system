<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('supervisor');

$user_id = $_SESSION['user_id'];
// Get Supervisor Department
$stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ?");
$stmt->execute([$user_id]);
$supervisor = $stmt->fetch();
$dept = $supervisor['department'];

/**
 * Handle POST Actions (Limited for Supervisor)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: trainees.php?error=csrf_failed");
        exit();
    }

    $trainee_id = $_POST['trainee_id'] ?? null;
    
    // Verify Supervisor Password
    $stmt = $pdo->prepare("SELECT password FROM trainees WHERE id = ?");
    $stmt->execute([$user_id]);
    $sup_data = $stmt->fetch();
    
    if (!password_verify($_POST['admin_password'], $sup_data['password'])) {
        header("Location: trainees.php?error=invalid_password");
        exit();
    }

    if ($_POST['action'] === 'set_schedule') {
        $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
        $dates = array_values(array_filter(array_map('trim', $dates)));
        $perDay = !empty($_POST['shift_starts']) && is_array($_POST['shift_starts'])
            && count($_POST['shift_starts']) === count($dates);
        
        // Verify trainee belongs to dept
        $stmt = $pdo->prepare("SELECT id FROM trainees WHERE id = ? AND department = ?");
        $stmt->execute([$trainee_id, $dept]);
        if (!$stmt->fetch()) {
            die("Unauthorized");
        }

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
            logAudit($pdo, $user_id, 'SET_SCHEDULE', "Supervisor set schedule for OJT ID $trainee_id");
            try {
                $stmt = $pdo->prepare("SELECT email, first_name, last_name, department FROM trainees WHERE id = ? AND deleted_at IS NULL");
                $stmt->execute([$trainee_id]);
                $trow = $stmt->fetch();
                if ($trow && !empty($trow['email'])) {
                    $minDate = min($dates);
                    $maxDate = max($dates);
                    $subject = "Schedule updated";
                    $body = "Your schedule has been updated by your Supervisor.\n\nDepartment: {$trow['department']}\nAffected dates: {$minDate}" . ($minDate !== $maxDate ? " to {$maxDate}" : "") . "\n";
                    enqueueSystemEmail($trow['email'], $subject, $body);
                }
            } catch (Exception $e) {
            }
            header("Location: trainees.php?status=schedule_set");
        } catch (Exception $e) {
            $pdo->rollBack();
            header("Location: trainees.php?error=db_error");
        }
        exit();
    }
    if ($_POST['action'] === 'clear_schedule_days') {
        $dates = isset($_POST['dates']) && is_array($_POST['dates']) ? $_POST['dates'] : [];
        $dates = array_values(array_filter(array_map('trim', $dates)));
        $stmt = $pdo->prepare("SELECT id FROM trainees WHERE id = ? AND department = ?");
        $stmt->execute([$trainee_id, $dept]);
        if (!$stmt->fetch()) {
            die("Unauthorized");
        }
        if (!empty($dates)) {
            $placeholders = implode(',', array_fill(0, count($dates), '?'));
            $stmt = $pdo->prepare("DELETE FROM schedules WHERE trainee_id = ? AND date IN ($placeholders)");
            $stmt->execute(array_merge([$trainee_id], $dates));
            logAudit($pdo, $user_id, 'CLEAR_SCHEDULE', "Supervisor cleared schedule days for OJT ID $trainee_id");
        }
        header("Location: trainees.php?status=schedule_cleared");
        exit();
    }
    elseif ($_POST['action'] === 'rate') {
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];
        
        // Verify trainee belongs to dept
        $stmt = $pdo->prepare("SELECT id FROM trainees WHERE id = ? AND department = ?");
        $stmt->execute([$trainee_id, $dept]);
        if (!$stmt->fetch()) die("Unauthorized");

        $stmt = $pdo->prepare("UPDATE trainees SET rating = ?, rating_comment = ? WHERE id = ?");
        $stmt->execute([$rating, $comment, $trainee_id]);
        logAudit($pdo, $user_id, 'RATE_TRAINEE', "Supervisor rated OJT ID $trainee_id with $rating stars");
        header("Location: trainees.php?status=rated");
        exit();
    }
}

// Pagination & Search Settings
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Status filter: Active is the default view. Graduated/completed trainees
// are only shown when the Supervisor explicitly selects the Graduated tab.
$view_status = isset($_GET['status']) ? $_GET['status'] : 'active';
if (!in_array($view_status, ['active', 'completed'], true)) {
    $view_status = 'active';
}

// Build Query (Locked to Department + Selected Status)
$query = "SELECT t.*, 
    (SELECT SUM(hours_worked) FROM attendance WHERE trainee_id = t.id AND time_out IS NOT NULL AND deleted_at IS NULL) as total_hours_standard,
    (SELECT SUM(verified_ot_seconds) FROM attendance WHERE trainee_id = t.id AND deleted_at IS NULL) as total_ot_seconds,
    (SELECT SUM(hours_added) FROM sanctions WHERE trainee_id = t.id AND deleted_at IS NULL) as total_sanctions,
    (SELECT COUNT(*) FROM transfers WHERE trainee_id = t.id AND deleted_at IS NULL) as total_transfers
    FROM trainees t 
    WHERE t.role = 'trainee' AND t.department = ? AND t.status = ? AND t.deleted_at IS NULL";

$params = [$dept, $view_status];
if ($search) {
    $query .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.username LIKE ? OR t.school LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

// Get total count for pagination
$count_query = "SELECT COUNT(*) FROM trainees t WHERE t.role = 'trainee' AND t.department = ? AND t.status = ? AND t.deleted_at IS NULL";
$count_params = [$dept, $view_status];
if ($search) {
    $count_query .= " AND (t.first_name LIKE ? OR t.last_name LIKE ? OR t.username LIKE ? OR t.school LIKE ?)";
    $count_params = array_merge($count_params, [$search_param, $search_param, $search_param, $search_param]);
}
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($count_params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query .= " ORDER BY t.created_at DESC LIMIT $limit OFFSET $offset";
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

$csrf_token = generateCsrfToken();
renderHeader($dept . ' Trainees');
renderSidebar('trainees');
?>

<div class="space-y-8">
    <div class="flex flex-col lg:flex-row lg:justify-between lg:items-center gap-4">
        <div>
            <h1 class="text-3xl font-bold text-slate-900"><?php echo $dept; ?> Directory</h1>
            <p class="text-sm text-slate-500 mt-1">Monitor and evaluate trainees in your department.</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center lg:justify-end">
            <div class="relative group w-full sm:w-64">
                <input type="text" id="traineeSearch" placeholder="Search trainee..." value="<?php echo htmlspecialchars($search); ?>" class="pl-10 pr-4 py-2 bg-white border border-slate-200 rounded-lg text-sm focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all w-full group-hover:border-slate-300">
                <svg class="w-4 h-4 absolute left-3.5 top-3 text-slate-400 group-hover:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
            </div>
            <button onclick="openExportAllDtrsModal()" class="px-4 py-2 bg-indigo-50 text-indigo-600 rounded-lg text-xs font-bold hover:bg-indigo-600 hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>
                Export All DTRs
            </button>
            <a href="requests.php" class="px-4 py-2 bg-blue-50 text-blue-600 rounded-lg text-xs font-bold hover:bg-blue-600 hover:text-white transition-all uppercase tracking-wider flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                Requests
            </a>
        </div>
    </div>

    <!-- Trainee status filter: Active is the default; Graduated is opt-in. -->
    <div class="flex items-center gap-2">
        <a href="trainees.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>"
           class="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wider transition-all <?php echo $view_status === 'active' ? 'bg-[#6D3089] text-white shadow-sm' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50'; ?>">
            Active
        </a>
        <a href="trainees.php?status=completed<?php echo $search ? '&search=' . urlencode($search) : ''; ?>"
           class="px-4 py-2 rounded-lg text-xs font-bold uppercase tracking-wider transition-all <?php echo $view_status === 'completed' ? 'bg-indigo-600 text-white shadow-sm' : 'bg-white text-slate-500 border border-slate-200 hover:bg-slate-50'; ?>">
            Graduated
        </a>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200">
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Trainee</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider">School/Course</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center">Hours Progress</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-center">Status</th>
                        <th class="px-6 py-4 text-xs font-bold text-slate-500 uppercase tracking-wider text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($trainees)): ?>
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-sm text-slate-400 italic">No trainees found in your department.</p>
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
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center font-bold text-[#6D3089] text-sm uppercase">
                                    <?php echo substr($t['first_name'], 0, 1) . substr($t['last_name'], 0, 1); ?>
                                </div>
                                <div>
                                    <p class="text-sm font-semibold text-slate-900"><?php echo e($t['first_name'] . ' ' . $t['last_name']); ?></p>
                                    <p class="text-xs text-slate-500">@<?php echo e($t['username']); ?></p>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4">
                            <p class="text-xs font-bold text-slate-700 uppercase"><?php echo e($t['school']); ?></p>
                            <p class="text-[10px] text-slate-400 font-bold uppercase mt-1"><?php echo e($t['course']); ?></p>
                        </td>
                        <td class="px-6 py-4">
                            <div class="w-32 mx-auto">
                                <div class="flex justify-between items-center mb-1 text-[10px] font-bold">
                                    <span class="text-slate-500"><?php echo floor($rendered); ?>/<?php echo $goal; ?>h</span>
                                    <span class="<?php echo $percent >= 100 ? 'text-emerald-600' : 'text-slate-400'; ?>"><?php echo round($percent); ?>%</span>
                                </div>
                                <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                                    <div class="h-full rounded-full <?php echo $percent >= 100 ? 'bg-emerald-500' : 'bg-[#6D3089]'; ?>" style="width: <?php echo $percent; ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-4 text-center">
                            <?php if ($t['status'] === 'pending'): ?>
                                <span class="bg-amber-100 text-amber-700 px-2 py-1 rounded text-[10px] font-bold uppercase">Pending</span>
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
                                <button onclick="viewTraineeProfile(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-[#6D3089] hover:bg-slate-50 rounded-lg transition-all" title="View Full Profile">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                                <button onclick="viewAttendancePhotos(<?php echo $t['id']; ?>)" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-slate-50 rounded-lg transition-all" title="View Photos">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h14a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                </button>
                                <button onclick="openRatingModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-amber-500 hover:bg-slate-50 rounded-lg transition-all" title="Rate Performance">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.382-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                                </button>
                                <button onclick="openSchedulerModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-emerald-600 hover:bg-slate-50 rounded-lg transition-all" title="Set Schedule">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                </button>
                                <button onclick="openScheduleCalendar(<?php echo $t['id']; ?>, '<?php echo addslashes($t['first_name'] . ' ' . $t['last_name']); ?>')" class="p-2 text-slate-400 hover:text-[#6D3089] hover:bg-slate-50 rounded-lg transition-all" title="View Schedule Calendar">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                </button>
                                <button onclick="openExportDtrModal(<?php echo htmlspecialchars(json_encode($t)); ?>)" class="p-2 text-slate-400 hover:text-blue-600 hover:bg-slate-50 rounded-lg transition-all" title="Export DTR (CSV)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
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
                    <a href="?page=<?php echo $page - 1; ?>&status=<?php echo urlencode($view_status); ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-[10px] font-black text-slate-600 hover:bg-slate-50 transition-all uppercase">Prev</a>
                <?php endif; ?>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?>&status=<?php echo urlencode($view_status); ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="px-3 py-1.5 <?php echo $i == $page ? 'bg-[#6D3089] text-white border-[#6D3089]' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'; ?> border rounded text-[10px] font-black transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?>&status=<?php echo urlencode($view_status); ?><?php echo $search ? '&search='.urlencode($search) : ''; ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded text-[10px] font-black text-slate-600 hover:bg-slate-50 transition-all uppercase">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Live Search
    let searchTimer;
    const searchInput = document.getElementById('traineeSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                const val = e.target.value;
                const status = '<?php echo $view_status; ?>';
                 let url = `trainees.php?status=${encodeURIComponent(status)}`;
                 if (val.trim() !== '') {
                     url += `&search=${encodeURIComponent(val)}`;
                 }
                 window.location.href = url;
            }, 500);
        });
        if (searchInput.value) {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
    }

    const CSRF_TOKEN = '<?php echo $csrf_token; ?>';
    const ALL_TRANSFERS = <?php echo json_encode($transfers_data); ?>;
    const ALL_SANCTIONS = <?php echo json_encode($sanctions_data); ?>;

    async function openRatingModal(t) {
        const { value: formValues } = await Swal.fire({
            title: 'Rate Performance',
            html: `
                <div class="text-left space-y-4">
                    <p class="text-xs text-slate-500 uppercase font-bold tracking-widest">Evaluating: ${t.first_name} ${t.last_name}</p>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Rating</label>
                        <select id="rating" class="swal2-input w-full m-0 mt-1 text-sm">
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3" selected>3 - Satisfactory</option>
                            <option value="2">2 - Needs Improvement</option>
                            <option value="1">1 - Poor</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Comments</label>
                        <textarea id="comment" class="swal2-textarea w-full m-0 mt-1 text-sm" placeholder="Evaluation feedback...">${t.rating_comment || ''}</textarea>
                    </div>
                    <div class="pt-4 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Supervisor Password to Confirm</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'Submit Rating',
            preConfirm: () => {
                const pass = document.getElementById('admin_pass').value;
                if(!pass) { Swal.showValidationMessage('Password required'); return false; }
                return {
                    trainee_id: t.id,
                    rating: document.getElementById('rating').value,
                    comment: document.getElementById('comment').value,
                    admin_password: pass
                }
            }
        });
        if (formValues) submitPostAction('rate', formValues);
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
                    <div class="pt-2 border-t"><label class="text-xs font-bold text-slate-400 uppercase">Supervisor password</label><input type="password" id="admin_pass" class="swal2-input w-full m-0 mt-1"></div>
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

    function viewTraineeProfile(t) {
        const transfers = ALL_TRANSFERS[t.id] || [];
        const sanctions = ALL_SANCTIONS[t.id] || [];
        
        let html = `
            <div class="text-left space-y-6">
                <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-xl">
                    <div class="w-16 h-16 rounded-full bg-[#6D3089] text-white flex items-center justify-center text-2xl font-bold">
                        ${t.first_name[0]}${t.last_name[0]}
                    </div>
                    <div>
                        <h4 class="font-bold text-slate-900">${t.first_name} ${t.last_name}</h4>
                        <p class="text-xs text-slate-500">${t.email || 'No email'}</p>
                        <p class="text-[10px] font-bold text-[#6D3089] uppercase mt-1">${t.department} Department</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="p-3 border border-slate-100 rounded-lg">
                        <p class="text-[9px] font-bold text-slate-400 uppercase">School</p>
                        <p class="text-xs font-semibold text-slate-700 mt-1">${t.school}</p>
                    </div>
                    <div class="p-3 border border-slate-100 rounded-lg">
                        <p class="text-[9px] font-bold text-slate-400 uppercase">Course</p>
                        <p class="text-xs font-semibold text-slate-700 mt-1">${t.course}</p>
                    </div>
                </div>

                <div>
                    <h5 class="text-[10px] font-bold text-slate-400 uppercase mb-3">Penalty Records</h5>
                    ${sanctions.length ? sanctions.map(s => `
                        <div class="text-xs p-2 border-l-2 border-rose-400 bg-rose-50/30 mb-2">
                            <p class="font-bold text-rose-600">+${s.hours_added} Hours</p>
                            <p class="text-slate-600 mt-1">${s.reason}</p>
                            <p class="text-[10px] text-slate-400 mt-1">${new Date(s.created_at).toLocaleDateString()} • By ${s.admin_name}</p>
                        </div>
                    `).join('') : '<p class="text-xs text-slate-400 italic">No penalties recorded.</p>'}
                </div>
            </div>
        `;

        Swal.fire({
            title: 'Trainee Information',
            html: html,
            width: '500px',
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'CLOSE'
        });
    }

    async function viewAttendancePhotos(traineeId) {
        Swal.fire({
            title: 'Attendance Photos',
            html: '<div id="photo_viewer" class="grid grid-cols-2 gap-4 max-h-[400px] overflow-y-auto p-2">Loading photos...</div>',
            width: '600px',
            didOpen: () => {
                fetch(`../admin/get_photos.php?trainee_id=${traineeId}`)
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

    async function submitPostAction(action, data) {
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

    // Place this inside your <script> tag to process server-side redirect states
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    
    // Configuration for non-intrusive, professional toast alerts
    const SystemToast = Swal.mixin({
        toast: true,
        position: 'top-end',
        showConfirmButton: false,
        timer: 3500,
        timerProgressBar: true,
        iconColor: '#6D3089', // Matches your application's primary color
        customClass: {
            popup: 'rounded-xl shadow-lg border border-slate-100 bg-white p-4',
            title: 'text-sm font-semibold text-slate-800 tracking-wide font-sans'
        },
        didOpen: (toast) => {
            toast.addEventListener('mouseenter', Swal.stopTimer);
            toast.addEventListener('mouseleave', Swal.resumeTimer);
        }
    });

    // Process Successful Operations
    if (urlParams.has('status')) {
        const status = urlParams.get('status');
        let successMessage = 'Operation completed successfully.';
        
        switch (status) {
            case 'schedule_set':
                successMessage = 'Trainee shift schedule has been successfully updated.';
                break;
            case 'schedule_cleared':
                successMessage = 'Selected shift records have been removed from the directory.';
                break;
            case 'rated':
                successMessage = 'Performance evaluation has been successfully submitted.';
                break;
        }

        SystemToast.fire({
            icon: 'success',
            title: successMessage
        });
        
        // Clean URL parameters cleanly without triggering a page refresh
        window.history.replaceState({}, document.title, window.location.pathname);
    }

    // Process System Errors
    if (urlParams.has('error')) {
        const error = urlParams.get('error');
        let errorMessage = 'An unhandled system anomaly occurred. Please contact an administrator.';
        let errorTitle = 'Execution Error';
        
        if (error === 'invalid_password') {
            errorTitle = 'Authentication Failed';
            errorMessage = 'The supervisor verification password provided is incorrect.';
        } else if (error === 'csrf_failed') {
            errorTitle = 'Security Violation';
            errorMessage = 'Cross-Site Request Forgery (CSRF) validation failed. Session may have expired.';
        } else if (error === 'db_error') {
            errorTitle = 'Database Failure';
            errorMessage = 'The system encountered an error writing data to the persistence layer.';
        }

        Swal.fire({
            icon: 'error',
            title: errorTitle,
            text: errorMessage,
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'Acknowledge',
            customClass: {
                popup: 'rounded-2xl border border-slate-200 shadow-xl',
                title: 'text-lg font-bold text-slate-900',
                htmlContainer: 'text-sm text-slate-600',
                confirmButton: 'px-5 py-2.5 rounded-lg text-xs font-bold uppercase tracking-wider transition-all'
            }
        });
        
        window.history.replaceState({}, document.title, window.location.pathname);
    }
});


    async function openExportDtrModal(trainee) {
        const currentDate = new Date();
        const currentYear = currentDate.getFullYear();
        const currentMonth = String(currentDate.getMonth() + 1).padStart(2, '0');
        
        const months = [
            { value: '01', label: 'January' },
            { value: '02', label: 'February' },
            { value: '03', label: 'March' },
            { value: '04', label: 'April' },
            { value: '05', label: 'May' },
            { value: '06', label: 'June' },
            { value: '07', label: 'July' },
            { value: '08', label: 'August' },
            { value: '09', label: 'September' },
            { value: '10', label: 'October' },
            { value: '11', label: 'November' },
            { value: '12', label: 'December' }
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
            // Format month as YYYY-MM for export_dtr.php compatibility
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
            { value: '01', label: 'January' },
            { value: '02', label: 'February' },
            { value: '03', label: 'March' },
            { value: '04', label: 'April' },
            { value: '05', label: 'May' },
            { value: '06', label: 'June' },
            { value: '07', label: 'July' },
            { value: '08', label: 'August' },
            { value: '09', label: 'September' },
            { value: '10', label: 'October' },
            { value: '11', label: 'November' },
            { value: '12', label: 'December' }
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
                    dept: '<?php echo addslashes($dept); ?>'
                };
            }
        });
        
        if (formValues) {
            const url = new URL('../admin/export_all_dtrs.php', window.location.href);
            url.searchParams.set('month', formValues.month);
            url.searchParams.set('year', formValues.year);
            url.searchParams.set('period', formValues.period);
            url.searchParams.set('dept', formValues.dept);
            window.location.href = url.toString();
        }
    }


</script>

<?php 
include __DIR__ . '/../includes/components/schedule_calendar_modal.php';
renderFooter(); 
?>
