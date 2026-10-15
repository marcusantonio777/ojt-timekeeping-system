<?php
session_start();

// 1. Role Verification
$allowed_roles = ['admin', 'superadmin', 'hr_admin', 'hr admin'];

if (!isset($_SESSION['role']) || !in_array(strtolower($_SESSION['role']), $allowed_roles)) {
    die("Access Denied: HR Admin or Superadmin role required.");
}

// 2. Load Dependencies
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/layouts/main_layout.php';

// 3. Handle Sanction Removal & Deduct Hours from Trainee Record
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_sanction'])) {
    $sanction_id    = intval($_POST['sanction_id']);
    $removal_reason = trim($_POST['removal_reason']);
    $admin_id       = $_SESSION['user_id'] ?? 1;

    if ($sanction_id > 0 && !empty($removal_reason)) {
        try {
            $pdo->beginTransaction();

            // Fetch active sanction details
            $stmtFetch = $pdo->prepare("
                SELECT trainee_id, hours_added 
                FROM sanctions 
                WHERE id = ? AND (is_removed = 0 OR is_removed IS NULL)
            ");
            $stmtFetch->execute([$sanction_id]);
            $sanction = $stmtFetch->fetch(PDO::FETCH_ASSOC);

            if ($sanction) {
                $trainee_id  = $sanction['trainee_id'];
                $hours_added = floatval($sanction['hours_added']);

                // Mark sanction as removed
                $stmtUpdate = $pdo->prepare("
                    UPDATE sanctions 
                    SET is_removed = 1, 
                        removed_by = ?, 
                        removal_reason = ?, 
                        removed_at = NOW() 
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$admin_id, $removal_reason, $sanction_id]);

                // Deduct the sanction hours back from trainees table
                $stmtTrainee = $pdo->prepare("
                    UPDATE trainees 
                    SET required_hours = GREATEST(0, required_hours - ?) 
                    WHERE id = ?
                ");
                $stmtTrainee->execute([$hours_added, $trainee_id]);

                $pdo->commit();
                header("Location: sanctions.php?success=removed");
                exit;
            } else {
                $pdo->rollBack();
                header("Location: sanctions.php?error=already_removed");
                exit;
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            die("Error removing sanction: " . $e->getMessage());
        }
    }
}

// 4. Fetch All Sanctions
$query = "
    SELECT 
        s.id AS sanction_id,
        s.trainee_id,
        s.hours_added,
        s.reason,
        s.created_at,
        s.is_removed,
        s.removal_reason,
        s.removed_at,
        t.first_name,
        t.last_name,
        t.department,
        t.username
    FROM sanctions s
    LEFT JOIN trainees t ON s.trainee_id = t.id
    WHERE s.deleted_at IS NULL
    ORDER BY s.is_removed ASC, s.created_at DESC
";

$stmt = $pdo->query($query);
$sanctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

renderHeader('Remove Sanctions');
renderSidebar('sanctions');
?>

<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Remove Sanctions</h1>
            <p class="text-sm text-slate-500">Search and clear trainee disciplinary penalties.</p>
        </div>
        <div class="text-xs bg-purple-50 text-purple-700 font-bold px-3 py-2 rounded-xl border border-purple-100">
            Total Synced Sanctions: <?php echo count($sanctions); ?>
        </div>
    </div>

    <?php if (isset($_GET['success']) && $_GET['success'] === 'removed'): ?>
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold">
            Sanction removed successfully. Penalty hours have been deducted from the student's total required hours.
        </div>
    <?php endif; ?>

    <div class="card p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 class="text-lg font-bold text-slate-800">Active & Synced Sanctions</h2>
            
            <div class="relative w-full sm:w-80">
                <input 
                    type="text" 
                    id="sanctionSearch" 
                    onkeyup="filterSanctions()" 
                    placeholder="Search name, QST ID, or department..." 
                    class="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-purple-600 focus:outline-none text-sm text-slate-700 placeholder-slate-400"
                >
            </div>
        </div>

        <div class="table-scroll">
            <table class="w-full text-left border-collapse" id="sanctionsTable">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-bold uppercase text-slate-400">
                        <th class="py-3 px-4">Trainee Name & QST ID</th>
                        <th class="py-3 px-4">Department</th>
                        <th class="py-3 px-4">Reason</th>
                        <th class="py-3 px-4">Penalty Hours</th>
                        <th class="py-3 px-4">Date Issued</th>
                        <th class="py-3 px-4">Status / Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm">
                    <?php if (empty($sanctions)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-400 font-medium">No sanctions found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sanctions as $s): ?>
                            <?php 
                                $fullName = trim(($s['first_name'] ?? '') . ' ' . ($s['last_name'] ?? ''));
                                if (empty($fullName)) {
                                    $fullName = $s['username'] ?? ('Trainee #' . $s['trainee_id']);
                                }
                                $qstId = 'QST-' . str_pad($s['trainee_id'], 4, '0', STR_PAD_LEFT);
                                $displayName = $fullName . " (ID: " . $qstId . ")";
                            ?>
                            <tr class="sanction-row hover:bg-slate-50 transition">
                                <td class="py-3 px-4 font-semibold text-slate-800 trainee-name"><?php echo htmlspecialchars($displayName); ?></td>
                                <td class="py-3 px-4 text-slate-600 trainee-dept"><?php echo htmlspecialchars($s['department'] ?? 'Unassigned'); ?></td>
                                <td class="py-3 px-4 text-slate-600 sanction-reason"><?php echo htmlspecialchars($s['reason']); ?></td>
                                <td class="py-3 px-4 font-bold text-rose-600">+<?php echo number_format($s['hours_added'], 2); ?> hrs</td>
                                <td class="py-3 px-4 text-slate-500"><?php echo date('M d, Y h:i A', strtotime($s['created_at'])); ?></td>
                                <td class="py-3 px-4">
                                    <?php if (!empty($s['is_removed'])): ?>
                                        <span class="inline-block px-2 py-1 text-xs font-bold bg-slate-100 text-slate-500 rounded-md">Removed</span>
                                    <?php else: ?>
                                        <button 
                                            type="button" 
                                            onclick="openRemoveModal(<?php echo $s['sanction_id']; ?>, '<?php echo htmlspecialchars(addslashes($displayName)); ?>')" 
                                            class="px-3 py-1 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-lg transition"
                                        >
                                            Remove Sanction
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="removeModal" class="fixed inset-0 bg-slate-900/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-xl">
        <h3 class="text-lg font-bold text-slate-800">Confirm Sanction Removal</h3>
        <p class="text-sm text-slate-500">Removing sanction for <strong id="modalTraineeName" class="text-slate-800"></strong>.</p>
        
        <form method="POST" action="sanctions.php">
            <input type="hidden" name="sanction_id" id="modalSanctionId">
            <div class="mb-4">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Reason for Removal</label>
                <textarea name="removal_reason" rows="3" required placeholder="Reason for removing sanction..." class="w-full p-3 border border-slate-200 rounded-xl text-sm"></textarea>
            </div>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeRemoveModal()" class="px-4 py-2 border rounded-xl text-slate-600 text-sm font-semibold">Cancel</button>
                <button type="submit" name="remove_sanction" class="px-4 py-2 bg-rose-600 text-white text-sm font-semibold rounded-xl">Confirm Removal</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterSanctions() {
    const input = document.getElementById('sanctionSearch').value.toLowerCase();
    document.querySelectorAll('.sanction-row').forEach(row => {
        const text = row.innerText.toLowerCase();
        row.style.display = text.includes(input) ? '' : 'none';
    });
}
function openRemoveModal(id, name) {
    document.getElementById('modalSanctionId').value = id;
    document.getElementById('modalTraineeName').innerText = name;
    document.getElementById('removeModal').classList.replace('hidden', 'flex');
}
function closeRemoveModal() {
    document.getElementById('removeModal').classList.replace('flex', 'hidden');
}
</script>

<?php renderFooter(); ?>