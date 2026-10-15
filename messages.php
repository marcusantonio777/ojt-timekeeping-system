<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");

require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';
require_once 'includes/layouts/main_layout.php';

checkAccess('trainee, admin, superadmin');

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$csrf_token = generateCsrfToken();

/**
 * -------------------------------------------------------------
 * AUTOMATED CONFIGURABLE DATA PURGE SYSTEM
 * -------------------------------------------------------------
 * Fetches retention rules managed dynamically by Superadmins.
 * Defaults securely to 15 days if setting doesn't exist yet.
 */
try {
    $settingsStmt = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'message_retention_days' LIMIT 1");
    $retentionSetting = $settingsStmt->fetchColumn();
    $daysThreshold = ($retentionSetting !== false && is_numeric($retentionSetting)) ? (int)$retentionSetting : 15;
    
    if ($daysThreshold > 0) {
        $purgeStmt = $pdo->prepare("DELETE FROM messages WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
        $purgeStmt->execute([$daysThreshold]);
    }
} catch (PDOException $e) {
    error_log("Message Auto-Purge Failure: " . $e->getMessage());
}

// Handle Sending Message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_message') {
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die("CSRF Token Validation Failed");
    }

    $receiver_id = (int)$_POST['receiver_id'];
    $message = trim($_POST['message']);
    
    if ($role === 'trainee') {
        $stmt = $pdo->query("SELECT id FROM trainees WHERE role IN ('admin','superadmin') AND deleted_at IS NULL ORDER BY (department = 'Human Resource') DESC, (role = 'superadmin') DESC, id ASC LIMIT 1");
        $receiver_id = (int)($stmt->fetchColumn() ?: 0);
    } else {
        $stmt = $pdo->prepare("SELECT id FROM trainees WHERE id = ? AND role = 'trainee' AND deleted_at IS NULL");
        $stmt->execute([$receiver_id]);
        if (!$stmt->fetchColumn()) {
            $receiver_id = 0;
        }
    }

    if ($receiver_id > 0 && !empty($message)) {
        $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message) VALUES (?, ?, ?)");
        $stmt->execute([$user_id, $receiver_id, $message]);
        logAudit($pdo, $user_id, 'SEND_MESSAGE', "Sent message to user ID: $receiver_id");
        header("Location: messages.php?status=sent&contact_id=" . $receiver_id);
        exit();
    }
}

// Fetch Conversations (For Admin/HR)
$conversations = [];
if ($role !== 'trainee') {
    $stmt = $pdo->prepare("
        SELECT t.id, t.first_name, t.last_name, t.role, t.department,
        (SELECT message FROM messages WHERE (sender_id = t.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = t.id) ORDER BY created_at DESC LIMIT 1) as last_msg,
        (SELECT created_at FROM messages WHERE (sender_id = t.id AND receiver_id = ?) OR (sender_id = ? AND receiver_id = t.id) ORDER BY created_at DESC LIMIT 1) as last_date,
        (SELECT COUNT(*) FROM messages WHERE sender_id = t.id AND receiver_id = ? AND is_read = 0) as unread_count
        FROM trainees t
        WHERE t.role = 'trainee' AND t.deleted_at IS NULL
        HAVING last_msg IS NOT NULL
        ORDER BY last_date DESC
    ");
    $stmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id]);
    $conversations = $stmt->fetchAll();
}

// Active Contact
$contact_id = isset($_GET['contact_id']) ? (int)$_GET['contact_id'] : 0;
if ($role === 'trainee' && $contact_id === 0) {
    $stmt = $pdo->query("SELECT id FROM trainees WHERE role IN ('admin','superadmin') AND deleted_at IS NULL ORDER BY (department = 'Human Resource') DESC, (role = 'superadmin') DESC, id ASC LIMIT 1");
    $contact_id = (int)($stmt->fetchColumn() ?: 0);
}

$messages = [];
$contact_name = "System HR";
if ($contact_id > 0) {
    // Mark as read
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ?");
    $stmt->execute([$contact_id, $user_id]);

    // Fetch history
    $stmt = $pdo->prepare("
        SELECT m.*, s.first_name as sender_name, s.role as sender_role
        FROM messages m
        JOIN trainees s ON m.sender_id = s.id
        WHERE (m.sender_id = ? AND m.receiver_id = ?)
           OR (m.sender_id = ? AND m.receiver_id = ?)
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$user_id, $contact_id, $contact_id, $user_id]);
    $messages = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT first_name, last_name, role, department FROM trainees WHERE id = ?");
    $stmt->execute([$contact_id]);
    $contact = $stmt->fetch();
    if ($contact) {
        $contact_name = $contact['first_name'] . ' ' . $contact['last_name'];
    }
}

renderHeader('Confidential HR Chat');
renderSidebar('messages');
?>

<div class="h-[calc(100vh-140px)] flex gap-6">
    <?php if ($role !== 'trainee'): ?>
    <div class="w-80 bg-white rounded-[2rem] border border-slate-100 flex flex-col overflow-hidden shadow-sm">
        <div class="p-6 border-b border-slate-50">
            <h3 class="text-sm font-black text-slate-800 uppercase tracking-widest">Inbox</h3>
        </div>
        <div class="flex-1 overflow-y-auto divide-y divide-slate-50">
            <?php if (empty($conversations)): ?>
                <div class="p-8 text-center text-slate-400 italic text-xs">No active conversations.</div>
            <?php endif; ?>
            <?php foreach ($conversations as $c): ?>
                <a href="?contact_id=<?php echo $c['id']; ?>" class="p-5 block hover:bg-slate-50 transition-all <?php echo $contact_id === (int)$c['id'] ? 'bg-purple-50/50 border-l-4 border-l-[#6D3089]' : ''; ?>">
                    <div class="flex justify-between items-start mb-1">
                        <p class="text-xs font-bold text-slate-800"><?php echo e($c['first_name'] . ' ' . $c['last_name']); ?></p>
                        <span class="text-[8px] font-bold text-slate-400 uppercase"><?php echo date('M d', strtotime($c['last_date'])); ?></span>
                    </div>
                    <p class="text-[10px] text-slate-500 truncate pr-4"><?php echo e($c['last_msg']); ?></p>
                    <?php if ($c['unread_count'] > 0): ?>
                        <span class="inline-block mt-2 px-2 py-0.5 bg-rose-500 text-white text-[8px] font-black rounded-full"><?php echo $c['unread_count']; ?> NEW</span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex-1 bg-white rounded-[2rem] border border-slate-100 flex flex-col overflow-hidden shadow-sm">
        <div class="p-6 border-b border-slate-50 flex items-center justify-between bg-slate-50/30">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-[#6D3089] text-white flex items-center justify-center font-bold text-sm">
                    <?php echo substr($contact_name, 0, 1); ?>
                </div>
                <div>
                    <h3 class="text-xs font-black text-slate-800 uppercase tracking-widest"><?php echo e($contact_name); ?></h3>
                    <p class="text-[9px] font-bold text-emerald-500 uppercase tracking-widest">Confidential Channel</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 bg-slate-900 text-white text-[8px] font-black uppercase tracking-widest rounded-lg">Retention Active: <?php echo $daysThreshold; ?> Days</span>
            </div>
        </div>

        <div id="chat-box" class="flex-1 overflow-y-auto p-8 space-y-6 bg-[#F8FAFC]/50">
            <?php if (empty($messages)): ?>
                <div class="h-full flex flex-col items-center justify-center text-center space-y-4">
                    <div class="w-16 h-16 bg-slate-100 rounded-3xl flex items-center justify-center text-slate-300">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Start a Confidential Conversation</p>
                        <p class="text-[9px] text-slate-400 uppercase font-black tracking-widest mt-1">Messages auto-erase after <?php echo $daysThreshold; ?> days per security policy.</p>
                    </div>
                </div>
            <?php endif; ?>

            <?php foreach ($messages as $m): ?>
                <?php $is_me = (int)$m['sender_id'] === (int)$user_id; ?>
                <div class="flex <?php echo $is_me ? 'justify-end' : 'justify-start'; ?>">
                    <div class="max-w-[70%] <?php echo $is_me ? 'bg-[#6D3089] text-white rounded-t-2xl rounded-bl-2xl shadow-lg shadow-purple-500/10' : 'bg-white border border-slate-100 text-slate-700 rounded-t-2xl rounded-br-2xl shadow-sm'; ?> p-4">
                        <p class="text-sm font-medium leading-relaxed"><?php echo nl2br(e($m['message'])); ?></p>
                        <div class="mt-2 flex items-center justify-between gap-4">
                            <span class="text-[8px] font-black uppercase opacity-60"><?php echo date('h:i A', strtotime($m['created_at'])); ?></span>
                            <?php if ($is_me): ?>
                                <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="p-6 bg-white border-t border-slate-50">
            <form method="POST" class="flex gap-4">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="send_message">
                <input type="hidden" name="receiver_id" value="<?php echo $contact_id; ?>">
                <div class="flex-1 relative">
                    <textarea name="message" rows="1" class="w-full bg-slate-50 border border-slate-100 rounded-2xl px-6 py-4 text-sm font-medium text-slate-700 focus:bg-white focus:border-[#6D3089] outline-none transition-all resize-none" placeholder="Type your confidential message here..." required></textarea>
                </div>
                <button type="submit" class="w-14 h-14 bg-[#6D3089] text-white rounded-2xl flex items-center justify-center shadow-xl shadow-purple-500/20 hover:scale-105 transition-all">
                    <svg class="w-6 h-6 transform rotate-90" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                </button>
            </form>
        </div>
    </div>
</div>

<script>
    const chatBox = document.getElementById('chat-box');
    if (chatBox) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
</script>

<?php renderFooter(); ?>