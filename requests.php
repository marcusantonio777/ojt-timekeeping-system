<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';
require_once __DIR__.'/components/requests_ui.php';
require_once 'includes/layouts/main_layout.php';

checkAccess('trainee');

$user_id = $_SESSION['user_id'];
$message = '';

$preload_date = isset($_GET['preload_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['preload_date']) ? $_GET['preload_date'] : '';
$preload_type = isset($_GET['preload_type']) ? trim($_GET['preload_type']) : '';
$preload_time_out = isset($_GET['preload_time_out']) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $_GET['preload_time_out']) ? $_GET['preload_time_out'] : '';
$preload_pending_ot_seconds = max(0, (int)($_GET['preload_pending_ot_seconds'] ?? 0));
$preload_narrative = isset($_GET['preload_narrative']) ? trim($_GET['preload_narrative']) : '';
$auto_open_request_modal = isset($_GET['open_request_modal']) || $preload_date !== '' || $preload_type !== '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: requests.php?error=csrf_failed");
        exit();
    }

    if ($_POST['action'] === 'submit_request') {
        $type = trim($_POST['type'] ?? '');
        $start_date = trim($_POST['start_date'] ?? '');
        $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : null;
        $start_time = !empty($_POST['start_time']) ? trim($_POST['start_time']) : null;
        $end_time = !empty($_POST['end_time']) ? trim($_POST['end_time']) : null;
        $reason = trim($_POST['reason'] ?? '');

        $storedFilename = null;
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("INSERT INTO requests (trainee_id, type, start_date, end_date, start_time, end_time, reason) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $type, $start_date, $end_date, $start_time, $end_time, $reason]);
            $request_id = (int)$pdo->lastInsertId();

            if (isset($_FILES['attachment']) && isset($_FILES['attachment']['error']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $maxBytes = 5 * 1024 * 1024;
                $size = (int)($_FILES['attachment']['size'] ?? 0);
                if ($size <= 0 || $size > $maxBytes) {
                    throw new Exception('Invalid attachment size');
                }

                $origName = (string)($_FILES['attachment']['name'] ?? '');
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                $allowedExt = ['pdf', 'jpg', 'jpeg', 'png'];
                if (!in_array($ext, $allowedExt, true)) {
                    throw new Exception('Invalid attachment type');
                }

                $tmp = $_FILES['attachment']['tmp_name'];
                $mime = mime_content_type($tmp) ?: 'application/octet-stream';
                $allowedMime = ['application/pdf', 'image/jpeg', 'image/png'];
                if (!in_array($mime, $allowedMime, true)) {
                    throw new Exception('Invalid attachment mime');
                }

                $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'requests';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $storedFilename = 'req_' . $request_id . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $dest = $uploadDir . DIRECTORY_SEPARATOR . $storedFilename;
                if (!move_uploaded_file($tmp, $dest)) {
                    throw new Exception('Failed to move attachment');
                }

                $stmt = $pdo->prepare("UPDATE requests SET attachment_path = ?, attachment_name = ?, attachment_mime = ?, attachment_size = ? WHERE id = ? AND trainee_id = ?");
                $stmt->execute([$storedFilename, $origName, $mime, $size, $request_id, $user_id]);
            }

            $pdo->commit();
        } catch (Exception $e) {
            $pdo->rollBack();
            if ($storedFilename) {
                $p = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'requests' . DIRECTORY_SEPARATOR . $storedFilename;
                if (is_file($p)) {
                    @unlink($p);
                }
            }
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=upload_failed");
            exit();
        }

        logAudit($pdo, $user_id, 'SUBMIT_REQUEST', "Submitted $type request for $start_date");
        try {
            $stmt = $pdo->prepare("SELECT first_name, last_name, department, email FROM trainees WHERE id = ? AND deleted_at IS NULL");
            $stmt->execute([$user_id]);
            $trainee = $stmt->fetch();
            if ($trainee) {
                $stmt = $pdo->prepare("SELECT email FROM trainees WHERE role = 'supervisor' AND department = ? AND deleted_at IS NULL AND email IS NOT NULL AND email <> ''");
                $stmt->execute([$trainee['department']]);
                $supervisorEmails = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $subject = "New {$type} request - {$trainee['first_name']} {$trainee['last_name']}";
                $body = "A new request has been submitted.\n\nTrainee: {$trainee['first_name']} {$trainee['last_name']}\nDepartment: {$trainee['department']}\nType: {$type}\nDate: {$start_date}" . ($end_date ? " to {$end_date}" : "") . "\n" . ($start_time ? "Time: {$start_time} - {$end_time}\n" : "") . "Reason: {$reason}\n";
                foreach ($supervisorEmails as $to) {
                    enqueueSystemEmail($to, $subject, $body);
                }
            }
        } catch (Exception $e) {
        }
        header("Location: " . $_SERVER['PHP_SELF'] . "?success=1");
        exit();
    }

    if ($_POST['action'] === 'delete_request') {
        $req_id = (int)($_POST['request_id'] ?? 0);

        try {
            $stmt = $pdo->prepare("SELECT id, type, supervisor_status, hr_status, attachment_path, start_date FROM requests WHERE id = ? AND trainee_id = ? AND deleted_at IS NULL AND archived_at IS NULL");
            $stmt->execute([$req_id, $user_id]);
            $req = $stmt->fetch();

            if (!$req) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?error=not_found");
                exit();
            }

            $can_delete = false;
            $sup_status = $req['supervisor_status'] ?? 'pending';
            if ($sup_status === 'pending') {
                $can_delete = true;
            } elseif ($sup_status === 'rejected') {
                $can_delete = true;
            } elseif ($sup_status === 'approved' && ($req['hr_status'] ?? 'pending') === 'rejected') {
                $can_delete = true;
            }

            if (!$can_delete) {
                header("Location: " . $_SERVER['PHP_SELF'] . "?error=cannot_delete");
                exit();
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE requests SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND trainee_id = ?");
            $stmt->execute([$req_id, $user_id]);

            if (!empty($req['attachment_path'])) {
                $attachDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'requests';
                $p = $attachDir . DIRECTORY_SEPARATOR . $req['attachment_path'];
                if (is_file($p)) {
                    @unlink($p);
                }
            }

            $pdo->commit();
            logAudit($pdo, $user_id, 'DELETE_REQUEST', "Deleted self-filed request ID $req_id (type: {$req['type']})");
            header("Location: " . $_SERVER['PHP_SELF'] . "?deleted=1");
            exit();
        } catch (Exception $e) {
            if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
            header("Location: " . $_SERVER['PHP_SELF'] . "?error=delete_failed");
            exit();
        }
    }
}

// Active = not yet archived (HR has not finalized approval)
$stmt = $pdo->prepare("SELECT * FROM requests WHERE trainee_id = ? AND deleted_at IS NULL AND archived_at IS NULL ORDER BY created_at DESC");
$stmt->execute([$user_id]);
$my_requests = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM requests WHERE trainee_id = ? AND deleted_at IS NULL AND archived_at IS NOT NULL ORDER BY archived_at DESC");
$stmt->execute([$user_id]);
$archived_requests = $stmt->fetchAll();
$request_stats = [
    'active' => count($my_requests),
    'waiting_supervisor' => 0,
    'waiting_hr' => 0,
    'archived' => count($archived_requests)
];

foreach ($my_requests as $request_row) {
    if (($request_row['supervisor_status'] ?? 'pending') === 'pending') {
        $request_stats['waiting_supervisor']++;
    } elseif (($request_row['supervisor_status'] ?? '') === 'approved' && ($request_row['hr_status'] ?? 'pending') === 'pending') {
        $request_stats['waiting_hr']++;
    }
}


renderHeader('My Requests');
renderSidebar('requests');
?>
<?php include 'includes/footer.php'; ?>
<div class="space-y-8">
    <div class="relative overflow-hidden rounded-[2.5rem] border border-slate-200 bg-white shadow-xl shadow-slate-900/5">
        <div class="absolute inset-0 bg-gradient-to-br from-[#6D3089] via-[#5B28A5] to-[#7C3AED]"></div>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(255,255,255,0.15),transparent_50%)]"></div>
        <div class="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-white/10 blur-3xl"></div>
        <div class="absolute -left-16 -bottom-16 h-64 w-64 rounded-full bg-amber-400/20 blur-3xl"></div>
        <div class="relative p-8 lg:p-10 text-white">
            <div class="flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between">
                <div class="max-w-2xl">
                    <p class="text-[11px] font-black uppercase tracking-[0.4em] text-white/60">Request Center</p>
                    <h1 class="mt-4 text-3xl lg:text-4xl font-black tracking-tight leading-tight">Submit, track, and follow every request in one place.</h1>
                    <p class="mt-4 text-sm leading-relaxed text-white/80 max-w-xl">File overtime, leave, or undertime requests with a cleaner workflow. Pending OT cards from your dashboard now open the filing modal automatically.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" onclick="openRequestModal()" class="inline-flex items-center gap-2.5 rounded-2xl bg-white px-6 py-3.5 text-[11px] font-black uppercase tracking-[0.22em] text-[#6D3089] shadow-2xl shadow-slate-950/20 transition-all hover:-translate-y-0.5 hover:bg-slate-50 active:translate-y-0">
                        <svg class="h-4.5 w-4.5" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>
                        Open new request
                    </button>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()" class="inline-flex items-center justify-center h-12 w-12 rounded-2xl border border-white/15 bg-white/5 text-white backdrop-blur transition-all hover:bg-white/10" aria-label="Print requests">
                            <svg class="h-4.5 w-4.5" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path></svg>
                        </button>
                        <button type="button" onclick="alert('Export feature coming soon')" class="inline-flex items-center justify-center h-12 w-12 rounded-2xl border border-white/15 bg-white/5 text-white backdrop-blur transition-all hover:bg-white/10" aria-label="Export requests">
                            <svg class="h-4.5 w-4.5" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M3 17V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path></svg>
                        </button>
                        <a href="#archive" class="inline-flex items-center justify-center h-12 w-12 rounded-2xl border border-white/15 bg-white/5 text-white backdrop-blur transition-all hover:bg-white/10" aria-label="View archive">
                            <svg class="h-4.5 w-4.5" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php echo requestFlashBanner(); ?>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <?php echo requestKpiTile('Active requests', $request_stats['active'], 'All requests still under workflow.', 'purple', 'doc'); ?>
        <?php echo requestKpiTile('Waiting supervisor', $request_stats['waiting_supervisor'], 'Needs department review.', 'amber', 'clock'); ?>
        <?php echo requestKpiTile('Waiting HR', $request_stats['waiting_hr'], 'Already endorsed by supervisor.', 'blue', 'shield'); ?>
        <?php echo requestKpiTile('Archived', $request_stats['archived'], 'Finalized records for reference.', 'emerald', 'check'); ?>
    </div>

    <div class="rounded-[2rem] border border-slate-200 bg-white p-5 lg:p-6 shadow-sm">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0">
                <p class="text-[10px] font-black uppercase tracking-[0.24em] text-slate-400">In progress</p>
                <h2 class="mt-2 text-xl font-black text-slate-900">Approval tracker</h2>
                <p class="mt-1 text-sm text-slate-500">Filter your request list by status or type for faster follow-up.</p>
            </div>
            <div class="w-full lg:w-auto">
                <div class="flex flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2" role="toolbar" aria-label="Request filters">
                        <div class="inline-flex items-center rounded-2xl border border-slate-200 bg-slate-50 p-1" role="group" aria-label="View toggle">
                            <button type="button" id="viewToggleTable" onclick="setRequestView('table')" class="inline-flex items-center justify-center h-9 w-9 rounded-xl text-[#6D3089] bg-white shadow-sm ring-1 ring-slate-200 transition-all" aria-pressed="true" title="Table view">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18M9 4v16M15 4v16"></path></svg>
                            </button>
                            <button type="button" id="viewToggleCard" onclick="setRequestView('card')" class="inline-flex items-center justify-center h-9 w-9 rounded-xl text-slate-400 hover:text-slate-600 transition-all" aria-pressed="false" title="Card view">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path></svg>
                            </button>
                        </div>

                        <div class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                            <label for="dateFromFilter" class="text-[10px] font-black uppercase tracking-widest text-slate-400">From</label>
                            <input id="dateFromFilter" type="date" class="bg-transparent text-xs font-bold text-slate-700 outline-none w-32" onchange="applyRequestFilters()">
                        </div>
                        <div class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-3 py-2">
                            <label for="dateToFilter" class="text-[10px] font-black uppercase tracking-widest text-slate-400">To</label>
                            <input id="dateToFilter" type="date" class="bg-transparent text-xs font-bold text-slate-700 outline-none w-32" onchange="applyRequestFilters()">
                        </div>

                        <div class="relative flex-1 min-w-[220px]">
                            <svg class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                            <input id="requestSearch" type="text" placeholder="Search request..." class="w-full rounded-2xl border border-slate-200 bg-slate-50 pl-11 pr-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10">
                        </div>

                        <select id="typeFilter" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10">
                            <option value="all">All types</option>
                            <option value="leave">Leave</option>
                            <option value="overtime">Overtime</option>
                            <option value="undertime">Undertime</option>
                        </select>
                        <select id="statusFilter" class="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10">
                            <option value="all">All stages</option>
                            <option value="pending_supervisor">Pending supervisor</option>
                            <option value="pending_hr">Pending HR</option>
                            <option value="approved">Fully approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="requestsTableView">
        <div class="bg-white rounded-[2rem] border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-gradient-to-r from-slate-50 to-slate-100/50 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-slate-200">
                        <th class="px-8 py-5">Type</th>
                        <th class="px-8 py-5">Date & Time</th>
                        <th class="px-8 py-5">Approval Flow</th>
                        <th class="px-8 py-5">Reason</th>
                        <th class="px-8 py-5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($my_requests)): ?>
                        <tr id="noActiveRequestsRow">
                            <td colspan="5" class="px-8 py-4">
                                <?php echo requestEmptyState('No active requests', 'Your pending approvals will appear here once you submit a request. Click "Open new request" to get started.', 'purple', '<button type="button" onclick="openRequestModal()" class="inline-flex items-center gap-2 rounded-2xl bg-[#6D3089] px-5 py-3 text-[11px] font-black uppercase tracking-[0.22em] text-white shadow-lg shadow-[#6D3089]/20 transition-all hover:-translate-y-0.5 hover:bg-[#5B28A5]"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>Open new request</button>'); ?>
                            </td>
                        </tr>
                    <?php endif; ?>
                    <tr id="filteredEmptyState" class="hidden">
                        <td colspan="5" class="px-8 py-4">
                            <?php echo requestEmptyState('No matching requests', 'Try adjusting your filters or search terms to find what you\'re looking for.', 'slate'); ?>
                        </td>
                    </tr>
                    <?php $zebra = 0; foreach ($my_requests as $r): ?>
                    <?php
                        $sup_status = $r['supervisor_status'] ?? 'pending';
                        $hr_status = $r['hr_status'] ?? 'pending';
                        $can_delete = false;
                        if ($sup_status === 'pending' || $sup_status === 'rejected') {
                            $can_delete = true;
                        } elseif ($sup_status === 'approved' && $hr_status === 'rejected') {
                            $can_delete = true;
                        }
                    ?>
                    <tr class="request-row transition-all group cursor-default <?php echo ($zebra % 2 === 0) ? 'bg-white' : 'bg-slate-50/30'; ?> hover:bg-[#6D3089]/[0.03] hover:shadow-md hover:-translate-y-[1px] hover:shadow-slate-900/5" style="z-index: <?php echo 100 - $zebra; ?>;" data-request-type="<?php echo htmlspecialchars($r['type']); ?>" data-supervisor-status="<?php echo htmlspecialchars($r['supervisor_status']); ?>" data-hr-status="<?php echo htmlspecialchars($r['hr_status']); ?>" data-start-date="<?php echo htmlspecialchars($r['start_date']); ?>" data-search="<?php echo htmlspecialchars(strtolower(($r['type'] ?? '') . ' ' . ($r['reason'] ?? '') . ' ' . ($r['start_date'] ?? '') . ' ' . ($r['end_date'] ?? ''))); ?>">
                        <td class="px-8 py-5 align-middle">
                            <?php echo requestTypeBadge($r['type'], 'md'); ?>
                        </td>
                        <td class="px-8 py-5 align-middle min-w-[180px]">
                            <div class="space-y-1">
                                <p class="text-xs font-bold text-slate-800 flex items-center gap-2">
                                    <svg class="h-3.5 w-3.5 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    <?php echo date('M d, Y', strtotime($r['start_date'])); ?><?php echo $r['end_date'] ? ' — '.date('M d, Y', strtotime($r['end_date'])) : ''; ?>
                                </p>
                                <?php if ($r['start_time']): ?>
                                <p class="text-[10px] text-slate-500 font-semibold flex items-center gap-2 pl-[22px]">
                                    <svg class="h-3 w-3 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    <?php echo date('h:i A', strtotime($r['start_time'])); ?> — <?php echo date('h:i A', strtotime($r['end_time'])); ?>
                                </p>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="px-8 py-5 align-middle min-w-[280px]">
                            <?php echo requestApprovalStepper($r['supervisor_status'], $r['hr_status'], true); ?>
                        </td>
                        <td class="px-8 py-5 align-middle max-w-[280px]">
                            <div class="flex items-start gap-2">
                                <p class="text-xs text-slate-600 leading-relaxed line-clamp-2"><?php echo htmlspecialchars($r['reason']); ?></p>
                            </div>
                        </td>
                        <td class="px-8 py-5 align-middle text-right">
                            <div class="inline-flex items-center gap-2">
                                <?php if (!empty($r['attachment_path'])): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-1 rounded-xl bg-blue-50 text-blue-600 border border-blue-100" title="Attachment uploaded">
                                    <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                </span>
                                <?php endif; ?>
                                <button type="button" onclick="viewRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-[#6D3089] bg-[#6D3089]/5 border border-[#6D3089]/10 transition-all hover:bg-[#6D3089] hover:text-white hover:border-[#6D3089]">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    View
                                </button>
                                <?php if ($can_delete): ?>
                                <button type="button" onclick="confirmDeleteRequest(<?php echo (int)$r['id']; ?>, <?php echo htmlspecialchars(json_encode($r['type'])); ?>)" class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest text-rose-600 bg-rose-50 border border-rose-100 transition-all hover:bg-rose-600 hover:text-white hover:border-rose-600">
                                    <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V3a2 2 0 012-2h4a2 2 0 012 2v4"></path></svg>
                                    Delete
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php $zebra++; endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>

    <div id="requestsCardView" class="hidden">
        <div id="cardEmptyState" class="hidden">
            <div class="rounded-[2rem] border border-slate-200 bg-white shadow-sm">
                <?php echo requestEmptyState('No matching requests', 'Try adjusting your filters or search terms to find what you\'re looking for.', 'slate'); ?>
            </div>
        </div>
        <?php if (empty($my_requests)): ?>
        <div class="rounded-[2rem] border border-slate-200 bg-white shadow-sm">
            <?php echo requestEmptyState('No active requests', 'Your pending approvals will appear here once you submit a request.', 'purple', '<button type="button" onclick="openRequestModal()" class="inline-flex items-center gap-2 rounded-2xl bg-[#6D3089] px-5 py-3 text-[11px] font-black uppercase tracking-[0.22em] text-white shadow-lg shadow-[#6D3089]/20 transition-all hover:-translate-y-0.5 hover:bg-[#5B28A5]"><svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"></path></svg>Open new request</button>'); ?>
        </div>
        <?php else: ?>
        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            <?php foreach ($my_requests as $r): ?>
            <?php
                $sup_status = $r['supervisor_status'] ?? 'pending';
                $hr_status = $r['hr_status'] ?? 'pending';
                $can_delete = false;
                if ($sup_status === 'pending' || $sup_status === 'rejected') {
                    $can_delete = true;
                } elseif ($sup_status === 'approved' && $hr_status === 'rejected') {
                    $can_delete = true;
                }
                $overallStatus = 'pending';
                if ($sup_status === 'rejected' || $hr_status === 'rejected') $overallStatus = 'rejected';
                elseif ($sup_status === 'approved' && $hr_status === 'approved') $overallStatus = 'approved';
                elseif ($sup_status === 'approved') $overallStatus = 'pending_hr';
                $statusLabels = ['pending'=>'Pending', 'pending_supervisor'=>'Pending Supervisor', 'pending_hr'=>'Pending HR', 'approved'=>'Approved', 'rejected'=>'Rejected'];
                $statusCls = ['pending'=>'bg-amber-50 text-amber-700 border-amber-200', 'pending_supervisor'=>'bg-amber-50 text-amber-700 border-amber-200', 'pending_hr'=>'bg-blue-50 text-blue-700 border-blue-200', 'approved'=>'bg-emerald-50 text-emerald-700 border-emerald-200', 'rejected'=>'bg-rose-50 text-rose-700 border-rose-200'];
            ?>
            <div class="request-card request-row group flex flex-col rounded-[1.75rem] border border-slate-200 bg-white shadow-sm transition-all hover:shadow-xl hover:shadow-slate-900/10 hover:-translate-y-1" data-request-type="<?php echo htmlspecialchars($r['type']); ?>" data-supervisor-status="<?php echo htmlspecialchars($r['supervisor_status']); ?>" data-hr-status="<?php echo htmlspecialchars($r['hr_status']); ?>" data-start-date="<?php echo htmlspecialchars($r['start_date']); ?>" data-search="<?php echo htmlspecialchars(strtolower(($r['type'] ?? '') . ' ' . ($r['reason'] ?? '') . ' ' . ($r['start_date'] ?? '') . ' ' . ($r['end_date'] ?? ''))); ?>">
                <div class="flex items-start justify-between gap-3 p-5 pb-3">
                    <?php echo requestTypeBadge($r['type'], 'md'); ?>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-[9px] font-black uppercase tracking-widest border <?php echo $statusCls[$overallStatus]; ?>">
                        <?php if ($overallStatus === 'approved'): ?>
                            <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path></svg>
                        <?php elseif ($overallStatus === 'rejected'): ?>
                            <svg class="h-2.5 w-2.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"></path></svg>
                        <?php else: ?>
                            <span class="h-2 w-2 rounded-full bg-current animate-pulse"></span>
                        <?php endif; ?>
                        <?php echo $statusLabels[$overallStatus]; ?>
                    </span>
                </div>

                <div class="px-5 pb-4 space-y-3">
                    <div class="flex items-start gap-2.5">
                        <div class="flex-shrink-0 inline-flex items-center justify-center h-8 w-8 rounded-xl bg-slate-100 text-slate-500">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-bold text-slate-800"><?php echo date('M d, Y', strtotime($r['start_date'])); ?><?php echo $r['end_date'] ? ' — '.date('M d, Y', strtotime($r['end_date'])) : ''; ?></p>
                            <?php if ($r['start_time']): ?>
                            <p class="text-[10px] text-slate-500 font-semibold mt-0.5 flex items-center gap-1">
                                <svg class="h-3 w-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php echo date('h:i A', strtotime($r['start_time'])); ?> — <?php echo date('h:i A', strtotime($r['end_time'])); ?>
                            </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php echo requestApprovalStepper($r['supervisor_status'], $r['hr_status'], true); ?>

                    <div class="rounded-2xl bg-slate-50/80 border border-slate-100 p-3">
                        <p class="text-[10px] font-black uppercase tracking-widest text-slate-400 mb-1.5">Reason</p>
                        <p class="text-xs text-slate-600 leading-relaxed line-clamp-3"><?php echo htmlspecialchars($r['reason']); ?></p>
                    </div>

                    <?php if (!empty($r['attachment_path'])): ?>
                    <div class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-blue-50 text-blue-700 border border-blue-100">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                        <span class="text-[10px] font-black uppercase tracking-widest">Attachment</span>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="mt-auto border-t border-slate-100 p-3 flex items-center gap-2 bg-slate-50/30 rounded-b-[1.75rem]">
                    <button type="button" onclick="viewRequestDetails(<?php echo htmlspecialchars(json_encode($r)); ?>)" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest text-slate-700 bg-white border border-slate-200 transition-all hover:bg-[#6D3089] hover:text-white hover:border-[#6D3089]">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                        View
                    </button>
                    <?php if ($can_delete): ?>
                    <button type="button" onclick="confirmDeleteRequest(<?php echo (int)$r['id']; ?>, <?php echo htmlspecialchars(json_encode($r['type'])); ?>)" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest text-rose-600 bg-white border border-slate-200 transition-all hover:bg-rose-600 hover:text-white hover:border-rose-600">
                        <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M8 7V3a2 2 0 012-2h4a2 2 0 012 2v4"></path></svg>
                        Delete
                    </button>
                    <?php else: ?>
                    <div class="flex-1"></div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($archived_requests)): ?>
    <div id="archive" class="mt-12 space-y-4">
        <div class="flex items-center gap-3">
            <div class="inline-flex items-center justify-center h-10 w-10 rounded-2xl bg-slate-100 text-slate-500">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"></path></svg>
            </div>
            <div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Archived Requests</p>
                <p class="text-sm text-slate-500 font-semibold">HR finalized — kept in database</p>
            </div>
        </div>
        <div class="bg-white rounded-[2rem] border border-slate-200 overflow-hidden shadow-sm">
            <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-slate-200">
                        <th class="px-8 py-4">Type</th>
                        <th class="px-8 py-4">Period</th>
                        <th class="px-8 py-4">Final Status</th>
                        <th class="px-8 py-4 text-right">Archived On</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php foreach ($archived_requests as $r): ?>
                    <tr class="hover:bg-slate-50/50 transition-all">
                        <td class="px-8 py-4">
                            <?php echo requestTypeBadge($r['type'], 'sm'); ?>
                        </td>
                        <td class="px-8 py-4">
                            <p class="text-xs font-bold text-slate-700"><?php echo date('M d, Y', strtotime($r['start_date'])); ?><?php echo $r['end_date'] ? ' — '.date('M d, Y', strtotime($r['end_date'])) : ''; ?></p>
                        </td>
                        <td class="px-8 py-4">
                            <?php echo requestStatusDot($r['hr_status']); ?>
                        </td>
                        <td class="px-8 py-4 text-right text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?php echo $r['archived_at'] ? date('M d, Y', strtotime($r['archived_at'])) : '—'; ?></td>
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
function setRequestView(mode) {
    const tableView = document.getElementById('requestsTableView');
    const cardView = document.getElementById('requestsCardView');
    const btnTable = document.getElementById('viewToggleTable');
    const btnCard = document.getElementById('viewToggleCard');
    if (!tableView || !cardView) return;
    if (mode === 'table') {
        tableView.classList.remove('hidden');
        cardView.classList.add('hidden');
        if (btnTable) {
            btnTable.classList.add('text-[#6D3089]', 'bg-white', 'shadow-sm', 'ring-1', 'ring-slate-200');
            btnTable.classList.remove('text-slate-400');
            btnTable.setAttribute('aria-pressed', 'true');
        }
        if (btnCard) {
            btnCard.classList.remove('text-[#6D3089]', 'bg-white', 'shadow-sm', 'ring-1', 'ring-slate-200');
            btnCard.classList.add('text-slate-400');
            btnCard.setAttribute('aria-pressed', 'false');
        }
    } else {
        tableView.classList.add('hidden');
        cardView.classList.remove('hidden');
        if (btnCard) {
            btnCard.classList.add('text-[#6D3089]', 'bg-white', 'shadow-sm', 'ring-1', 'ring-slate-200');
            btnCard.classList.remove('text-slate-400');
            btnCard.setAttribute('aria-pressed', 'true');
        }
        if (btnTable) {
            btnTable.classList.remove('text-[#6D3089]', 'bg-white', 'shadow-sm', 'ring-1', 'ring-slate-200');
            btnTable.classList.add('text-slate-400');
            btnTable.setAttribute('aria-pressed', 'false');
        }
    }
}

function normalizeTimeValue(timeValue) {
    if (!timeValue) return '';
    const parts = String(timeValue).split(':');
    return parts.length >= 2 ? `${parts[0].padStart(2, '0')}:${parts[1].padStart(2, '0')}` : '';
}

function subtractSecondsFromTime(timeValue, secondsToSubtract) {
    const normalized = normalizeTimeValue(timeValue);
    if (!normalized || !secondsToSubtract) return normalized;
    const [hours, minutes] = normalized.split(':').map(Number);
    const base = (hours * 3600) + (minutes * 60) - Number(secondsToSubtract);
    const wrapped = ((base % 86400) + 86400) % 86400;
    const resultHours = String(Math.floor(wrapped / 3600)).padStart(2, '0');
    const resultMinutes = String(Math.floor((wrapped % 3600) / 60)).padStart(2, '0');
    return `${resultHours}:${resultMinutes}`;
}

let wizState = {
    step: 1,
    type: 'leave',
    start_date: '',
    end_date: '',
    start_time: '',
    end_time: '',
    reason: '',
    attachName: 'No file'
};

function wizardStepIndicator(current, total) {
    const steps = ['Choose Type', 'Date & Reason', 'Review & Attach'];
    let html = '<div class="mb-6"><div class="flex items-center justify-between gap-2">';
    for (let i = 0; i < total; i++) {
        const active = (i + 1) === current;
        const done = (i + 1) < current;
        const cls = done ? 'bg-emerald-500 text-white border-emerald-500' : (active ? 'bg-white border-[#6D3089] text-[#6D3089] ring-4 ring-[#6D3089]/10' : 'bg-white text-slate-400 border-slate-200');
        const lineCls = i < total - 1 ? (done ? 'bg-emerald-400' : 'bg-slate-200') : '';
        html += '<div class="flex items-center gap-2 flex-1 min-w-0">';
        html += '<div class="flex-shrink-0">';
        html += '<div class="flex items-center justify-center h-8 w-8 rounded-full border-2 font-black text-[11px] ' + cls + '">';
        html += done ? '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>' : (i + 1);
        html += '</div>';
        html += '</div>';
        if (i < total - 1) {
            html += '<div class="flex-1 h-[2px] rounded-full ' + lineCls + '"></div>';
        }
        html += '</div>';
    }
    html += '</div><div class="flex gap-2 mt-2 text-center">';
    for (let i = 0; i < total; i++) {
        const active = (i + 1) === current;
        html += '<p class="flex-1 text-[9px] font-black uppercase tracking-widest ' + (active ? 'text-[#6D3089]' : 'text-slate-400') + '">' + steps[i] + '</p>';
    }
    html += '</div></div>';
    return html;
}

function renderWizardStep(step, defaults) {
    const html = {
        1: `
            <div class="space-y-4">
                <div class="text-center mb-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.24em] text-slate-400">Step 1 of 3</p>
                    <h3 class="mt-2 text-lg font-black text-slate-900">What type of request is this?</h3>
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <button type="button" data-wiz-type="leave" class="wiz-type-card text-left p-5 rounded-2xl border-2 transition-all hover:-translate-y-0.5 ${wizState.type === 'leave' ? 'border-[#6D3089] bg-[#6D3089]/5 ring-4 ring-[#6D3089]/10 shadow-lg shadow-[#6D3089]/10' : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md'}">
                        <div class="flex items-center justify-center h-14 w-14 rounded-2xl ${wizState.type === 'leave' ? 'bg-[#6D3089] text-white' : 'bg-amber-50 text-amber-600'} mb-4">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        </div>
                        <p class="text-sm font-black uppercase tracking-widest ${wizState.type === 'leave' ? 'text-[#6D3089]' : 'text-slate-800'}">Leave</p>
                        <p class="mt-1 text-[11px] leading-relaxed ${wizState.type === 'leave' ? 'text-[#6D3089]/70' : 'text-slate-500'}">Full or multiple-day absences. Use for sick, personal, or vacation days.</p>
                    </button>
                    <button type="button" data-wiz-type="overtime" class="wiz-type-card text-left p-5 rounded-2xl border-2 transition-all hover:-translate-y-0.5 ${wizState.type === 'overtime' ? 'border-[#6D3089] bg-[#6D3089]/5 ring-4 ring-[#6D3089]/10 shadow-lg shadow-[#6D3089]/10' : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md'}">
                        <div class="flex items-center justify-center h-14 w-14 rounded-2xl ${wizState.type === 'overtime' ? 'bg-[#6D3089] text-white' : 'bg-emerald-50 text-emerald-600'} mb-4">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <p class="text-sm font-black uppercase tracking-widest ${wizState.type === 'overtime' ? 'text-[#6D3089]' : 'text-slate-800'}">Overtime</p>
                        <p class="mt-1 text-[11px] leading-relaxed ${wizState.type === 'overtime' ? 'text-[#6D3089]/70' : 'text-slate-500'}">Hours worked beyond shift. Auto-filed from pending OT cards on your dashboard.</p>
                    </button>
                    <button type="button" data-wiz-type="undertime" class="wiz-type-card text-left p-5 rounded-2xl border-2 transition-all hover:-translate-y-0.5 ${wizState.type === 'undertime' ? 'border-[#6D3089] bg-[#6D3089]/5 ring-4 ring-[#6D3089]/10 shadow-lg shadow-[#6D3089]/10' : 'border-slate-200 bg-white hover:border-slate-300 hover:shadow-md'}">
                        <div class="flex items-center justify-center h-14 w-14 rounded-2xl ${wizState.type === 'undertime' ? 'bg-[#6D3089] text-white' : 'bg-rose-50 text-rose-600'} mb-4">
                            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                        </div>
                        <p class="text-sm font-black uppercase tracking-widest ${wizState.type === 'undertime' ? 'text-[#6D3089]' : 'text-slate-800'}">Undertime</p>
                        <p class="mt-1 text-[11px] leading-relaxed ${wizState.type === 'undertime' ? 'text-[#6D3089]/70' : 'text-slate-500'}">Early clock-out for personal matters on a regular duty day.</p>
                    </button>
                </div>
            </div>
        `,
        2: `
            <div class="space-y-4">
                <div class="text-center mb-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.24em] text-slate-400">Step 2 of 3</p>
                    <h3 class="mt-2 text-lg font-black text-slate-900">When and why is this needed?</h3>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Start Date *</label>
                        <input type="date" id="r_start_date" class="swal2-input w-full m-0 mt-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10" value="${wizState.start_date}">
                    </div>
                    <div id="end_date_container" class="${wizState.type === 'leave' ? '' : 'hidden'}">
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">End Date (Optional)</label>
                        <input type="date" id="r_end_date" class="swal2-input w-full m-0 mt-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10" value="${wizState.end_date}">
                    </div>
                </div>
                <div id="time_fields" class="${wizState.type === 'leave' ? 'hidden' : ''} grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Start Time *</label>
                        <input type="time" id="r_start_time" class="swal2-input w-full m-0 mt-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10" value="${wizState.start_time}">
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">End Time *</label>
                        <input type="time" id="r_end_time" class="swal2-input w-full m-0 mt-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10" value="${wizState.end_time}">
                    </div>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase tracking-widest text-slate-500">Reason / Justification *</label>
                    <textarea id="r_reason" rows="4" class="swal2-textarea w-full m-0 mt-1.5 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700 outline-none transition-all focus:border-[#6D3089] focus:bg-white focus:ring-4 focus:ring-[#6D3089]/10 resize-none" placeholder="Please provide a clear explanation for this request...">${escapeHtml(wizState.reason)}</textarea>
                </div>
            </div>
        `,
        3: `
            <div class="space-y-4">
                <div class="text-center mb-2">
                    <p class="text-[10px] font-black uppercase tracking-[0.24em] text-slate-400">Step 3 of 3</p>
                    <h3 class="mt-2 text-lg font-black text-slate-900">Review & submit</h3>
                    <p class="mt-1 text-xs text-slate-500">Double-check details below. Add an attachment if required.</p>
                </div>
                <div class="rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white overflow-hidden">
                    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                        <div>
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Request type</p>
                            <p class="text-sm font-black uppercase tracking-widest text-slate-800 mt-0.5">${wizState.type}</p>
                        </div>
                        <span class="inline-flex items-center px-3 py-1 rounded-full ${wizState.type === 'leave' ? 'bg-amber-50 text-amber-700 border border-amber-100' : (wizState.type === 'overtime' ? 'bg-emerald-50 text-emerald-700 border border-emerald-100' : 'bg-rose-50 text-rose-700 border border-rose-100')} text-[9px] font-black uppercase tracking-widest">
                            <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="${wizState.type === 'leave' ? 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' : 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'}"></path></svg>
                            ${wizState.type}
                        </span>
                    </div>
                    <div class="p-5 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Start date</p>
                                <p class="text-sm font-bold text-slate-800 mt-1">${wizState.start_date}</p>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">End date</p>
                                <p class="text-sm font-bold text-slate-800 mt-1">${wizState.end_date || '—'}</p>
                            </div>
                        </div>
                        ${wizState.type !== 'leave' ? `
                        <div class="grid grid-cols-2 gap-4 pt-3 border-t border-slate-100">
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Start time</p>
                                <p class="text-sm font-bold text-slate-800 mt-1">${wizState.start_time}</p>
                            </div>
                            <div>
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">End time</p>
                                <p class="text-sm font-bold text-slate-800 mt-1">${wizState.end_time}</p>
                            </div>
                        </div>
                        ` : ''}
                        <div class="pt-3 border-t border-slate-100">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Reason</p>
                            <div class="mt-2 p-3 rounded-2xl bg-white border border-slate-100">
                                <p class="text-xs text-slate-700 leading-relaxed whitespace-pre-wrap">${escapeHtml(wizState.reason)}</p>
                            </div>
                        </div>
                        <div class="pt-3 border-t border-slate-100">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-2">Attachment (Optional)</p>
                            <div class="flex items-center gap-2">
                                <button type="button" id="attach_btn" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-2xl bg-slate-100 text-slate-700 text-[10px] font-black uppercase tracking-widest hover:bg-slate-200 transition-all">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                    Choose File
                                </button>
                                <span id="attach_name" class="flex-1 min-w-0 text-[11px] font-bold text-slate-500 truncate">${wizState.attachName}</span>
                                <button type="button" id="attach_clear" class="px-3 py-2 rounded-xl bg-rose-50 text-rose-600 text-[10px] font-black uppercase tracking-widest hover:bg-rose-100 transition-all">Remove</button>
                            </div>
                            <p class="text-[9px] text-slate-400 font-bold mt-2 uppercase tracking-widest">PDF / JPG / PNG • Maximum 5 MB</p>
                        </div>
                    </div>
                </div>
            </div>
        `
    };
    return wizardStepIndicator(step, 3) + (html[step] || '');
}

function validateStep2() {
    const start_date = document.getElementById('r_start_date')?.value || '';
    const reason = document.getElementById('r_reason')?.value || '';
    if (!start_date) return 'Start date is required';
    if (!reason.trim()) return 'Please provide a reason';
    if (wizState.type !== 'leave') {
        const start_time = document.getElementById('r_start_time')?.value || '';
        const end_time = document.getElementById('r_end_time')?.value || '';
        if (!start_time || !end_time) return 'Both start and end time are required for ' + wizState.type;
    }
    return null;
}

function syncStep2ToState() {
    wizState.start_date = document.getElementById('r_start_date')?.value || wizState.start_date;
    wizState.end_date = document.getElementById('r_end_date')?.value || wizState.end_date;
    wizState.start_time = document.getElementById('r_start_time')?.value || wizState.start_time;
    wizState.end_time = document.getElementById('r_end_time')?.value || wizState.end_time;
    wizState.reason = document.getElementById('r_reason')?.value || wizState.reason;
    const attachName = document.getElementById('attach_name');
    if (attachName) wizState.attachName = attachName.textContent || wizState.attachName;
}

async function openRequestModal(defaults = {}) {
    const normalizedDefaults = {
        type: defaults.type || 'leave',
        start_date: defaults.start_date || '<?php echo date('Y-m-d'); ?>',
        end_date: defaults.end_date || '',
        start_time: defaults.start_time || '',
        end_time: defaults.end_time || '',
        reason: defaults.reason || '',
        pending_ot_seconds: Number(defaults.pending_ot_seconds || 0),
        attendance_narrative: defaults.attendance_narrative || ''
    };

    if (normalizedDefaults.type === 'overtime' && normalizedDefaults.pending_ot_seconds > 0 && normalizedDefaults.end_time && !normalizedDefaults.start_time) {
        normalizedDefaults.start_time = subtractSecondsFromTime(normalizedDefaults.end_time, normalizedDefaults.pending_ot_seconds);
    }

    if (normalizedDefaults.type === 'overtime' && !normalizedDefaults.reason) {
        normalizedDefaults.reason = normalizedDefaults.attendance_narrative
            ? `Pending OT filing based on recorded attendance. Narrative: ${normalizedDefaults.attendance_narrative}`
            : 'Pending OT filing based on recorded attendance.';
    }

    wizState = {
        step: 1,
        type: normalizedDefaults.type,
        start_date: normalizedDefaults.start_date,
        end_date: normalizedDefaults.end_date,
        start_time: normalizeTimeValue(normalizedDefaults.start_time),
        end_time: normalizeTimeValue(normalizedDefaults.end_time),
        reason: normalizedDefaults.reason,
        attachName: 'No file'
    };

    const modalTitle = wizState.type === 'overtime' ? 'Submit Overtime Request' : 'Submit New Request';

    const swalInstance = await Swal.fire({
        title: modalTitle,
        html: '<div id="wiz_root" class="text-left">' + renderWizardStep(1) + '</div>',
        showCancelButton: true,
        showConfirmButton: true,
        confirmButtonText: 'Next',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#6D3089',
        allowOutsideClick: false,
        customClass: {
            popup: 'rounded-[2rem] max-w-2xl',
            title: 'text-xl font-black tracking-tight text-slate-900 text-left px-8 pt-7 pb-0',
            htmlContainer: 'px-8 pb-4 pt-5',
            actions: 'px-8 pb-7 pt-2 items-center gap-3 sticky bottom-0 bg-white/90 backdrop-blur border-t border-slate-100 mt-2',
            confirmButton: 'order-3 px-6 py-3 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] shadow-lg shadow-[#6D3089]/20',
            cancelButton: 'order-1 px-5 py-3 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] border border-slate-200 text-slate-600 bg-white hover:bg-slate-50 shadow-none'
        },
        didOpen: () => {
            const fileInput = document.getElementById('request_attachment_input');

            function bindStep1() {
                const cards = document.querySelectorAll('.wiz-type-card');
                cards.forEach((card) => {
                    card.addEventListener('click', () => {
                        const t = card.getAttribute('data-wiz-type');
                        wizState.type = t;
                        const root = document.getElementById('wiz_root');
                        if (root) root.innerHTML = renderWizardStep(1);
                        bindStep1();
                        updateSwalButtons(1);
                    });
                });
            }

            function bindStep2() {
                syncStep2ToState();
                ['r_start_date', 'r_end_date', 'r_start_time', 'r_end_time'].forEach((id) => {
                    const el = document.getElementById(id);
                    if (el) el.addEventListener('change', syncStep2ToState);
                    if (el) el.addEventListener('input', syncStep2ToState);
                });
                const reasonEl = document.getElementById('r_reason');
                if (reasonEl) {
                    reasonEl.addEventListener('input', syncStep2ToState);
                }
            }

            function bindStep3() {
                const attachBtn = document.getElementById('attach_btn');
                const attachClear = document.getElementById('attach_clear');
                const attachName = document.getElementById('attach_name');
                if (attachBtn && fileInput) {
                    attachBtn.addEventListener('click', () => fileInput.click());
                }
                if (attachClear && fileInput) {
                    attachClear.addEventListener('click', () => {
                        fileInput.value = '';
                        if (attachName) attachName.textContent = 'No file';
                        wizState.attachName = 'No file';
                    });
                }
                if (fileInput) {
                    fileInput.addEventListener('change', () => {
                        const f = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
                        if (attachName) {
                            attachName.textContent = f ? f.name : 'No file';
                            wizState.attachName = f ? f.name : 'No file';
                        }
                    });
                }
            }

            function updateSwalButtons(step) {
                const backBtn = document.querySelector('#wiz_back_btn') || (() => {
                    const actions = Swal.getActions();
                    if (!actions) return null;
                    const b = document.createElement('button');
                    b.id = 'wiz_back_btn';
                    b.type = 'button';
                    b.className = 'swal2-cancel swal2-styled order-2 px-5 py-3 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] border border-[#6D3089]/20 text-[#6D3089] bg-[#6D3089]/5 hover:bg-[#6D3089]/10 shadow-none transition-all';
                    b.textContent = 'Back';
                    b.addEventListener('click', () => {
                        if (wizState.step === 2) syncStep2ToState();
                        wizState.step = Math.max(1, wizState.step - 1);
                        const root = document.getElementById('wiz_root');
                        if (root) root.innerHTML = renderWizardStep(wizState.step);
                        if (wizState.step === 1) bindStep1();
                        if (wizState.step === 2) bindStep2();
                        if (wizState.step === 3) bindStep3();
                        updateSwalButtons(wizState.step);
                    });
                    actions.appendChild(b);
                    return b;
                })();
                if (step === 1) {
                    backBtn.style.display = 'none';
                    Swal.getConfirmButton().textContent = 'Next';
                } else {
                    backBtn.style.display = '';
                    if (step === 2) {
                        Swal.getConfirmButton().textContent = 'Next';
                    } else if (step === 3) {
                        Swal.getConfirmButton().textContent = 'Submit Request';
                    }
                }
            }

            bindStep1();
            updateSwalButtons(1);

            Swal.getConfirmButton().addEventListener('click', async () => {
                if (wizState.step === 1) {
                    wizState.step = 2;
                    const root = document.getElementById('wiz_root');
                    if (root) root.innerHTML = renderWizardStep(2);
                    bindStep2();
                    updateSwalButtons(2);
                    Swal.enableButtons();
                } else if (wizState.step === 2) {
                    const err = validateStep2();
                    if (err) {
                        Swal.showValidationMessage(err);
                        return;
                    }
                    syncStep2ToState();
                    wizState.step = 3;
                    const root = document.getElementById('wiz_root');
                    if (root) root.innerHTML = renderWizardStep(3);
                    bindStep3();
                    updateSwalButtons(3);
                    Swal.enableButtons();
                } else if (wizState.step === 3) {
                    syncStep2ToState();
                    if (!wizState.start_date || !wizState.reason) {
                        Swal.showValidationMessage('Missing required fields');
                        return;
                    }
                    const form = document.getElementById('request_submit_form');
                    form.querySelector('input[name="type"]').value = wizState.type;
                    form.querySelector('input[name="start_date"]').value = wizState.start_date;
                    form.querySelector('input[name="end_date"]').value = wizState.end_date;
                    form.querySelector('input[name="start_time"]').value = wizState.start_time;
                    form.querySelector('input[name="end_time"]').value = wizState.end_time;
                    form.querySelector('input[name="reason"]').value = wizState.reason;
                    Swal.close();
                    form.submit();
                }
            });
        },
        preConfirm: () => { return new Promise(() => {}); }
    });
}

function toggleTimeFields(type) {
    const timeFields = document.getElementById('time_fields');
    const endDateContainer = document.getElementById('end_date_container');
    if (type === 'leave') {
        if (timeFields) timeFields.classList.add('hidden');
        if (endDateContainer) endDateContainer.classList.remove('hidden');
    } else {
        if (timeFields) timeFields.classList.remove('hidden');
        if (endDateContainer) endDateContainer.classList.add('hidden');
    }
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function typeBadgeInline(type) {
    const v = {
        leave: ['bg-amber-50 text-amber-700 border border-amber-200', 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z', 'Leave'],
        overtime: ['bg-emerald-50 text-emerald-700 border border-emerald-200', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'Overtime'],
        undertime: ['bg-rose-50 text-rose-700 border border-rose-200', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z', 'Undertime']
    };
    const c = v[type] || v['leave'];
    return '<span class="inline-flex items-center gap-1.5 px-3.5 py-1.5 rounded-2xl text-[10px] font-black uppercase tracking-widest ' + c[0] + '"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' + c[1] + '"></path></svg>' + c[2] + '</span>';
}

function viewRequestDetails(r) {
    function statusPill(status, label) {
        const map = {
            pending: 'bg-amber-50 text-amber-700 border border-amber-200',
            approved: 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            rejected: 'bg-rose-50 text-rose-700 border border-rose-200'
        };
        const cls = map[status] || map['pending'];
        const lab = label || (status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Pending');
        return '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest ' + cls + '">' +
            '<span class="h-1.5 w-1.5 rounded-full bg-current"></span>' + lab + '</span>';
    }
    function tlState(date) { return date && date !== '' ? 'done' : 'active'; }
    function tlItem(idx, title, sub, date, state) {
        const cls = state === 'done' ? 'bg-emerald-500 ring-emerald-100 text-white' :
                    state === 'rejected' ? 'bg-rose-500 ring-rose-100 text-white' :
                    'bg-white ring-[#6D3089]/20 border-[#6D3089] text-[#6D3089] border-2';
        const icon = state === 'done'
            ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>'
            : (state === 'rejected'
                ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>'
                : '<span class="text-[10px] font-black">' + (idx) + '</span>');
        const ds = (date && date !== '') ? new Date(date).toLocaleString('en-US', { month:'short', day:'numeric', year:'numeric', hour:'numeric', minute:'2-digit' }) : '';
        return '<li class="ms-6"><span class="absolute -start-[13px] inline-flex items-center justify-center rounded-full h-7 w-7 ring-4 ' + cls + '">' + icon + '</span>' +
            '<div class="rounded-2xl border border-slate-100 bg-slate-50/50 p-3.5"><div class="flex items-start justify-between gap-3"><div class="min-w-0">' +
            '<p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-800">' + escapeHtml(title) + '</p>' +
            '<p class="mt-1 text-[11px] text-slate-500 font-semibold leading-snug">' + escapeHtml(sub || '') + '</p>' +
            '</div>' + (ds !== '' ? '<p class="flex-shrink-0 text-[9px] font-bold uppercase tracking-widest text-slate-400 whitespace-nowrap mt-0.5">' + ds + '</p>' : '') +
            '</div></div></li>';
    }
    const stages = [];
    stages.push([tlItem(1, 'Request Filed', 'You submitted the request form', r.created_at || '', 'done')]);
    const sp = r.supervisor_status || 'pending';
    if (sp === 'approved' || r.supervisor_approved_at) {
        stages.push([tlItem(2, 'Supervisor Approved', r.supervisor_note || 'Department reviewed', r.supervisor_approved_at || '', 'done')]);
    } else if (sp === 'rejected') {
        stages.push([tlItem(2, 'Supervisor Rejected', r.supervisor_note || 'Department returned', r.updated_at || '', 'rejected')]);
    } else {
        stages.push([tlItem(2, 'Pending Supervisor Review', r.supervisor_note || 'Awaiting department action', '', 'active')]);
    }
    const hp = r.hr_status || 'pending';
    if (r.archived_at && hp === 'approved') {
        stages.push([tlItem(3, 'HR Approved', r.hr_note || 'HR finalized', r.archived_at || '', 'done')]);
    } else if (hp === 'rejected') {
        stages.push([tlItem(3, 'HR Rejected', r.hr_note || 'HR returned', r.updated_at || '', 'rejected')]);
    } else if (sp === 'approved' && hp === 'pending') {
        stages.push([tlItem(3, 'Pending HR Final Review', r.hr_note || 'Endorsed by supervisor; HR queue', '', 'active')]);
    }

    const html = `
        <div class="text-left">
            <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
                <div class="lg:col-span-3 space-y-5">
                    <div class="flex items-center justify-between gap-3">
                        ${typeBadgeInline(r.type)}
                        <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">ID #${r.id}</p>
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-gradient-to-br from-slate-50 to-white overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Request summary</p>
                            ${statusPill((r.supervisor_status === 'approved' && r.hr_status === 'approved') ? 'approved' : ((r.supervisor_status === 'rejected' || r.hr_status === 'rejected') ? 'rejected' : 'pending'),
                                (r.supervisor_status === 'approved' && r.hr_status === 'approved') ? 'Fully Approved'
                                : ((r.supervisor_status === 'rejected' || r.hr_status === 'rejected') ? 'Rejected'
                                : (r.supervisor_status === 'approved' ? 'Pending HR' : 'Pending Supervisor')))}
                        </div>
                        <div class="p-5 space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div class="rounded-2xl bg-white border border-slate-100 p-3.5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Start Date</p>
                                    <p class="text-sm font-bold text-slate-800 mt-1">${r.start_date ? new Date(r.start_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—'}</p>
                                </div>
                                <div class="rounded-2xl bg-white border border-slate-100 p-3.5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">End Date</p>
                                    <p class="text-sm font-bold text-slate-800 mt-1">${r.end_date ? new Date(r.end_date).toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) : '—'}</p>
                                </div>
                            </div>
                            ${r.start_time ? `
                            <div class="grid grid-cols-2 gap-4 pt-1">
                                <div class="rounded-2xl bg-white border border-slate-100 p-3.5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Start Time</p>
                                    <p class="text-sm font-bold text-slate-800 mt-1">${r.start_time}</p>
                                </div>
                                <div class="rounded-2xl bg-white border border-slate-100 p-3.5">
                                    <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">End Time</p>
                                    <p class="text-sm font-bold text-slate-800 mt-1">${r.end_time || '—'}</p>
                                </div>
                            </div>
                            ` : ''}
                            <div class="pt-2 border-t border-slate-100">
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-2">Reason / Justification</p>
                                <div class="rounded-2xl bg-white border border-slate-100 p-4">
                                    <p class="text-xs text-slate-700 leading-relaxed whitespace-pre-wrap">${escapeHtml(r.reason || '')}</p>
                                </div>
                            </div>
                            ${r.attachment_path ? `
                            <div class="pt-2 border-t border-slate-100">
                                <p class="text-[9px] font-black uppercase tracking-widest text-slate-400 mb-2">Attachment</p>
                                <a href="serve_image.php?request_id=${r.id}" target="_blank" class="inline-flex items-center gap-2 px-4 py-3 rounded-2xl bg-blue-50 text-blue-700 border border-blue-100 text-[10px] font-black uppercase tracking-widest hover:bg-blue-600 hover:text-white hover:border-blue-600 transition-all">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                                    ${escapeHtml(r.attachment_name || 'Download attachment')}
                                </a>
                            </div>
                            ` : ''}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">Supervisor Review</p>
                            <div class="mt-2">${statusPill(r.supervisor_status || 'pending')}</div>
                            ${r.supervisor_note ? '<p class="mt-2 text-[11px] text-slate-600 italic leading-relaxed bg-amber-50 border border-amber-100 rounded-xl p-2.5">"'+escapeHtml(r.supervisor_note)+'"</p>' : ''}
                        </div>
                        <div class="rounded-2xl border border-slate-200 bg-white p-4">
                            <p class="text-[9px] font-black uppercase tracking-widest text-slate-400">HR Final Review</p>
                            <div class="mt-2">${statusPill(r.hr_status || 'pending')}</div>
                            ${r.hr_note ? '<p class="mt-2 text-[11px] text-slate-600 italic leading-relaxed bg-blue-50 border border-blue-100 rounded-xl p-2.5">"'+escapeHtml(r.hr_note)+'"</p>' : ''}
                        </div>
                    </div>
                </div>

                <div class="lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-5 sticky top-4">
                        <div class="flex items-center justify-between mb-4">
                            <p class="text-[10px] font-black uppercase tracking-[0.22em] text-slate-400">Approval Timeline</p>
                            <svg class="w-4 h-4 text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        </div>
                        <ol class="relative border-s-slate-100 border-s ml-1 space-y-5">
                            ${stages.join('')}
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    `;

    Swal.fire({
        title: 'Request Details',
        html: html,
        confirmButtonText: 'Close',
        confirmButtonColor: '#6D3089',
        width: '90%',
        customClass: {
            popup: 'rounded-[2rem] max-w-5xl',
            title: 'text-xl font-black tracking-tight text-slate-900 text-left px-8 pt-7 pb-0',
            htmlContainer: 'px-8 pb-4 pt-5',
            actions: 'px-8 pb-7 pt-2 sticky bottom-0 bg-white/90 backdrop-blur border-t border-slate-100 mt-2',
            confirmButton: 'px-6 py-3 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] shadow-lg shadow-[#6D3089]/20'
        }
    });
}

function getStageValue(row) {
    const supervisorStatus = row.dataset.supervisorStatus;
    const hrStatus = row.dataset.hrStatus;
    if (supervisorStatus === 'rejected' || hrStatus === 'rejected') return 'rejected';
    if (supervisorStatus === 'approved' && hrStatus === 'approved') return 'approved';
    if (supervisorStatus === 'approved' && hrStatus === 'pending') return 'pending_hr';
    return 'pending_supervisor';
}

function applyRequestFilters() {
    const searchTerm = (document.getElementById('requestSearch')?.value || '').trim().toLowerCase();
    const typeFilter = document.getElementById('typeFilter')?.value || 'all';
    const statusFilter = document.getElementById('statusFilter')?.value || 'all';
    const dateFrom = document.getElementById('dateFromFilter')?.value || '';
    const dateTo = document.getElementById('dateToFilter')?.value || '';
    const rows = Array.from(document.querySelectorAll('.request-row'));
    let visibleCount = 0;

    rows.forEach((row) => {
        const matchesSearch = !searchTerm || (row.dataset.search || '').includes(searchTerm);
        const matchesType = typeFilter === 'all' || row.dataset.requestType === typeFilter;
        const matchesStatus = statusFilter === 'all' || getStageValue(row) === statusFilter;
        let matchesDate = true;
        const rowDate = row.dataset.startDate;
        if (rowDate) {
            if (dateFrom && rowDate < dateFrom) matchesDate = false;
            if (dateTo && rowDate > dateTo) matchesDate = false;
        }
        const show = matchesSearch && matchesType && matchesStatus && matchesDate;
        row.classList.toggle('hidden', !show);
        if (show) visibleCount += 1;
    });

    const filteredEmpty = document.getElementById('filteredEmptyState');
    const noActive = document.getElementById('noActiveRequestsRow');
    const cardEmpty = document.getElementById('cardEmptyState');
    if (filteredEmpty) filteredEmpty.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
    if (noActive && rows.length > 0) noActive.classList.add('hidden');
    if (cardEmpty) cardEmpty.classList.toggle('hidden', visibleCount !== 0 || rows.length === 0);
}

document.addEventListener('DOMContentLoaded', () => {
    ['requestSearch', 'typeFilter', 'statusFilter', 'dateFromFilter', 'dateToFilter'].forEach((id) => {
        const element = document.getElementById(id);
        if (element) {
            element.addEventListener('input', applyRequestFilters);
            element.addEventListener('change', applyRequestFilters);
        }
    });

    applyRequestFilters();

    const shouldAutoOpen = <?php echo $auto_open_request_modal ? 'true' : 'false'; ?>;
    if (shouldAutoOpen) {
        openRequestModal({
            type: <?php echo json_encode($preload_type !== '' ? $preload_type : 'overtime'); ?>,
            start_date: <?php echo json_encode($preload_date !== '' ? $preload_date : date('Y-m-d')); ?>,
            end_time: <?php echo json_encode($preload_time_out); ?>,
            pending_ot_seconds: <?php echo (int)$preload_pending_ot_seconds; ?>,
            attendance_narrative: <?php echo json_encode($preload_narrative); ?>
        });
    }
});

function confirmDeleteRequest(requestId, requestType) {
    if (!requestId) return;
    const capType = (requestType || 'request').charAt(0).toUpperCase() + (requestType || 'request').slice(1);
    const extraNote = requestType === 'overtime' ? '\n\nIf you delete this OT request, the pending OT card will reappear on your dashboard so you can re-file correctly.' : '';
    const ok = confirm(`Delete this ${capType} request?\n\nThis cannot be undone.${extraNote}`);
    if (!ok) return;
    const form = document.getElementById('request_delete_form');
    if (!form) return;
    form.querySelector('[name="request_id"]').value = String(parseInt(requestId, 10) || 0);
    form.submit();
}
</script>

<form id="request_submit_form" method="POST" enctype="multipart/form-data" class="hidden">
    <input type="hidden" name="action" value="submit_request">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="type" value="">
    <input type="hidden" name="start_date" value="">
    <input type="hidden" name="end_date" value="">
    <input type="hidden" name="start_time" value="">
    <input type="hidden" name="end_time" value="">
    <input type="hidden" name="reason" value="">
    <input type="file" id="request_attachment_input" name="attachment" class="hidden" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png">
</form>

<form id="request_delete_form" method="POST" class="hidden">
    <input type="hidden" name="action" value="delete_request">
    <input type="hidden" name="csrf_token" value="<?php echo generateCsrfToken(); ?>">
    <input type="hidden" name="request_id" value="0">
</form>

<?php renderFooter(); ?>
