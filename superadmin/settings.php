<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('superadmin');

// 1. SYSTEM SETTINGS
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$stmt = $pdo->prepare("SELECT username, first_name, last_name, email, phone FROM trainees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$_SESSION['user_id']]);
$me = $stmt->fetch();

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
        logAudit($pdo, $_SESSION['user_id'], 'UPDATE_SETTINGS', "System protocols updated");
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
}

$csrf_token = generateCsrfToken();
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

        <!-- System Maintenance -->
        <div class="bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm space-y-6">
            <div class="flex items-center gap-3 border-b border-slate-50 pb-4">
                <div class="w-10 h-10 rounded-xl bg-slate-900 flex items-center justify-center text-white">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                </div>
                <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Maintenance Mode</h3>
            </div>
            <div class="flex items-center justify-between p-4 bg-slate-50 rounded-2xl border border-slate-100">
                <div>
                    <p class="text-xs font-bold text-slate-700 uppercase tracking-wider">System-Wide Lock</p>
                    <p class="text-[9px] text-slate-400 mt-1 uppercase font-black tracking-widest">When enabled, only Superadmins can log in.</p>
                </div>
                <select name="settings[maintenance_mode]" class="bg-white border border-slate-200 rounded-xl px-4 py-2 text-xs font-bold text-slate-600 outline-none">
                    <option value="0" <?php echo ($settings['maintenance_mode'] ?? '0') == '0' ? 'selected' : ''; ?>>DISABLED (Live)</option>
                    <option value="1" <?php echo ($settings['maintenance_mode'] ?? '0') == '1' ? 'selected' : ''; ?>>ENABLED (Lockdown)</option>
                </select>
            </div>
        </div>

        <div class="flex justify-end pt-4">
            <button type="submit" class="px-10 py-4 bg-[#6D3089] text-white rounded-[2rem] font-black text-xs uppercase tracking-widest shadow-xl shadow-purple-500/20 hover:scale-105 transition-all">
                Save Settings
            </button>
        </div>
    </form>
</div>
<?php
// Place this at the top of superadmin/settings.php alongside other POST processing logic
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_retention') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: settings.php?error=csrf_failed");
        exit();
    }
    
    // Ensure the retention window cannot be broken or configured to less than 1 day
    $days = max(1, (int)$_POST['retention_days']);
    
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (setting_key, setting_value) 
        VALUES ('message_retention_days', ?) 
        ON DUPLICATE KEY UPDATE setting_value = ?
    ");
    $stmt->execute([$days, $days]);
    
    header("Location: settings.php?status=settings_updated");
    exit();
}

// Fetch current setting value to pre-populate the input layout form field
$currentDaysQuery = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'message_retention_days' LIMIT 1");
$currentDays = $currentDaysQuery->fetchColumn();
$currentDays = ($currentDays !== false) ? (int)$currentDays : 15;
?>

<div class="max-w-md bg-white rounded-[2rem] border border-slate-100 p-8 shadow-sm">
    <div class="mb-6">
        <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Data Retention Settings</h3>
        <p class="text-[10px] text-slate-400 uppercase font-black tracking-wider mt-1">Manage secure automated chat history purging parameters.</p>
    </div>
    
    <form method="POST" class="space-y-6">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="action" value="update_retention">
        
        <div>
            <label class="block text-xs font-black text-slate-700 uppercase tracking-wider mb-2">Message Lifespan (Days)</label>
            <div class="relative">
                <input type="number" name="retention_days" min="1" max="365" value="<?php echo htmlspecialchars($currentDays); ?>" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-6 py-4 text-sm font-bold text-slate-800 focus:bg-white focus:border-[#6D3089] outline-none transition-all" required>
                <span class="absolute right-6 top-4 text-xs font-bold text-slate-400 uppercase">Days</span>
            </div>
        </div>

        <button type="submit" class="w-full py-4 bg-[#6D3089] text-white text-xs font-black uppercase tracking-widest rounded-2xl shadow-xl shadow-purple-500/10 hover:scale-[1.02] transition-all">
            Update Retention Rules
        </button>
    </form>
</div>
<?php renderFooter(); ?>
