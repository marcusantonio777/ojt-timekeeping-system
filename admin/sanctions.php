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

// Helper function to get readable display name with fallback
function getTraineeDisplayName($row) {
    $name = '';
    if (!empty($row['full_name'])) $name = $row['full_name'];
    elseif (!empty($row['name'])) $name = $row['name'];
    elseif (!empty($row['first_name'])) $name = trim($row['first_name'] . ' ' . ($row['last_name'] ?? ''));
    elseif (!empty($row['username'])) $name = $row['username'];
    elseif (!empty($row['email'])) $name = $row['email'];
    else $name = 'Unknown Trainee';

    // Append Student / User ID tag
    $id = $row['id_number'] ?? $row['student_id'] ?? $row['user_id'] ?? $row['trainee_id'] ?? 'N/A';
    return $name . " (ID: " . $id . ")";
}

// 3. Handle Sanction Removal (POST Request)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_sanction'])) {
    $sanction_id    = intval($_POST['sanction_id']);
    $removal_reason = trim($_POST['removal_reason']);
    $admin_id       = $_SESSION['user_id'] ?? 1;

    if ($sanction_id > 0 && !empty($removal_reason)) {
        $stmt = $pdo->prepare("
            UPDATE sanctions 
            SET is_removed = 1, 
                removed_by = ?, 
                removal_reason = ?, 
                removed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$admin_id, $removal_reason, $sanction_id]);
        header("Location: sanctions.php?success=removed");
        exit;
    }
}

// 4. Fetch ALL Sanctions Data (Left join to capture real user names & departments)
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
        u.id AS user_id,
        u.*
    FROM sanctions s
    LEFT JOIN users u ON s.trainee_id = u.id
    WHERE s.deleted_at IS NULL
    ORDER BY s.is_removed ASC, s.created_at DESC
";

$stmt = $pdo->query($query);
$sanctions = $stmt->fetchAll(PDO::FETCH_ASSOC);

renderHeader('Remove Sanctions');
renderSidebar('sanctions');
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Remove Sanctions</h1>
            <p class="text-sm text-slate-500">Search and manage trainee disciplinary records.</p>
        </div>
        <div class="text-xs bg-purple-50 text-purple-700 font-bold px-3 py-2 rounded-xl border border-purple-100">
            Total Synced Sanctions: <?php echo count($sanctions); ?>
        </div>
    </div>

    <!-- Notifications -->
    <?php if (isset($_GET['success']) && $_GET['success'] === 'removed'): ?>
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-sm font-semibold">
            Sanction has been successfully removed and marked as resolved.
        </div>
    <?php endif; ?>

    <!-- Sanctions List Table -->
    <div class="card p-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <h2 class="text-lg font-bold text-slate-800">Active & Synced Sanctions</h2>
            
            <!-- Live Search Bar -->
            <div class="relative w-full sm:w-80">
                <input 
                    type="text" 
                    id="sanctionSearch" 
                    onkeyup="filterSanctions()" 
                    placeholder="Search by name, ID, or department..." 
                    class="w-full pl-10 pr-4 py-2 border border-slate-200 rounded-xl focus:ring-2 focus:ring-purple-600 focus:outline-none text-sm text-slate-700 placeholder-slate-400"
                >
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-slate-400">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>
        </div>

        <div class="table-scroll">
            <table class="w-full text-left border-collapse" id="sanctionsTable">
                <thead>
                    <tr class="border-b border-slate-200 text-xs font-bold uppercase text-slate-400">
                        <th class="py-3 px-4">Trainee Name & ID</th>
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
                            <td colspan="6" class="py-6 text-center text-slate-400 font-medium">
                                No sanctions found in the database.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sanctions as $s): ?>
                            <tr class="sanction-row hover:bg-slate-50 transition">
                                <td class="py-3 px-4 font-semibold text-slate-800 trainee-name">
                                    <?php echo htmlspecialchars(getTraineeDisplayName($s)); ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 trainee-dept">
                                    <?php 
                                        $dept = $s['department'] ?? $s['dept_name'] ?? $s['dept'] ?? 'Unassigned';
                                        echo htmlspecialchars($dept); 
                                    ?>
                                </td>
                                <td class="py-3 px-4 text-slate-600 sanction-reason">
                                    <?php echo htmlspecialchars($s['reason']); ?>
                                </td>
                                <td class="py-3 px-4 font-bold text-rose-600">
                                    +<?php echo number_format($s['hours_added'], 2); ?> hrs
                                </td>
                                <td class="py-3 px-4 text-slate-500">
                                    <?php echo date('M d, Y h:i A', strtotime($s['created_at'])); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if (!empty($s['is_removed'])): ?>
                                        <div class="flex flex-col">
                                            <span class="inline-block px-2 py-1 text-xs font-bold bg-slate-100 text-slate-500 rounded-md w-max">Removed</span>
                                            <span class="text-xs text-slate-400 mt-1" title="<?php echo htmlspecialchars($s['removal_reason'] ?? ''); ?>">
                                                Reason: <?php echo htmlspecialchars(mb_strimwidth($s['removal_reason'] ?? 'N/A', 0, 20, '...')); ?>
                                            </span>
                                        </div>
                                    <?php else: ?>
                                        <div class="flex items-center gap-2">
                                            <span class="inline-block px-2 py-1 text-xs font-bold bg-rose-100 text-rose-700 rounded-md">Active</span>
                                            <button 
                                                type="button" 
                                                onclick="openRemoveModal(<?php echo $s['sanction_id']; ?>, '<?php echo htmlspecialchars(addslashes(getTraineeDisplayName($s))); ?>')" 
                                                class="px-3 py-1 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-lg transition shadow-sm"
                                            >
                                                Remove Sanction
                                            </button>
                                        </div>
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

<!-- Modal for Removal Reason -->
<div id="removeModal" class="fixed inset-0 bg-slate-900/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-xl">
        <h3 class="text-lg font-bold text-slate-800">Confirm Sanction Removal</h3>
        <p class="text-sm text-slate-500">Removing sanction for <strong id="modalTraineeName" class="text-slate-800"></strong>.</p>
        
        <form method="POST" action="sanctions.php">
            <input type="hidden" name="sanction_id" id="modalSanctionId">
            
            <div class="mb-4">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Reason for Removal</label>
                <textarea name="removal_reason" rows="3" required placeholder="e.g. Completed makeup hours, approved appeal..." class="w-full p-3 border border-slate-200 rounded-xl focus:ring-2 focus:ring-purple-600 focus:outline-none text-sm"></textarea>
            </div>

            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeRemoveModal()" class="px-4 py-2 border border-slate-200 rounded-xl text-slate-600 text-sm font-semibold hover:bg-slate-50 transition">
                    Cancel
                </button>
                <button type="submit" name="remove_sanction" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-sm font-semibold rounded-xl transition">
                    Confirm Removal
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Real-time table filter logic
function filterSanctions() {
    const input = document.getElementById('sanctionSearch').value.toLowerCase();
    const rows = document.querySelectorAll('.sanction-row');

    rows.forEach(row => {
        const name = row.querySelector('.trainee-name')?.innerText.toLowerCase() || '';
        const dept = row.querySelector('.trainee-dept')?.innerText.toLowerCase() || '';
        const reason = row.querySelector('.sanction-reason')?.innerText.toLowerCase() || '';

        if (name.includes(input) || dept.includes(input) || reason.includes(input)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

function openRemoveModal(id, traineeName) {
    document.getElementById('modalSanctionId').value = id;
    document.getElementById('modalTraineeName').innerText = traineeName;
    document.getElementById('removeModal').classList.remove('hidden');
    document.getElementById('removeModal').classList.add('flex');
}

function closeRemoveModal() {
    document.getElementById('removeModal').classList.add('hidden');
    document.getElementById('removeModal').classList.remove('flex');
}
</script>

<?php renderFooter(); ?>