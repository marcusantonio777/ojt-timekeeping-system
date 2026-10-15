<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('admin, superadmin');

// 1. SYSTEM SETTINGS
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $pdo->prepare("SELECT username, first_name, last_name, email, phone FROM trainees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();

$audit_action = isset($_GET['audit_action']) ? trim($_GET['audit_action']) : '';
$audit_user = isset($_GET['audit_user']) ? trim($_GET['audit_user']) : '';
$audit_from = isset($_GET['audit_from']) ? trim($_GET['audit_from']) : '';
$audit_to = isset($_GET['audit_to']) ? trim($_GET['audit_to']) : '';
$audit_limit = 100;

if (isset($_GET['export']) && $_GET['export'] === 'audit_csv') {
    $query = "SELECT l.created_at, l.action, l.details, l.ip_address, t.username, t.first_name, t.last_name, t.role
              FROM audit_logs l
              LEFT JOIN trainees t ON l.user_id = t.id
              WHERE 1=1";
    $params = [];
    if ($audit_action !== '') {
        $query .= " AND l.action = ?";
        $params[] = $audit_action;
    }
    if ($audit_user !== '') {
        $query .= " AND (t.username LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ?)";
        $p = "%{$audit_user}%";
        $params = array_merge($params, [$p, $p, $p]);
    }
    if ($audit_from !== '') {
        $query .= " AND DATE(l.created_at) >= ?";
        $params[] = $audit_from;
    }
    if ($audit_to !== '') {
        $query .= " AND DATE(l.created_at) <= ?";
        $params[] = $audit_to;
    }
    $query .= " ORDER BY l.created_at DESC LIMIT 5000";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=audit_logs_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['created_at', 'action', 'details', 'ip_address', 'username', 'first_name', 'last_name', 'role']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['created_at'], $r['action'], $r['details'], $r['ip_address'], $r['username'], $r['first_name'], $r['last_name'], $r['role']]);
    }
    fclose($out);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: settings.php?error=csrf_failed");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'update_settings') {
        foreach ($_POST['settings'] as $key => $value) {
            $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->execute([$value, $key]);
        }
        logAudit($pdo, $_SESSION['user_id'], 'UPDATE_SETTINGS', "HR Admin updated system protocols");
        header("Location: settings.php?status=settings_updated");
        exit();
    }

    if ($action === 'update_my_account') {
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $new_password = (string)($_POST['new_password'] ?? '');
        $sql = "UPDATE trainees SET email = ?, phone = ?";
        $params = [$email !== '' ? $email : null, $phone !== '' ? $phone : null];
        if ($new_password !== '') {
            $newToken = bin2hex(random_bytes(32));
            $sql .= ", password = ?, session_token = ?";
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            $params[] = $newToken;
        }
        $sql .= " WHERE id = ? AND deleted_at IS NULL";
        $params[] = $_SESSION['user_id'];
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($new_password !== '' && isset($newToken)) {
            $_SESSION['session_token'] = $newToken;
            session_regenerate_id(true);
        }
        logAudit($pdo, $_SESSION['user_id'], 'ACCOUNT_UPDATE', "Account settings updated");
        header("Location: settings.php?status=account_updated");
        exit();
    }

    if ($action === 'add_holiday') {
        $date = trim($_POST['holiday_date'] ?? '');
        $name = trim($_POST['holiday_name'] ?? '');
        $type = $_POST['holiday_type'] ?? 'holiday';
        if ($date !== '' && $name !== '' && in_array($type, ['holiday', 'special'], true)) {
            $stmt = $pdo->prepare("INSERT INTO holidays (date, name, type) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name), type = VALUES(type)");
            $stmt->execute([$date, $name, $type]);
            logAudit($pdo, $_SESSION['user_id'], 'HOLIDAY_UPSERT', "Holiday set: {$date} ({$type})");
        }
        header("Location: settings.php?status=holiday_saved");
        exit();
    }

    if ($action === 'delete_holiday') {
        $hid = (int)($_POST['holiday_id'] ?? 0);
        if ($hid > 0) {
            $stmt = $pdo->prepare("DELETE FROM holidays WHERE id = ?");
            $stmt->execute([$hid]);
            logAudit($pdo, $_SESSION['user_id'], 'HOLIDAY_DELETE', "Holiday deleted: {$hid}");
        }
        header("Location: settings.php?status=holiday_deleted");
        exit();
    }
}

$csrf_token = generateCsrfToken();
$allow_hr_audit_view = getSetting($pdo, 'allow_hr_audit_view', '0');

$holiday_list = $pdo->query("SELECT * FROM holidays ORDER BY date DESC LIMIT 100")->fetchAll();

$audit_logs = [];
if ($allow_hr_audit_view === '1') {
    $audit_query = "SELECT l.*, t.username, t.first_name, t.last_name, t.role as user_role
                    FROM audit_logs l
                    LEFT JOIN trainees t ON l.user_id = t.id
                    WHERE 1=1";
    $audit_params = [];
    if ($audit_action !== '') {
        $audit_query .= " AND l.action = ?";
        $audit_params[] = $audit_action;
    }
    if ($audit_user !== '') {
        $audit_query .= " AND (t.username LIKE ? OR t.first_name LIKE ? OR t.last_name LIKE ?)";
        $p = "%{$audit_user}%";
        $audit_params = array_merge($audit_params, [$p, $p, $p]);
    }
    if ($audit_from !== '') {
        $audit_query .= " AND DATE(l.created_at) >= ?";
        $audit_params[] = $audit_from;
    }
    if ($audit_to !== '') {
        $audit_query .= " AND DATE(l.created_at) <= ?";
        $audit_params[] = $audit_to;
    }
    $audit_query .= " ORDER BY l.created_at DESC LIMIT {$audit_limit}";
    $stmt = $pdo->prepare($audit_query);
    $stmt->execute($audit_params);
    $audit_logs = $stmt->fetchAll();
}

renderHeader('System Protocols');
renderSidebar('settings');
?>

<div class="max-w-4xl mx-auto space-y-8">
    <div>
        <h1 class="text-3xl font-black text-slate-800 uppercase tracking-tighter">System Settings</h1>
        <p class="text-xs font-bold text-slate-400 uppercase tracking-[0.3em]">Configure Rules & Security</p>
    </div>

    <form method="POST" class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="update_my_account">
        <div class="flex items-center gap-3 border-b border-slate-50 pb-4">
            <div class="w-10 h-10 rounded-xl bg-slate-100 flex items-center justify-center text-slate-700">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
            </div>
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">My Account</h3>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Email</label>
                <input type="email" name="email" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
            </div>
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Phone</label>
                <input type="text" name="phone" value="<?php echo htmlspecialchars($me['phone'] ?? ''); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
            </div>
            <div class="md:col-span-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">New Password (Optional)</label>
                <input type="password" name="new_password" minlength="8" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all" placeholder="Minimum 8 characters">
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" class="px-10 py-4 bg-slate-900 text-white rounded-[2rem] font-black text-xs uppercase tracking-widest hover:bg-slate-700 transition-all">
                Save Account
            </button>
        </div>
    </form>

    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="update_settings">

        <!-- Sanction Engine -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-50 pb-4">
                <div class="w-10 h-10 rounded-xl bg-rose-50 flex items-center justify-center text-rose-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                </div>
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Late Penalty Engine</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Late Threshold (Minutes)</label>
                    <input type="number" name="settings[auto_sanction_late_minutes]" value="<?php echo htmlspecialchars($settings['auto_sanction_late_minutes'] ?? '15'); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                    <p class="text-[9px] text-slate-400 mt-2 ml-1">Grace period before a trainee is marked as "Late".</p>
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Penalty Hours</label>
                    <input type="number" step="0.5" name="settings[auto_sanction_hours_penalty]" value="<?php echo htmlspecialchars($settings['auto_sanction_hours_penalty'] ?? '1'); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                    <p class="text-[9px] text-slate-400 mt-2 ml-1">Hours automatically added to trainee's required total.</p>
                </div>
            </div>
        </div>

        <!-- Geofencing Protocol -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-50 pb-4">
                <div class="w-10 h-10 rounded-xl bg-blue-50 flex items-center justify-center text-blue-500">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                </div>
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Geofencing Rules</h3>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="md:col-span-2">
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Allowed Radius (Meters)</label>
                    <input type="number" name="settings[geofencing_radius_meters]" value="<?php echo htmlspecialchars($settings['geofencing_radius_meters'] ?? '500'); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Hotel Latitude</label>
                    <input type="text" name="settings[hotel_lat]" value="<?php echo htmlspecialchars($settings['hotel_lat'] ?? '10.3167'); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Hotel Longitude</label>
                    <input type="text" name="settings[hotel_lng]" value="<?php echo htmlspecialchars($settings['hotel_lng'] ?? '123.9000'); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-4 text-sm font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                </div>
            </div>
        </div>

        <div class="flex justify-end pt-4">
            <button type="submit" class="px-10 py-4 bg-[#6D3089] text-white rounded-[2rem] font-black text-xs uppercase tracking-widest shadow-xl shadow-purple-500/20 hover:scale-105 transition-all">
                Save Settings
            </button>
        </div>
    </form>

    <div class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
        <div class="flex items-center gap-3 border-b border-slate-50 pb-4">
            <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center text-indigo-600">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Holidays</h3>
        </div>

        <form method="POST" class="grid grid-cols-1 md:grid-cols-4 gap-4 items-end">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="action" value="add_holiday">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Date</label>
                <input type="date" name="holiday_date" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all" required>
            </div>
            <div class="md:col-span-2">
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Name</label>
                <input type="text" name="holiday_name" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all" placeholder="Holiday name" required>
            </div>
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Type</label>
                <select name="holiday_type" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
                    <option value="holiday">Holiday</option>
                    <option value="special">Special</option>
                </select>
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button type="submit" class="px-10 py-3 bg-indigo-600 text-white rounded-2xl font-black text-xs uppercase tracking-widest hover:bg-indigo-700 transition-all">
                    Save Holiday
                </button>
            </div>
        </form>

        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Type</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($holiday_list)): ?>
                        <tr><td colspan="4" class="px-4 py-6 text-xs text-slate-400 italic text-center">No holidays set.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($holiday_list as $h): ?>
                        <tr class="text-xs">
                            <td class="px-4 py-3 font-bold text-slate-700"><?php echo htmlspecialchars($h['date']); ?></td>
                            <td class="px-4 py-3 text-slate-700 font-bold"><?php echo htmlspecialchars($h['name']); ?></td>
                            <td class="px-4 py-3 text-slate-500 font-black uppercase"><?php echo htmlspecialchars($h['type']); ?></td>
                            <td class="px-4 py-3 text-right">
                                <form method="POST" class="inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="delete_holiday">
                                    <input type="hidden" name="holiday_id" value="<?php echo (int)$h['id']; ?>">
                                    <button type="submit" class="px-4 py-2 bg-rose-50 text-rose-700 rounded-xl text-[10px] font-black uppercase tracking-widest hover:bg-rose-700 hover:text-white transition-all">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($allow_hr_audit_view === '1'): ?>
    <div class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
        <div class="flex items-center justify-between gap-4 border-b border-slate-50 pb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-slate-900 text-white flex items-center justify-center">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                </div>
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Audit Logs</h3>
            </div>
            <a href="?export=audit_csv&audit_action=<?php echo urlencode($audit_action); ?>&audit_user=<?php echo urlencode($audit_user); ?>&audit_from=<?php echo urlencode($audit_from); ?>&audit_to=<?php echo urlencode($audit_to); ?>" class="px-5 py-2.5 bg-indigo-50 text-indigo-700 rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-indigo-700 hover:text-white transition-all">
                Export CSV
            </a>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Action</label>
                <input type="text" name="audit_action" value="<?php echo htmlspecialchars($audit_action); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all" placeholder="LOGIN, SET_SCHEDULE...">
            </div>
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">User</label>
                <input type="text" name="audit_user" value="<?php echo htmlspecialchars($audit_user); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all" placeholder="Name or username">
            </div>
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">From</label>
                <input type="date" name="audit_from" value="<?php echo htmlspecialchars($audit_from); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
            </div>
            <div>
                <label class="block text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">To</label>
                <input type="date" name="audit_to" value="<?php echo htmlspecialchars($audit_to); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-5 py-3 text-xs font-bold text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all">
            </div>
            <div class="md:col-span-4 flex justify-end">
                <button type="submit" class="px-8 py-3 bg-slate-900 text-white rounded-2xl text-xs font-black uppercase tracking-widest hover:bg-slate-700 transition-all">
                    Apply Filters
                </button>
            </div>
        </form>

        <div class="overflow-x-auto max-h-[520px] overflow-y-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                        <th class="px-4 py-3">Time</th>
                        <th class="px-4 py-3">Action</th>
                        <th class="px-4 py-3">User</th>
                        <th class="px-4 py-3">IP</th>
                        <th class="px-4 py-3">Details</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($audit_logs)): ?>
                        <tr><td colspan="5" class="px-4 py-6 text-xs text-slate-400 italic text-center">No logs found.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($audit_logs as $l): ?>
                        <tr class="text-xs hover:bg-slate-50 transition-all">
                            <td class="px-4 py-3 text-slate-500 font-bold whitespace-nowrap"><?php echo date('M d, Y • h:i:s A', strtotime($l['created_at'])); ?></td>
                            <td class="px-4 py-3">
                                <span class="px-2.5 py-1 rounded-lg text-[9px] font-black uppercase tracking-widest 
                                    <?php 
                                        // ...existing code...
$classes = '';
switch ($l['action']) {
    case 'LOGIN':
        $classes = 'bg-emerald-100 text-emerald-700';
        break;
    case 'LOGOUT':
        $classes = 'bg-slate-100 text-slate-700';
        break;
    case 'ADD_TRAINEE':
    case 'EDIT_TRAINEE':
        $classes = 'bg-blue-100 text-blue-700';
        break;
    case 'ADD_SANCTION':
        $classes = 'bg-rose-100 text-rose-700';
        break;
    case 'HR_APPROVE':
    case 'SUPERVISOR_APPROVE':
    case 'HR_BULK_APPROVE':
        $classes = 'bg-indigo-100 text-indigo-700';
        break;
    case 'TIME_IN':
    case 'TIME_OUT':
        $classes = 'bg-purple-100 text-purple-700';
        break;
    case 'BROADCAST':
        $classes = 'bg-amber-100 text-amber-700';
        break;
    default:
        $classes = 'bg-slate-100 text-slate-600';
}
echo $classes;
// ...existing code...
                                    ?>">
                                    <?php echo htmlspecialchars($l['action']); ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 text-slate-600 font-bold whitespace-nowrap">
                                <?php
                                    $u = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
                                    echo htmlspecialchars($u !== '' ? $u : ($l['username'] ?? 'System'));
                                ?>
                                <span class="text-[9px] text-slate-400 font-black uppercase ml-1 opacity-60"><?php echo htmlspecialchars($l['user_role'] ?? ''); ?></span>
                            </td>
                            <td class="px-4 py-3 text-slate-500 font-bold"><?php echo htmlspecialchars($l['ip_address'] ?? ''); ?></td>
                            <td class="px-4 py-3 text-slate-600 max-w-xs truncate" title="<?php echo htmlspecialchars($l['details'] ?? ''); ?>"><?php echo htmlspecialchars($l['details'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php renderFooter(); ?>
