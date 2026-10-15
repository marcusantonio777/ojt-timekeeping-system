<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('supervisor');

$user_id = $_SESSION['user_id'];
$stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ?");
$stmt->execute([$user_id]);
$supervisor = $stmt->fetch();
$dept = $supervisor['department'];

$csrf_token = generateCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: requests.php?error=csrf_failed");
        exit();
    }

    if ($_POST['action'] === 'approve_request') {
        $req_id = (int) $_POST['request_id'];
        $note = trim($_POST['note'] ?? '');

        $stmt = $pdo->prepare("UPDATE requests r 
        JOIN trainees t ON r.trainee_id = t.id 
        SET r.supervisor_status = 'approved', 
            r.supervisor_id = ?, 
            r.supervisor_note = ?, 
            r.supervisor_approved_at = CURRENT_TIMESTAMP 
        WHERE r.id = ? AND t.department = ? AND r.archived_at IS NULL");
        $stmt->execute([$user_id, $note, $req_id, $dept]);

        logAudit($pdo, $user_id, 'SUPERVISOR_APPROVE', "Approved request ID $req_id");
        try {
            $stmt = $pdo->prepare("SELECT r.type, r.start_date, r.end_date, r.start_time, r.end_time, t.email, t.first_name, t.last_name, t.department FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE r.id = ? AND t.department = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL");
            $stmt->execute([$req_id, $dept]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                $subject = "Request approved by Supervisor ({$row['type']})";
                $body = "Your request has been approved by your Supervisor.\n\nType: {$row['type']}\nDate: {$row['start_date']}" . ($row['end_date'] ? " to {$row['end_date']}" : "") . "\n" . ($row['start_time'] ? "Time: {$row['start_time']} - {$row['end_time']}\n" : "") . ($note ? "Supervisor Note: {$note}\n" : "");
                enqueueSystemEmail($row['email'], $subject, $body);
            }
            $stmt = $pdo->prepare("SELECT email FROM trainees WHERE role IN ('admin','superadmin') AND deleted_at IS NULL AND email IS NOT NULL AND email <> ''");
            $stmt->execute();
            $admins = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($row) {
                $subject = "Supervisor approved request - {$row['department']} ({$row['type']})";
                $body = "A request is ready for HR final review.\n\nTrainee: {$row['first_name']} {$row['last_name']}\nDepartment: {$row['department']}\nType: {$row['type']}\nDate: {$row['start_date']}" . ($row['end_date'] ? " to {$row['end_date']}" : "") . "\n";
                foreach ($admins as $to) {
                    enqueueSystemEmail($to, $subject, $body);
                }
            }
        } catch (Exception $e) {
        }
        header("Location: requests.php?status=approved");
        exit();
    }

    if ($_POST['action'] === 'reject_request') {
        $req_id = (int) $_POST['request_id'];
        $note = trim($_POST['note'] ?? '');

        $stmt = $pdo->prepare("UPDATE requests r 
                             JOIN trainees t ON r.trainee_id = t.id 
                             SET r.supervisor_status = 'rejected', r.supervisor_id = ?, r.supervisor_note = ?, r.hr_status = 'rejected', r.archived_at = CURRENT_TIMESTAMP
                             WHERE r.id = ? AND t.department = ? AND r.archived_at IS NULL");
        $stmt->execute([$user_id, $note, $req_id, $dept]);

        logAudit($pdo, $user_id, 'SUPERVISOR_REJECT', "Rejected request ID $req_id");
        try {
            $stmt = $pdo->prepare("SELECT r.type, r.start_date, r.end_date, r.start_time, r.end_time, t.email FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE r.id = ? AND t.department = ? AND r.deleted_at IS NULL AND t.deleted_at IS NULL");
            $stmt->execute([$req_id, $dept]);
            $row = $stmt->fetch();
            if ($row && !empty($row['email'])) {
                $subject = "Request rejected by Supervisor ({$row['type']})";
                $body = "Your request has been rejected by your Supervisor.\n\nType: {$row['type']}\nDate: {$row['start_date']}" . ($row['end_date'] ? " to {$row['end_date']}" : "") . "\n" . ($row['start_time'] ? "Time: {$row['start_time']} - {$row['end_time']}\n" : "") . ($note ? "Supervisor Note: {$note}\n" : "");
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
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $sql = "UPDATE requests r 
                INNER JOIN trainees t ON r.trainee_id = t.id 
                SET r.supervisor_status = 'approved', r.supervisor_id = ?, r.supervisor_note = ?
                WHERE r.id IN ($placeholders) AND t.department = ? AND r.supervisor_status = 'pending' 
                AND r.deleted_at IS NULL AND (r.archived_at IS NULL)";
            $params = array_merge([$user_id, $note], $ids, [$dept]);
            $pdo->prepare($sql)->execute($params);
            logAudit($pdo, $user_id, 'SUPERVISOR_BULK_APPROVE', 'Bulk approved requests: ' . implode(',', $ids));
        }
        header("Location: requests.php?status=bulk_approved");
        exit();
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
            $sql = "UPDATE requests r 
                INNER JOIN trainees t ON r.trainee_id = t.id 
                SET r.supervisor_status = 'rejected', r.supervisor_id = ?, r.supervisor_note = ?, r.hr_status = 'rejected', r.archived_at = CURRENT_TIMESTAMP
                WHERE r.id IN ($placeholders) AND t.department = ? AND r.supervisor_status = 'pending' 
                AND r.deleted_at IS NULL AND (r.archived_at IS NULL)";
            $params = array_merge([$user_id, $note], $ids, [$dept]);
            $pdo->prepare($sql)->execute($params);
            logAudit($pdo, $user_id, 'SUPERVISOR_BULK_REJECT', 'Bulk rejected requests: ' . implode(',', $ids));
        }
        header("Location: requests.php?status=bulk_rejected");
        exit();
    }
}

// Fetch Active Trainee Requests
$stmt = $pdo->prepare("
    SELECT r.*, t.first_name, t.last_name, t.username, t.status as trainee_status 
    FROM requests r 
    JOIN trainees t ON r.trainee_id = t.id 
    WHERE t.department = ? 
      AND (t.status = 'active' OR t.status IS NULL)
      AND r.deleted_at IS NULL 
      AND r.archived_at IS NULL
    ORDER BY r.supervisor_status ASC, r.created_at DESC
");
$stmt->execute([$dept]);
$dept_requests = $stmt->fetchAll();

// Fetch Graduated / Completed Trainee Requests
$stmt = $pdo->prepare("
    SELECT r.*, t.first_name, t.last_name, t.username, t.status as trainee_status 
    FROM requests r 
    JOIN trainees t ON r.trainee_id = t.id 
    WHERE t.department = ? 
      AND t.status = 'graduated'
      AND r.deleted_at IS NULL 
      AND r.archived_at IS NULL
    ORDER BY r.supervisor_status ASC, r.created_at DESC
");
$stmt->execute([$dept]);
$graduated_requests = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT r.*, t.first_name, t.last_name, t.username 
    FROM requests r 
    JOIN trainees t ON r.trainee_id = t.id 
    WHERE t.department = ? AND r.deleted_at IS NULL AND r.archived_at IS NOT NULL
    ORDER BY r.archived_at DESC
    LIMIT 200
");
$stmt->execute([$dept]);
$archived_requests = $stmt->fetchAll();

renderHeader($dept . ' Requests');
renderSidebar('requests');
?>

<div class="space-y-8">
    <div class="flex flex-wrap justify-between gap-4 items-end">
        <div>
            <h1 class="text-3xl font-black text-slate-800 uppercase tracking-tighter"><?php echo $dept; ?> Requests</h1>
            <p class="text-xs font-bold text-slate-400 uppercase tracking-[0.3em]">Review Leave, OT, and Undertime</p>
        </div>
        <div class="flex flex-wrap gap-2 items-center">
            <details class="relative">
                <summary class="list-none cursor-pointer select-none px-4 py-2.5 bg-white border border-slate-200 text-slate-800 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all inline-flex items-center gap-2">
                    Actions
                    <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"></path></svg>
                </summary>
                <div class="absolute right-0 mt-2 w-64 bg-white border border-slate-200 rounded-2xl shadow-xl shadow-slate-900/10 overflow-hidden z-30">
                    <div class="p-2 space-y-1">
                        <button type="button" onclick="toggleSelectAllPending()" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 hover:bg-slate-50">Select pending</button>
                        <button type="button" onclick="bulkSupervisorAction('bulk_approve_requests')" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-emerald-700 hover:bg-emerald-50">Approve selected</button>
                        <button type="button" onclick="bulkSupervisorAction('bulk_reject_requests')" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-rose-700 hover:bg-rose-50">Reject selected</button>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- Active vs Graduated Switcher Tabs -->
    <div class="flex items-center gap-2">
        <button type="button" onclick="switchTab('active')" id="btn-tab-active" 
            class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-[#6D3089] text-white transition-all shadow-sm">
            Active Trainees
        </button>
        <button type="button" onclick="switchTab('graduated')" id="btn-tab-graduated" 
            class="px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition-all shadow-sm">
            Graduated / Completed
        </button>
    </div>

    <form id="bulk_form" method="post" class="hidden">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="action" id="bulk_action" value="">
        <input type="hidden" name="bulk_note" id="bulk_note_field" value="">
    </form>

    <!-- Active Requests Table Container (Default) -->
    <div id="container-active" class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                    <th class="px-4 py-5 w-10"></th>
                    <th class="px-8 py-5">Trainee</th>
                    <th class="px-8 py-5">Type</th>
                    <th class="px-8 py-5">Details</th>
                    <th class="px-8 py-5">My Status</th>
                    <th class="px-8 py-5">HR Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($dept_requests)): ?>
                    <tr>
                        <td colspan="7" class="px-8 py-12 text-center text-slate-400 font-bold uppercase text-[10px] tracking-widest">No active requests</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($dept_requests as $r): ?>
                <tr class="hover:bg-slate-50/50 transition-all group">
                    <td class="px-4 py-5">
                        <?php if ($r['supervisor_status'] === 'pending'): ?>
                            <input type="checkbox" name="request_ids[]" value="<?php echo (int) $r['id']; ?>" form="bulk_form" class="req_cb rounded border-slate-300 text-[#6D3089] focus:ring-[#6D3089]" data-pending="1">
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5">
                        <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></p>
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-0.5">@<?php echo htmlspecialchars($r['username']); ?></p>
                    </td>
                    <td class="px-8 py-5">
                        <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest 
                            <?php echo $r['type'] === 'leave' ? 'bg-amber-100 text-amber-600' : ($r['type'] === 'overtime' ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'); ?>">
                            <?php echo htmlspecialchars($r['type']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5">
                        <p class="text-xs font-bold text-slate-700"><?php echo date('M d, Y', strtotime($r['start_date'])); ?><?php echo $r['end_date'] ? ' - '.date('M d, Y', strtotime($r['end_date'])) : ''; ?></p>
                        <?php if ($r['start_time']): ?>
                            <p class="text-[9px] text-slate-400 font-black uppercase mt-0.5"><?php echo date('h:i A', strtotime($r['start_time'])); ?> - <?php echo date('h:i A', strtotime($r['end_time'])); ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest 
                            <?php echo $r['supervisor_status'] === 'approved' ? 'text-emerald-500' : ($r['supervisor_status'] === 'rejected' ? 'text-rose-500' : 'text-amber-500'); ?>">
                            ● <?php echo htmlspecialchars($r['supervisor_status']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest 
                            <?php echo $r['hr_status'] === 'approved' ? 'text-emerald-500' : ($r['hr_status'] === 'rejected' ? 'text-rose-500' : 'text-amber-500'); ?>">
                            ● <?php echo htmlspecialchars($r['hr_status']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <details class="relative inline-block">
                            <summary class="list-none cursor-pointer select-none p-2.5 bg-slate-50 text-slate-500 rounded-xl hover:bg-slate-100 transition-all inline-flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6h.01M12 12h.01M12 18h.01"></path></svg>
                            </summary>
                            <div class="absolute right-0 mt-2 w-52 bg-white border border-slate-200 rounded-2xl shadow-xl shadow-slate-900/10 overflow-hidden z-30">
                                <div class="p-2 space-y-1">
                                    <button type="button" onclick="viewRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 hover:bg-slate-50">View details</button>
                                    <?php if ($r['supervisor_status'] === 'pending'): ?>
                                        <button type="button" onclick="reviewRequest(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-emerald-700 hover:bg-emerald-50">Review & decide</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- Graduated Requests Table Container (Hidden by Default) -->
    <div id="container-graduated" class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                    <th class="px-8 py-5">Trainee (Graduated)</th>
                    <th class="px-8 py-5">Type</th>
                    <th class="px-8 py-5">Details</th>
                    <th class="px-8 py-5">My Status</th>
                    <th class="px-8 py-5">HR Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php if (empty($graduated_requests)): ?>
                    <tr>
                        <td colspan="6" class="px-8 py-12 text-center text-slate-400 font-bold uppercase text-[10px] tracking-widest">No requests from graduated trainees</td>
                    </tr>
                <?php endif; ?>
                <?php foreach ($graduated_requests as $r): ?>
                <tr class="hover:bg-slate-50/50 transition-all group">
                    <td class="px-8 py-5">
                        <p class="text-xs font-bold text-slate-700"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></p>
                        <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-0.5">@<?php echo htmlspecialchars($r['username']); ?></p>
                    </td>
                    <td class="px-8 py-5">
                        <span class="px-3 py-1 rounded-full text-[8px] font-black uppercase tracking-widest 
                            <?php echo $r['type'] === 'leave' ? 'bg-amber-100 text-amber-600' : ($r['type'] === 'overtime' ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-600'); ?>">
                            <?php echo htmlspecialchars($r['type']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5">
                        <p class="text-xs font-bold text-slate-700"><?php echo date('M d, Y', strtotime($r['start_date'])); ?><?php echo $r['end_date'] ? ' - '.date('M d, Y', strtotime($r['end_date'])) : ''; ?></p>
                    </td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest 
                            <?php echo $r['supervisor_status'] === 'approved' ? 'text-emerald-500' : ($r['supervisor_status'] === 'rejected' ? 'text-rose-500' : 'text-amber-500'); ?>">
                            ● <?php echo htmlspecialchars($r['supervisor_status']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest 
                            <?php echo $r['hr_status'] === 'approved' ? 'text-emerald-500' : ($r['hr_status'] === 'rejected' ? 'text-rose-500' : 'text-amber-500'); ?>">
                            ● <?php echo htmlspecialchars($r['hr_status']); ?>
                        </span>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <details class="relative inline-block">
                            <summary class="list-none cursor-pointer select-none p-2.5 bg-slate-50 text-slate-500 rounded-xl hover:bg-slate-100 transition-all inline-flex items-center justify-center">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6h.01M12 12h.01M12 18h.01"></path></svg>
                            </summary>
                            <div class="absolute right-0 mt-2 w-52 bg-white border border-slate-200 rounded-2xl shadow-xl shadow-slate-900/10 overflow-hidden z-30">
                                <div class="p-2 space-y-1">
                                    <button type="button" onclick="viewRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="w-full text-left px-3 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 hover:bg-slate-50">View details</button>
                                </div>
                            </div>
                        </details>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>

    <?php if (!empty($archived_requests)): ?>
    <div>
        <h2 class="text-lg font-black text-slate-600 uppercase tracking-tight mb-3">Archive <span class="text-xs font-bold text-slate-400">(HR-finalized — read only)</span></h2>
        <div class="bg-slate-50 rounded-[2rem] border border-slate-200 overflow-hidden shadow-inner">
            <div class="overflow-x-auto">
            <table class="w-full text-left opacity-90">
                <thead>
                    <tr class="bg-slate-100 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-200">
                        <th class="px-8 py-4">Trainee</th>
                        <th class="px-8 py-4">Type</th>
                        <th class="px-8 py-4">Archived</th>
                        <th class="px-8 py-4">HR</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php foreach ($archived_requests as $r): ?>
                    <tr>
                        <td class="px-8 py-3 text-xs font-bold text-slate-600"><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                        <td class="px-8 py-3"><span class="text-[8px] font-black uppercase"><?php echo htmlspecialchars($r['type']); ?></span></td>
                        <td class="px-8 py-3 text-[10px] text-slate-500"><?php echo $r['archived_at'] ? date('M d, Y g:i A', strtotime($r['archived_at'])) : '—'; ?></td>
                        <td class="px-8 py-3 text-[10px] text-emerald-600 font-bold"><?php echo htmlspecialchars($r['hr_status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
const CSRF = <?php echo json_encode($csrf_token); ?>;

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function switchTab(tabName) {
    const activeContainer = document.getElementById('container-active');
    const graduatedContainer = document.getElementById('container-graduated');
    const btnActive = document.getElementById('btn-tab-active');
    const btnGraduated = document.getElementById('btn-tab-graduated');

    if (tabName === 'active') {
        activeContainer.classList.remove('hidden');
        graduatedContainer.classList.add('hidden');
        
        btnActive.className = "px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-[#6D3089] text-white transition-all shadow-sm";
        btnGraduated.className = "px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition-all shadow-sm";
    } else {
        activeContainer.classList.add('hidden');
        graduatedContainer.classList.remove('hidden');
        
        btnGraduated.className = "px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-[#6D3089] text-white transition-all shadow-sm";
        btnActive.className = "px-4 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition-all shadow-sm";
    }
}

function toggleSelectAllPending() {
    const boxes = document.querySelectorAll('input.req_cb[data-pending="1"]');
    const anyUnchecked = Array.from(boxes).some(b => !b.checked);
    boxes.forEach(b => { b.checked = anyUnchecked; });
}

async function bulkSupervisorAction(action) {
    const boxes = document.querySelectorAll('input.req_cb:checked');
    if (!boxes.length) {
        Swal.fire({ icon: 'warning', title: 'None selected', text: 'Select one or more pending requests.', confirmButtonColor: '#6D3089' });
        return;
    }
    const { value: note, isConfirmed } = await Swal.fire({
        title: action.indexOf('approve') !== -1 ? 'Approve selected' : 'Reject selected',
        input: 'textarea',
        inputLabel: 'Optional note (applies to all selected)',
        inputPlaceholder: 'Note for HR...',
        showCancelButton: true,
        confirmButtonColor: action.indexOf('approve') !== -1 ? '#059669' : '#e11d48',
        confirmButtonText: 'Confirm'
    });
    if (!isConfirmed) return;
    document.getElementById('bulk_action').value = action;
    document.getElementById('bulk_note_field').value = note || '';
    document.getElementById('bulk_form').submit();
}

async function reviewRequest(r) {
    const { value: result } = await Swal.fire({
        title: 'Review Request',
        html: `
            <div class="text-left space-y-4">
                <p class="text-xs text-slate-500 uppercase font-black tracking-widest">Reviewing: ${escapeHtml(r.first_name)} ${escapeHtml(r.last_name)}</p>
                <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Trainee Reason</p>
                    <p class="text-xs text-slate-600 mt-1 leading-relaxed">${escapeHtml(r.reason)}</p>
                </div>
                ${r.attachment_path ? `
                <div class="p-3 bg-blue-50 rounded-xl border border-blue-100">
                    <div class="flex items-center justify-between">
                        <p class="text-[8px] font-black text-blue-600 uppercase tracking-widest">Attachment</p>
                        <a href="../serve_image.php?request_id=${r.id}" target="_blank" class="text-[10px] font-black text-blue-700 uppercase tracking-widest hover:underline">Open</a>
                    </div>
                    <p class="text-[10px] font-bold text-blue-800 mt-1 truncate">${escapeHtml(r.attachment_name || 'Attachment')}</p>
                </div>
                ` : ''}
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500">Your Note (Optional)</label>
                    <textarea id="rev_note" class="swal2-textarea w-full m-0 mt-1" placeholder="Add a note for HR..."></textarea>
                </div>
            </div>
        `,
        showCancelButton: true,
        showDenyButton: true,
        confirmButtonText: 'APPROVE',
        denyButtonText: 'REJECT',
        confirmButtonColor: '#10B981',
        denyButtonColor: '#F43F5E',
        preConfirm: () => {
            return { action: 'approve_request', note: document.getElementById('rev_note').value };
        },
        preDeny: () => {
            return { action: 'reject_request', note: document.getElementById('rev_note').value };
        }
    });

    if (result) {
        const form = document.createElement('form');
        form.method = 'POST';
        [['action', result.action], ['csrf_token', CSRF], ['request_id', String(r.id)], ['note', result.note || '']].forEach(([name, val]) => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = name;
            inp.value = val;
            form.appendChild(inp);
        });
        document.body.appendChild(form);
        form.submit();
    }
}

function viewRequestDetails(r) {
    Swal.fire({
        title: 'Request Details',
        html: `
            <div class="text-left space-y-4 p-2">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Type</p>
                        <p class="text-xs font-bold text-slate-700 uppercase">${r.type}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Submitted</p>
                        <p class="text-xs font-bold text-slate-700">${new Date(r.created_at).toLocaleDateString()}</p>
                    </div>
                </div>
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Period</p>
                    <p class="text-xs font-bold text-slate-700">${r.start_date} ${r.end_date ? ' to ' + r.end_date : ''}</p>
                    ${r.start_time ? `<p class="text-[10px] text-slate-500 font-bold">${r.start_time} - ${r.end_time}</p>` : ''}
                </div>
                <div>
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">Reason</p>
                    <p class="text-xs text-slate-600 bg-slate-50 p-3 rounded-xl mt-1 leading-relaxed">${escapeHtml(r.reason)}</p>
                </div>
                ${r.attachment_path ? `
                <div class="border-t pt-4">
                    <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2">Attachment</p>
                    <a href="../serve_image.php?request_id=${r.id}" target="_blank" class="inline-flex items-center gap-2 px-4 py-2 bg-blue-50 text-blue-700 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-blue-700 hover:text-white transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 10v6m0 0l-3-3m3 3l3-3M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1"></path></svg>
                        Download
                    </a>
                </div>
                ` : ''}
                <div class="grid grid-cols-2 gap-4 border-t pt-4">
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">My Status</p>
                        <p class="text-xs font-black uppercase ${r.supervisor_status === 'approved' ? 'text-emerald-500' : (r.supervisor_status === 'rejected' ? 'text-rose-500' : 'text-amber-500')}">${r.supervisor_status}</p>
                    </div>
                    <div>
                        <p class="text-[8px] font-black text-slate-400 uppercase tracking-widest">HR Final Status</p>
                        <p class="text-xs font-black uppercase ${r.hr_status === 'approved' ? 'text-emerald-500' : (r.hr_status === 'rejected' ? 'text-rose-500' : 'text-amber-500')}">${r.hr_status}</p>
                    </div>
                </div>
                ${r.supervisor_note ? `
                <div class="bg-amber-50 p-3 rounded-xl border border-amber-100">
                    <p class="text-[8px] font-black text-amber-600 uppercase tracking-widest">Your Note</p>
                    <p class="text-xs text-amber-700 mt-1 italic">"${escapeHtml(r.supervisor_note)}"</p>
                </div>
                ` : ''}
            </div>
        `,
        confirmButtonText: 'CLOSE',
        confirmButtonColor: '#6D3089'
    });
}
</script>

<?php renderFooter(); ?>