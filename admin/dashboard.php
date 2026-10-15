<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('admin, superadmin');

$role = $_SESSION['role'];
$csrf_token = generateCsrfToken();

if (isset($_GET['api']) && $_GET['api'] === 'widgets') {
    header('Content-Type: application/json');
    $total_ojts = (int)($pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $active_now = (int)($pdo->query("SELECT COUNT(*) FROM attendance a JOIN trainees t ON a.trainee_id = t.id WHERE a.date = CURDATE() AND a.time_out IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL")->fetchColumn() ?: 0);
    $pending_apps_count = (int)($pdo->query("SELECT COUNT(*) FROM trainees WHERE status = 'pending' AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $pending_requests_count = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn() ?: 0);
    $pending_supervisor_count = (int)($pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn() ?: 0);
    $timed_in_today = (int)($pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $still_active = (int)($pdo->query("SELECT COUNT(*) FROM attendance WHERE date = CURDATE() AND time_out IS NULL AND deleted_at IS NULL")->fetchColumn() ?: 0);
    $pending_by_dept = $pdo->query("
        SELECT t.department, COUNT(r.id) as count 
        FROM requests r
        JOIN trainees t ON r.trainee_id = t.id 
        WHERE r.supervisor_status = 'approved' 
        AND r.hr_status = 'pending' 
        AND r.deleted_at IS NULL 
        AND t.deleted_at IS NULL
        GROUP BY t.department
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'metrics' => [
            'total_ojts' => $total_ojts,
            'active_now' => $active_now,
            'pending_applications' => $pending_apps_count,
            'pending_requests' => $pending_requests_count,
            'pending_supervisor' => $pending_supervisor_count,
            'timed_in_today' => $timed_in_today,
            'still_active' => $still_active
        ],
        'pending_by_dept' => $pending_by_dept
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        die("CSRF Token Validation Failed");
    }

    $action = $_POST['action'];

    if ($action === 'broadcast') {
        $title = trim($_POST['title']);
        $msg = trim($_POST['message']);
        $target = $_POST['target'] ?? 'all';
        $image_path = handleUploadedImage($_FILES['image'] ?? null, __DIR__ . '/../uploads/announcements', 'ann_');

        $stmt = $pdo->prepare("INSERT INTO system_notifications (title, message, role_target, created_by, image_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $msg, $target, $_SESSION['user_id'], $image_path]);
        logAudit($pdo, $_SESSION['user_id'], 'BROADCAST', "HR Admin sent broadcast to $target with image: " . ($image_path ?: 'none'));
        header("Location: dashboard.php?status=broadcast_sent");
        exit();
    }
}

$csrf_token = generateCsrfToken();
$total_ojts = $pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL")->fetchColumn();
$active_now = $pdo->query("SELECT COUNT(*) FROM attendance a JOIN trainees t ON a.trainee_id = t.id WHERE a.date = CURDATE() AND a.time_out IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL")->fetchColumn();
$pending_apps_count = $pdo->query("SELECT COUNT(*) FROM trainees WHERE status = 'pending' AND deleted_at IS NULL")->fetchColumn();

// Get pending approvals count grouped by department
$pending_by_dept = $pdo->query("
    SELECT t.department, COUNT(r.id) as count 
    FROM requests r
    JOIN trainees t ON r.trainee_id = t.id 
    WHERE r.supervisor_status = 'approved' 
    AND r.hr_status = 'pending' 
    AND r.deleted_at IS NULL 
    AND t.deleted_at IS NULL
    GROUP BY t.department
")->fetchAll(PDO::FETCH_ASSOC);
// Get today's attendance total timed-in count
$timed_in_today = $pdo->query("
    SELECT COUNT(*) 
    FROM attendance 
    WHERE date = CURDATE() AND deleted_at IS NULL
")->fetchColumn() ?: 0;

// Get how many are still active (timed in but not timed out)
$still_active = $pdo->query("
    SELECT COUNT(*) 
    FROM attendance 
    WHERE date = CURDATE() AND time_out IS NULL AND deleted_at IS NULL
")->fetchColumn() ?: 0;

$stmt = $pdo->query("SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) FROM attendance a JOIN trainees t ON a.trainee_id = t.id WHERE a.time_out IS NOT NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL");
$total_seconds_all = $stmt->fetchColumn() ?: 0;
$total_init_hours = $pdo->query("SELECT SUM(initial_hours) FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL")->fetchColumn() ?: 0;
$total_hours_all = floor(($total_seconds_all / 3600) + $total_init_hours);

// 1.5 PENDING REQUESTS (FOR HR)
$pending_requests_count = $pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'approved' AND hr_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn();
$pending_supervisor_requests_count = $pdo->query("SELECT COUNT(*) FROM requests WHERE supervisor_status = 'pending' AND deleted_at IS NULL AND archived_at IS NULL")->fetchColumn();

// 2. ACTIONABLE LISTS (LIMIT TO 10)
// Pending Approval OJTs
$pending_ojts = $pdo->query("SELECT * FROM trainees WHERE status = 'pending' AND role = 'trainee' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 10")->fetchAll();

// Active Now OJTs (Recent 10)
$active_ojts = $pdo->query("
    SELECT a.*, t.first_name, t.last_name, t.department 
    FROM attendance a 
    JOIN trainees t ON a.trainee_id = t.id 
    WHERE a.date = CURDATE() AND a.time_out IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL 
    ORDER BY a.time_in DESC LIMIT 10
")->fetchAll();

$dept_stats = $pdo->query("SELECT department, COUNT(*) as count FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL GROUP BY department")->fetchAll();

// --- BOTTLENECK TRACKING ---
$bottlenecks = $pdo->query("
    SELECT r.*, t.first_name, t.last_name, t.department
    FROM requests r
    JOIN trainees t ON r.trainee_id = t.id
    WHERE r.supervisor_status = 'pending' 
    AND r.created_at < DATE_SUB(NOW(), INTERVAL 48 HOUR)
    AND r.deleted_at IS NULL
    ORDER BY r.created_at ASC
")->fetchAll();

renderHeader('HR Command Center');
renderSidebar('dashboard');
?>
<?php include '../includes/footer.php'; ?>
<div class="space-y-8">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-slate-900">Intelligence Feed</h1>
            <p class="text-sm text-slate-500 mt-1">Real-time oversight of hotel trainee operations.</p>
        </div>
        <div class="flex gap-3">
            <a href="/quest_ojt_system/index.php" target="_blank" class="px-5 py-2.5 bg-white text-slate-700 border border-slate-200 rounded-xl text-sm font-semibold hover:bg-slate-50 transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                Open Kiosk
            </a>
            <a href="trainees.php" class="px-5 py-2.5 bg-[#6D3089] text-white rounded-xl text-sm font-semibold hover:bg-[#5a2771] transition-all flex items-center gap-2 shadow-lg shadow-purple-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                Manage Directory
            </a>
            <button onclick="openBroadcastModal()" class="px-5 py-2.5 bg-slate-900 text-white rounded-xl text-sm font-semibold hover:bg-slate-800 transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                Broadcast
            </button>
        </div>
    </div>

 <!-- Stats Grid -->
<!-- Bottleneck Alert (Bento Box Style) -->
        <?php if (!empty($bottlenecks)): ?>
        <div class="col-span-full bg-rose-50 border border-rose-100 rounded-[2rem] p-8 mb-8">
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-2xl bg-rose-500 text-white flex items-center justify-center shadow-lg shadow-rose-500/20">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-black text-rose-900 uppercase tracking-widest">Supervisor Bottlenecks</h3>
                        <p class="text-[10px] font-bold text-rose-600 uppercase tracking-widest">Requests stuck for >48 hours</p>
                    </div>
                </div>
                <span class="px-4 py-2 bg-rose-500 text-white text-[10px] font-black rounded-xl uppercase tracking-widest"><?php echo count($bottlenecks); ?> Delayed</span>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php foreach (array_slice($bottlenecks, 0, 3) as $b): ?>
                <div class="bg-white/60 rounded-2xl p-4 border border-rose-200/50">
                    <div class="flex justify-between items-start mb-2">
                        <p class="text-xs font-black text-slate-800 uppercase"><?php echo e($b['first_name'] . ' ' . $b['last_name']); ?></p>
                        <span class="text-[8px] font-black text-rose-500 uppercase"><?php echo floor((time() - strtotime($b['created_at'])) / 3600); ?>h LATE</span>
                    </div>
                    <p class="text-[10px] font-bold text-slate-500 uppercase"><?php echo e($b['department']); ?> • <?php echo e($b['type']); ?></p>
                </div>
                <?php endforeach; ?>
                <?php if (count($bottlenecks) > 3): ?>
                <a href="requests.php?filter=delayed" class="flex items-center justify-center rounded-2xl border-2 border-dashed border-rose-200 text-rose-500 text-[10px] font-black uppercase hover:bg-rose-100 transition-all">
                    + <?php echo count($bottlenecks) - 3; ?> More Bottlenecks
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
    <!-- Total OJTs -->
    <div class="card p-6 border-l-4 border-l-[#6D3089]">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Total OJTs</p>
        <h3 class="text-3xl font-bold text-slate-900"><?php echo $total_ojts; ?></h3>
    </div>

    <!-- Active Now -->
    <div class="card p-6 border-l-4 border-l-emerald-500">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Active Now</p>
        <div class="flex items-center gap-3">
            <h3 class="text-3xl font-bold text-emerald-600"><?php echo $active_now; ?></h3>
            <span class="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
        </div>
    </div>

    <!-- Pending Approvals by Department -->
    <div class="card p-6 border-l-4 border-l-amber-500 bg-white shadow-sm">
    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Pending Approvals by Dept</p>
    
    <ul class="text-xs mt-2 space-y-2">
        <?php if (empty($pending_by_dept)): ?>
            <li class="italic text-slate-400 font-bold uppercase text-[10px]">No pending HR approvals</li>
        <?php else: ?>
            <?php foreach ($pending_by_dept as $d): ?>
                <li class="flex justify-between items-center font-black text-amber-700 uppercase tracking-tight border-b border-amber-50 pb-1">
                    <span><?php echo htmlspecialchars($d['department']); ?></span>
                    <span class="bg-amber-100 px-2 py-0.5 rounded-lg text-amber-900 shadow-sm">
                        <?php echo $d['count']; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        <?php endif; ?>
    </ul>
</div>

    <div class="card p-6 border-l-4 border-l-rose-500">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Pending Supervisor Approval</p>
        <div class="flex items-end justify-between gap-3">
            <h3 class="text-3xl font-bold text-rose-600"><?php echo (int)$pending_supervisor_requests_count; ?></h3>
            <a href="requests.php?filter=supervisor_pending" class="px-3 py-1.5 bg-rose-50 text-rose-700 rounded-xl text-[9px] font-black uppercase tracking-widest hover:bg-rose-700 hover:text-white transition-all">View</a>
        </div>
        <p class="text-[10px] text-slate-400 font-bold mt-2 uppercase tracking-widest">System-wide queue</p>
    </div>

    <!-- Today’s Attendance -->
    <div class="card p-6 border-l-4 border-l-blue-500">
        <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Today’s Attendance</p>
        <h3 class="text-3xl font-bold text-blue-600"><?php echo $timed_in_today; ?> timed-in</h3>
        <p class="text-xs text-blue-400"><?php echo $still_active; ?> still active</p>
    </div>
</div>
        <!-- Actionable List: Recent Attendance with Photos -->
        <div class="card overflow-hidden lg:col-span-2">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h2 class="text-xs font-bold text-slate-600 uppercase tracking-widest">Recent Attendance Photos</h2>
                <a href="trainees.php" class="text-[10px] font-bold text-[#6D3089] hover:underline uppercase">View All Logs</a>
            </div>
            <div class="p-6 grid grid-cols-2 md:grid-cols-5 gap-4">
                <?php
                $recent_photos = $pdo->query("
                    SELECT a.*, t.first_name, t.last_name 
                    FROM attendance a 
                    JOIN trainees t ON a.trainee_id = t.id 
                    WHERE (a.photo_in IS NOT NULL OR a.photo_out IS NOT NULL) 
                    AND t.deleted_at IS NULL AND a.deleted_at IS NULL 
                    ORDER BY a.date DESC, a.time_in DESC LIMIT 5
                ")->fetchAll();
                
                if (empty($recent_photos)): ?>
                    <div class="col-span-full py-10 text-center text-slate-400 italic text-xs">No attendance photos available.</div>
                <?php endif; ?>
                
                <?php foreach ($recent_photos as $rp): 
                    $photo = $rp['photo_in'] ?: $rp['photo_out'];
                ?>
                <div class="group relative aspect-[3/4] rounded-2xl overflow-hidden bg-slate-100 border border-slate-200">
                    <img src="../serve_image.php?file=<?php echo urlencode($photo); ?>&trainee_id=<?php echo (int)$rp['trainee_id']; ?>" class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-500">
                    <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-black/20 to-transparent p-3 flex flex-col justify-end">
                        <p class="text-[10px] font-bold text-white truncate"><?php echo $rp['first_name']; ?></p>
                        <p class="text-[8px] text-slate-300 font-medium"><?php echo date('M d, h:i A', strtotime($rp['time_in'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Actionable List: Pending Approval -->
        <div class="card overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h2 class="text-xs font-bold text-slate-600 uppercase tracking-widest">Pending Approval</h2>
                <a href="trainees.php?status=pending" class="text-[10px] font-bold text-[#6D3089] hover:underline uppercase">View All</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if (empty($pending_ojts)): ?>
                    <div class="p-8 text-center text-slate-400 italic text-xs">No pending applications.</div>
                <?php endif; ?>
                <?php foreach ($pending_ojts as $t): ?>
                <div class="p-4 flex justify-between items-center hover:bg-slate-50/50 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-amber-50 text-amber-600 flex items-center justify-center text-[10px] font-bold">
                            <?php echo $t['first_name'][0]; ?><?php echo $t['last_name'][0]; ?>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($t['first_name'] . ' ' . $t['last_name']); ?></p>
                            <p class="text-[9px] text-slate-400 font-bold uppercase"><?php echo $t['school']; ?></p>
                        </div>
                    </div>
                    <a href="trainees.php?action=approve&id=<?php echo $t['id']; ?>&csrf_token=<?php echo urlencode($csrf_token); ?>" class="px-3 py-1 bg-emerald-50 text-emerald-600 rounded text-[9px] font-bold uppercase hover:bg-emerald-600 hover:text-white transition-all">Quick Approve</a>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        

    <!-- Visual Insights -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Dept Distribution Chart -->
        <div class="card p-6 lg:col-span-1 h-[350px]">
            <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-6 border-l-4 border-[#6D3089] pl-4">Department Load</h4>
            <canvas id="deptChart"></canvas>
        </div>

        <!-- Monthly Trends Chart -->
        <div class="card p-6 lg:col-span-2 h-[350px]">
            <h4 class="text-[10px] font-bold text-slate-500 uppercase tracking-widest mb-6 border-l-4 border-emerald-500 pl-4">Attendance Trends (Last 7 Days)</h4>
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- Actionable List: Active Now -->
        <div class="card overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h2 class="text-xs font-bold text-slate-600 uppercase tracking-widest">Active Now (Recent)</h2>
                <a href="trainees.php" class="text-[10px] font-bold text-[#6D3089] hover:underline uppercase">Monitor All</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if (empty($active_ojts)): ?>
                    <div class="p-8 text-center text-slate-400 italic text-xs">No one is currently timed-in.</div>
                <?php endif; ?>
                <?php foreach ($active_ojts as $log): ?>
                <div class="p-4 flex justify-between items-center hover:bg-slate-50/50 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold">
                            <?php echo $log['first_name'][0]; ?><?php echo $log['last_name'][0]; ?>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></p>
                            <p class="text-[9px] text-slate-400 font-bold uppercase"><?php echo $log['department']; ?></p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-[10px] font-bold text-emerald-600 uppercase">IN: <?php echo date('h:i A', strtotime($log['time_in'])); ?></p>
                        <p class="text-[8px] text-slate-400 font-bold uppercase"><?php echo date('M d'); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div>

<script>
    // 1. Department Distribution Chart
    <?php
    $dept_data = $pdo->query("SELECT department, COUNT(*) as count FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL GROUP BY department")->fetchAll(PDO::FETCH_ASSOC);
    $labels = array_column($dept_data, 'department');
    $counts = array_column($dept_data, 'count');
    ?>
    
    new Chart(document.getElementById('deptChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($labels); ?>,
            datasets: [{
                data: <?php echo json_encode($counts); ?>,
                backgroundColor: ['#6D3089', '#10B981', '#F59E0B', '#3B82F6', '#EF4444', '#6366F1'],
                borderWidth: 0,
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 8, usePointStyle: true, font: { size: 10, weight: 'bold' } } }
            },
            cutout: '70%'
        }
    });

    // 2. Attendance Trends Chart
    <?php
    $trend_data = $pdo->query("
        SELECT date, COUNT(*) as count 
        FROM attendance 
        WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) 
        AND deleted_at IS NULL 
        GROUP BY date 
        ORDER BY date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $trend_labels = array_map(function($d) { return date('M d', strtotime($d['date'])); }, $trend_data);
    $trend_counts = array_column($trend_data, 'count');
    ?>

    new Chart(document.getElementById('trendChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($trend_labels); ?>,
            datasets: [{
                label: 'Daily Time-Ins',
                data: <?php echo json_encode($trend_counts); ?>,
                borderColor: '#10B981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointBackgroundColor: '#fff',
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { beginAtZero: true, grid: { display: false }, ticks: { font: { size: 10 } } },
                x: { grid: { display: false }, ticks: { font: { size: 10 } } }
            }
        }
    });

    async function openBroadcastModal() {
        const { value: formValues } = await Swal.fire({
            title: 'Send System Announcement',
            html: `
                <div class="text-left space-y-4">
                    <input id="b_title" class="swal2-input w-full m-0" style="font-size: 14px; border-radius: 12px;" placeholder="Announcement Title">
                    <textarea id="b_msg" class="swal2-textarea w-full m-0" style="font-size: 14px; border-radius: 12px; height: 120px;" placeholder="Message to be sent..."></textarea>
                    <div class="space-y-1">
                        <label class="text-[9px] font-black text-slate-400 uppercase tracking-widest ml-1">Attach Image (Optional)</label>
                        <input type="file" id="b_image" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-purple-50 file:text-[#6D3089] hover:file:bg-purple-100" accept="image/*">
                    </div>
                    <select id="b_target" class="swal2-input w-full m-0" style="font-size: 14px; border-radius: 12px;">
                        <option value="all">Broadcast to All</option>
                        <option value="admin">HR Admins Only</option>
                        <option value="supervisor">Supervisors Only</option>
                        <option value="trainee">Trainees Only</option>
                    </select>
                </div>
            `,
            confirmButtonText: 'BROADCAST NOW',
            confirmButtonColor: '#6D3089',
            showCancelButton: true,
            cancelButtonText: 'CANCEL',
            preConfirm: () => {
                const title = document.getElementById('b_title').value;
                const message = document.getElementById('b_msg').value;
                const image = document.getElementById('b_image').files[0];
                if (!title || !message) {
                    Swal.showValidationMessage('Please fill in both title and message');
                }
                return {
                    title: title,
                    message: message,
                    target: document.getElementById('b_target').value,
                    image: image
                }
            }
        });

        if (formValues) {
            const formData = new FormData();
            formData.append('action', 'broadcast');
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('title', formValues.title);
            formData.append('message', formValues.message);
            formData.append('target', formValues.target);
            if (formValues.image) {
                formData.append('image', formValues.image);
            }

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                window.location.href = 'dashboard.php?status=broadcast_sent';
            });
        }
    }
</script>

<?php renderFooter(); ?>
