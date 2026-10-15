<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('superadmin');

// 1. STATS
$total_users = $pdo->query("SELECT COUNT(*) FROM trainees WHERE deleted_at IS NULL")->fetchColumn();
$total_admins = $pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'admin' AND deleted_at IS NULL")->fetchColumn();
$total_supervisors = $pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'supervisor' AND deleted_at IS NULL")->fetchColumn();
$total_trainees = $pdo->query("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND deleted_at IS NULL")->fetchColumn();

// 2. SETTINGS
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$maintenance = $settings['maintenance_mode'] ?? '0';

// 3. AUDIT
$audit_logs = $pdo->query("SELECT l.*, t.first_name as username, t.last_name, t.role as admin_role 
                           FROM audit_logs l 
                           LEFT JOIN trainees t ON l.user_id = t.id 
                           ORDER BY l.created_at DESC LIMIT 10")->fetchAll();

// 4. SESSIONS
$active_sessions = $pdo->query("SELECT a.*, t.first_name, t.last_name, t.department 
                                FROM attendance a 
                                JOIN trainees t ON a.trainee_id = t.id 
                                WHERE a.time_out IS NULL AND a.deleted_at IS NULL 
                                ORDER BY a.time_in DESC")->fetchAll();
$active_now = count($active_sessions);

if (isset($_GET['api']) && $_GET['api'] === 'widgets') {
    header('Content-Type: application/json');
    echo json_encode([
        'metrics' => [
            'total_users' => (int)$total_users,
            'total_admins' => (int)$total_admins,
            'total_supervisors' => (int)$total_supervisors,
            'total_trainees' => (int)$total_trainees,
            'active_now' => (int)$active_now
        ],
        'maintenance' => $maintenance === '1',
        'audit' => $audit_logs
    ]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: dashboard.php?error=csrf_failed");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'toggle_maintenance') {
        $new_val = $maintenance === '1' ? '0' : '1';
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'maintenance_mode'");
        $stmt->execute([$new_val]);
        logAudit($pdo, $_SESSION['user_id'], 'MAINTENANCE_TOGGLE', "Set maintenance mode to $new_val");
        header("Location: dashboard.php?status=maintenance_toggled");
        exit();
    }

    if ($action === 'broadcast') {
        $title = trim($_POST['title']);
        $msg = trim($_POST['message']);
        $target = $_POST['target'] ?? 'all';
        $image_path = handleUploadedImage($_FILES['image'] ?? null, __DIR__ . '/../uploads/announcements', 'ann_');

        $stmt = $pdo->prepare("INSERT INTO system_notifications (title, message, role_target, created_by, image_path) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $msg, $target, $_SESSION['user_id'], $image_path]);
        logAudit($pdo, $_SESSION['user_id'], 'BROADCAST', "Sent broadcast to $target with image: " . ($image_path ?: 'none'));
        header("Location: dashboard.php?status=broadcast_sent");
        exit();
    }

    if ($action === 'update_governance') {
        $val = $_POST['allow_hr_audit_view'] ?? '0';
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('allow_hr_audit_view', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute([$val]);
        logAudit($pdo, $_SESSION['user_id'], 'UPDATE_GOVERNANCE', "Updated HR Admin audit visibility to $val");
        header("Location: dashboard.php?status=governance_updated");
        exit();
    }
}

$csrf_token = generateCsrfToken();
$allow_hr_audit_view = getSetting($pdo, 'allow_hr_audit_view', '0');
renderHeader('Godmode Terminal');
renderSidebar('dashboard');
?>
<?php include '../includes/footer.php'; ?>
<div class="space-y-10">
    <div class="flex flex-col md:flex-row md:items-end justify-between gap-6">
        <div>
            <div class="flex items-center gap-3 mb-2">
                <span class="px-3 py-1 bg-slate-900 text-white text-[10px] font-black uppercase tracking-widest rounded-lg">System Core Control</span>
                <span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>
                <span class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Global Overview</span>
            </div>
            <h1 class="text-4xl font-black text-slate-900 uppercase tracking-tighter">System Intelligence</h1>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <button onclick="openBroadcastModal()" class="px-6 py-3 bg-white border border-slate-200 text-slate-700 rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-slate-50 transition-all flex items-center gap-2 shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.167H3.38a1.745 1.745 0 01-1.445-2.733l4.408-6.527a1.745 1.745 0 012.657 1.477z"></path></svg>
                Send Broadcast
            </button>

            <form method="POST" class="inline">
                <input type="hidden" name="action" value="toggle_maintenance">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <button type="submit" class="px-6 py-3 <?php echo $maintenance === '1' ? 'bg-rose-600' : 'bg-emerald-600'; ?> text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:opacity-90 transition-all flex items-center gap-2 shadow-lg shadow-emerald-500/20">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    <?php echo $maintenance === '1' ? 'Disable Lockdown' : 'Enable Lockdown'; ?>
                </button>
            </form>

            <a href="settings.php" class="px-6 py-3 bg-[#6D3089] text-white rounded-2xl text-[10px] font-black uppercase tracking-widest hover:bg-[#5a2771] transition-all flex items-center gap-2 shadow-xl shadow-purple-500/20">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path></svg>
                Protocols
            </a>

            <!-- Governance Toggle -->
            <form method="POST" class="inline">
                <input type="hidden" name="action" value="update_governance">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="flex items-center gap-2 px-6 py-3 bg-white border border-slate-200 rounded-2xl">
                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">HR Audit Access</span>
                    <select name="allow_hr_audit_view" onchange="this.form.submit()" class="bg-transparent text-[10px] font-black text-[#6D3089] outline-none">
                        <option value="0" <?php echo $allow_hr_audit_view === '0' ? 'selected' : ''; ?>>LOCKED</option>
                        <option value="1" <?php echo $allow_hr_audit_view === '1' ? 'selected' : ''; ?>>VISIBLE</option>
                    </select>
                </div>
            </form>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="card p-8 group hover:border-slate-900 transition-all">
            <div class="flex items-center justify-between mb-4">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                </div>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Global</span>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Total Users</p>
            <h3 class="text-4xl font-black text-slate-900"><?php echo $total_users; ?></h3>
        </div>

        <div class="card p-8 group hover:border-[#6D3089] transition-all">
            <div class="flex items-center justify-between mb-4">
                <div class="w-10 h-10 rounded-xl bg-purple-50 text-[#6D3089] flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Trainees</span>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Active OJTs</p>
            <h3 class="text-4xl font-black text-[#6D3089]"><?php echo $total_trainees; ?></h3>
        </div>

        <div class="card p-8 group hover:border-emerald-500 transition-all">
            <div class="flex items-center justify-between mb-4">
                <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <?php if ($active_now > 0): ?>
                    <span class="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                <?php endif; ?>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Live Logs</p>
            <h3 class="text-4xl font-black text-emerald-600"><?php echo $active_now; ?></h3>
        </div>

        <div class="card p-8 group hover:border-rose-500 transition-all">
            <div class="flex items-center justify-between mb-4">
                <div class="w-10 h-10 rounded-xl bg-rose-50 text-rose-600 flex items-center justify-center">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Integrity</span>
            </div>
            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1">Audit Alerts</p>
            <h3 class="text-4xl font-black text-rose-600"><?php echo count($audit_logs); ?></h3>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 card overflow-hidden">
            <div class="p-6 border-b border-slate-50 flex justify-between items-center bg-slate-50/30">
                <div>
                    <h2 class="text-[10px] font-black text-slate-800 uppercase tracking-[0.2em]">Security Audit</h2>
                    <p class="text-[8px] font-bold text-slate-400 uppercase tracking-widest mt-1">Real-time system actions</p>
                </div>
            </div>
            <div class="divide-y divide-slate-50 max-h-[520px] overflow-y-auto">
                <?php foreach ($audit_logs as $log): ?>
                <div class="p-5 flex justify-between items-center hover:bg-slate-50 transition-all group">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-slate-50 text-slate-400 flex items-center justify-center text-[10px] font-black border border-slate-100">
                            <?php echo substr($log['action'], 0, 2); ?>
                        </div>
                        <div>
                            <p class="text-[11px] font-black text-slate-800 uppercase tracking-tight max-w-[520px] truncate" title="<?php echo htmlspecialchars($log['details']); ?>"><?php echo htmlspecialchars($log['details']); ?></p>
                            <div class="flex items-center gap-2 mt-0.5">
                                <span class="text-[8px] font-black text-[#6D3089] uppercase tracking-widest"><?php echo htmlspecialchars($log['action']); ?></span>
                                <span class="w-1 h-1 rounded-full bg-slate-200"></span>
                                <span class="text-[8px] text-slate-400 font-bold uppercase tracking-widest"><?php echo htmlspecialchars($log['username']); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest"><?php echo date('h:i A', strtotime($log['created_at'])); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="space-y-8">
            <?php if ($maintenance === '1'): ?>
            <div class="card p-8 bg-rose-50 border-rose-100 border-2">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-8 h-8 rounded-lg bg-rose-600 text-white flex items-center justify-center">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                    </div>
                    <h2 class="text-[10px] font-black text-rose-600 uppercase tracking-[0.2em]">System Locked</h2>
                </div>
                <p class="text-[10px] text-rose-500 font-bold leading-relaxed uppercase">Maintenance mode is currently active. Only Superadmins can access the system.</p>
            </div>
            <?php endif; ?>

            <div class="card p-8">
                <div class="flex items-center justify-between mb-8">
                    <h2 class="text-[10px] font-black text-slate-800 uppercase tracking-[0.2em]">Operational Pulse</h2>
                    <svg class="w-4 h-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                </div>
                <div class="space-y-6">
                    <div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Database Sync</span>
                            <span class="text-[9px] font-black text-emerald-600 uppercase tracking-widest">Healthy</span>
                        </div>
                        <div class="w-full bg-slate-50 h-1.5 rounded-full">
                            <div class="bg-emerald-500 h-full rounded-full w-[98%]"></div>
                        </div>
                    </div>
                    </div>
            </div>
        </div>
    </div>
</div>

<script>
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

    // Check URL for status messages
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('status') === 'maintenance_toggled') {
        Swal.fire({ title: 'Success', text: 'System status updated.', icon: 'success', timer: 2000, showConfirmButton: false });
    } else if (urlParams.get('status') === 'broadcast_sent') {
        Swal.fire({ title: 'Broadcast Sent', text: 'Notifications have been dispatched.', icon: 'success', timer: 2000, showConfirmButton: false });
    }
</script>

<?php renderFooter(); ?>
