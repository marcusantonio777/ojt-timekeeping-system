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

$stmt = $pdo->prepare("SELECT email, phone FROM trainees WHERE id = ? AND deleted_at IS NULL");
$stmt->execute([$user_id]);
$me = $stmt->fetch() ?: ['email' => null, 'phone' => null];

if (isset($_GET['api']) && $_GET['api'] === 'widgets') {
    header('Content-Type: application/json');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND department = ? AND deleted_at IS NULL");
    $stmt->execute([$dept]);
    $total_ojts = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN trainees t ON a.trainee_id = t.id WHERE a.date = CURDATE() AND a.time_out IS NULL AND t.department = ? AND t.deleted_at IS NULL AND a.deleted_at IS NULL");
    $stmt->execute([$dept]);
    $active_now = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE t.department = ? AND r.supervisor_status = 'pending' AND r.deleted_at IS NULL AND t.deleted_at IS NULL");
    $stmt->execute([$dept]);
    $pending_requests = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("
        SELECT a.id, a.date, a.time_in, a.time_out, t.first_name, t.last_name
        FROM attendance a 
        JOIN trainees t ON a.trainee_id = t.id 
        WHERE t.department = ? AND a.time_out IS NOT NULL AND a.verified_at IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL 
        ORDER BY a.date DESC, a.time_out DESC LIMIT 10
    ");
    $stmt->execute([$dept]);
    $pending_verifications = $stmt->fetchAll();

    echo json_encode([
        'department' => $dept,
        'metrics' => [
            'total_ojts' => $total_ojts,
            'active_now' => $active_now,
            'pending_requests' => $pending_requests,
            'pending_verifications' => count($pending_verifications)
        ],
        'pending_verifications_list' => $pending_verifications
    ]);
    exit();
}

// Handle Rating & Announcement
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: dashboard.php?error=csrf_failed");
        exit();
    }
    if ($_POST['action'] === 'verify_log') {
        $log_id = (int)($_POST['log_id'] ?? 0);
        if ($log_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE attendance a
                JOIN trainees t ON a.trainee_id = t.id
                SET a.verified_at = CURRENT_TIMESTAMP, a.verified_by = ?
                WHERE a.id = ? AND t.department = ? AND a.deleted_at IS NULL AND t.deleted_at IS NULL
            ");
            $stmt->execute([$user_id, $log_id, $dept]);
            logAudit($pdo, $user_id, 'VERIFY_LOG', "Verified attendance log ID $log_id");
        }
        header("Location: dashboard.php?status=verified");
        exit();
    }
    if ($_POST['action'] === 'rate') {
        $trainee_id = $_POST['trainee_id'];
        $rating = $_POST['rating'];
        $comment = $_POST['comment'];
        $stmt = $pdo->prepare("UPDATE trainees SET rating = ?, rating_comment = ? WHERE id = ? AND department = ?");
        $stmt->execute([$rating, $comment, $trainee_id, $dept]);
        logAudit($pdo, $user_id, 'RATE_TRAINEE', "Supervisor rated OJT ID $trainee_id with $rating stars");
        header("Location: dashboard.php?status=rated");
        exit();
    }
    elseif ($_POST['action'] === 'announce') {
        $title = trim($_POST['title']);
        $msg = trim($_POST['message']);
        $image_path = handleUploadedImage($_FILES['image'] ?? null, __DIR__ . '/../uploads/announcements', 'ann_');

        $stmt = $pdo->prepare("INSERT INTO system_notifications (title, message, role_target, created_at, image_path) VALUES (?, ?, ?, CURRENT_TIMESTAMP, ?)");
        $full_title = "[$dept] " . $title;
        $stmt->execute([$full_title, $msg, 'trainee', $image_path]);
        logAudit($pdo, $user_id, 'DEPT_ANNOUNCEMENT', "Supervisor posted announcement for $dept with image: " . ($image_path ?: 'none'));
        header("Location: dashboard.php?status=announced");
        exit();
    }
    elseif ($_POST['action'] === 'update_my_account') {
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
        $params[] = $user_id;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        if ($new_password !== '' && isset($newToken)) {
            $_SESSION['session_token'] = $newToken;
            session_regenerate_id(true);
        }
        logAudit($pdo, $user_id, 'ACCOUNT_UPDATE', "Supervisor updated account settings");
        header("Location: dashboard.php?status=account_updated");
        exit();
    }
}

// 1. STATS (DEPARTMENT ONLY)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM trainees WHERE role = 'trainee' AND department = ? AND deleted_at IS NULL");
$stmt->execute([$dept]);
$total_ojts = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN trainees t ON a.trainee_id = t.id WHERE a.date = CURDATE() AND a.time_out IS NULL AND t.department = ? AND t.deleted_at IS NULL AND a.deleted_at IS NULL");
$stmt->execute([$dept]);
$active_now = $stmt->fetchColumn();

// 1.5 PENDING REQUESTS (FOR DEPT)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM requests r JOIN trainees t ON r.trainee_id = t.id WHERE t.department = ? AND r.supervisor_status = 'pending' AND r.deleted_at IS NULL");
$stmt->execute([$dept]);
$pending_requests_count = $stmt->fetchColumn();

// 2. TASK ALERTS: Pending Verification (max 10)
$stmt = $pdo->prepare("
    SELECT a.*, t.first_name, t.last_name 
    FROM attendance a 
    JOIN trainees t ON a.trainee_id = t.id 
    WHERE t.department = ? AND a.time_out IS NOT NULL AND a.verified_at IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL 
    ORDER BY a.date DESC, a.time_out DESC LIMIT 10
");
$stmt->execute([$dept]);
$pending_verifications = $stmt->fetchAll();

// 3. ON DUTY SNAPSHOT (max 10)
$stmt = $pdo->prepare("
    SELECT a.*, t.first_name, t.last_name 
    FROM attendance a 
    JOIN trainees t ON a.trainee_id = t.id 
    WHERE t.department = ? AND a.date = CURDATE() AND a.time_out IS NULL AND t.deleted_at IS NULL AND a.deleted_at IS NULL 
    ORDER BY a.time_in DESC LIMIT 10
");
$stmt->execute([$dept]);
$on_duty = $stmt->fetchAll();

// 4. All Trainees in Dept (for Rating Modal)
$stmt = $pdo->prepare("SELECT * FROM trainees WHERE role = 'trainee' AND department = ? AND deleted_at IS NULL ORDER BY last_name ASC");
$stmt->execute([$dept]);
$dept_trainees = $stmt->fetchAll();

renderHeader('Supervisor Dashboard');
renderSidebar('dashboard');
?>
<?php include '../includes/footer.php'; ?>
<div class="space-y-8">
    <!-- Header -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-slate-900"><?php echo $dept; ?> Control</h1>
            <p class="text-sm text-slate-500 mt-1">Operational overview for your department.</p>
        </div>
        <div class="flex gap-2">
            <button onclick="openAnnounceModal()" class="px-4 py-2 bg-indigo-600 text-white rounded-xl text-xs font-bold uppercase hover:bg-indigo-700 transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                Post Alert
            </button>
            <button onclick="openAccountModal()" class="px-4 py-2 bg-slate-900 text-white rounded-xl text-xs font-bold uppercase hover:bg-slate-700 transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                My Account
            </button>
            <button onclick="openRatingModal()" class="px-4 py-2 bg-[#6D3089] text-white rounded-xl text-xs font-bold uppercase hover:bg-[#5a2771] transition-all flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.382-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"></path></svg>
                Rate OJT
            </button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="card p-6 border-l-4 border-l-[#6D3089]">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Total Dept OJTs</p>
            <h3 class="text-3xl font-bold text-slate-900"><?php echo $total_ojts; ?></h3>
        </div>
        <div class="card p-6 border-l-4 border-l-emerald-500">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">On Duty Now</p>
            <div class="flex items-center gap-3">
                <h3 class="text-3xl font-bold text-emerald-600"><?php echo $active_now; ?></h3>
                <span class="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
            </div>
        </div>
        <div class="card p-6 border-l-4 border-l-amber-500">
            <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest mb-2">Pending Requests</p>
            <h3 class="text-3xl font-bold text-amber-600"><?php echo $pending_requests_count; ?></h3>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
        <!-- Task Alerts: Pending Verification -->
        <div class="card overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h2 class="text-xs font-bold text-slate-600 uppercase tracking-widest">Pending Verification</h2>
                <a href="trainees.php" class="text-[10px] font-bold text-[#6D3089] hover:underline uppercase">View All Logs</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if (empty($pending_verifications)): ?>
                    <div class="p-8 text-center text-slate-400 italic text-xs">All logs verified.</div>
                <?php endif; ?>
                <?php foreach ($pending_verifications as $log): ?>
                <div class="p-4 flex justify-between items-center hover:bg-slate-50/50 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center text-[10px] font-bold uppercase">
                            <?php echo $log['first_name'][0] . $log['last_name'][0]; ?>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></p>
                            <p class="text-[9px] text-slate-400 font-bold uppercase"><?php echo date('M d, Y', strtotime($log['date'])); ?></p>
                        </div>
                    </div>
                    <button onclick="confirmVerification(<?php echo $log['id']; ?>)" class="px-3 py-1 bg-[#6D3089] text-white text-[9px] font-bold uppercase rounded hover:bg-[#5a2771] transition-all shadow-sm">Verify Now</button>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- On Duty Snapshot -->
        <div class="card overflow-hidden">
            <div class="p-5 border-b border-slate-100 flex justify-between items-center bg-slate-50/50">
                <h2 class="text-xs font-bold text-slate-600 uppercase tracking-widest">On Duty (Recent)</h2>
                <a href="trainees.php" class="text-[10px] font-bold text-[#6D3089] hover:underline uppercase">Monitor Dept</a>
            </div>
            <div class="divide-y divide-slate-50">
                <?php if (empty($on_duty)): ?>
                    <div class="p-8 text-center text-slate-400 italic text-xs">No one is currently timed-in.</div>
                <?php endif; ?>
                <?php foreach ($on_duty as $log): ?>
                <div class="p-4 flex justify-between items-center hover:bg-slate-50/50 transition-all">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center text-[10px] font-bold uppercase">
                            <?php echo $log['first_name'][0] . $log['last_name'][0]; ?>
                        </div>
                        <div>
                            <p class="text-xs font-bold text-slate-800"><?php echo htmlspecialchars($log['first_name'] . ' ' . $log['last_name']); ?></p>
                            <p class="text-[9px] text-slate-400 font-bold uppercase">In: <?php echo date('h:i A', strtotime($log['time_in'])); ?></p>
                        </div>
                    </div>
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const TRAINEES = <?php echo json_encode($dept_trainees); ?>;
    const CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;
    const ME_EMAIL = <?php echo json_encode($me['email'] ?? ''); ?>;
    const ME_PHONE = <?php echo json_encode($me['phone'] ?? ''); ?>;

    async function openAccountModal() {
        const { value: formValues } = await Swal.fire({
            title: 'My Account',
            html: `
                <div class="text-left space-y-4">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Email</label>
                        <input id="ac_email" type="email" class="swal2-input w-full m-0 mt-1" value="${ME_EMAIL || ''}">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Phone</label>
                        <input id="ac_phone" class="swal2-input w-full m-0 mt-1" value="${ME_PHONE || ''}">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">New Password (Optional)</label>
                        <input id="ac_pass" type="password" class="swal2-input w-full m-0 mt-1" placeholder="Minimum 8 characters">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'Save',
            preConfirm: () => {
                const email = document.getElementById('ac_email').value.trim();
                const phone = document.getElementById('ac_phone').value.trim();
                const pass = document.getElementById('ac_pass').value;
                if (pass && pass.length < 8) {
                    Swal.showValidationMessage('Password must be at least 8 characters');
                    return false;
                }
                return { email, phone, new_password: pass };
            }
        });
        if (!formValues) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'dashboard.php';
        const csrf = document.createElement('input');
        csrf.type = 'hidden';
        csrf.name = 'csrf_token';
        csrf.value = CSRF_TOKEN;
        form.appendChild(csrf);
        const action = document.createElement('input');
        action.type = 'hidden';
        action.name = 'action';
        action.value = 'update_my_account';
        form.appendChild(action);
        Object.keys(formValues).forEach((k) => {
            const i = document.createElement('input');
            i.type = 'hidden';
            i.name = k;
            i.value = formValues[k];
            form.appendChild(i);
        });
        document.body.appendChild(form);
        form.submit();
    }

    function confirmVerification(id) {
        Swal.fire({
            title: 'Verify Log?',
            text: 'Confirm trainee narrative and hours for this session.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'Yes, verify'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'dashboard.php';
                [['csrf_token', CSRF_TOKEN], ['action', 'verify_log'], ['log_id', String(id)]].forEach(([k, v]) => {
                    const i = document.createElement('input');
                    i.type = 'hidden';
                    i.name = k;
                    i.value = v;
                    form.appendChild(i);
                });
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    async function openAnnounceModal() {
        const { value: formValues } = await Swal.fire({
            title: 'Department Alert',
            html: `
                <div class="text-left space-y-4">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Subject</label>
                        <input id="ann_title" class="swal2-input w-full m-0 mt-1 text-sm" placeholder="e.g. Schedule Change">
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Message</label>
                        <textarea id="ann_msg" class="swal2-textarea w-full m-0 mt-1 text-sm" placeholder="Details for your trainees..."></textarea>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Attach Image (Optional)</label>
                        <input type="file" id="ann_image" class="w-full text-xs text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-[10px] file:font-black file:bg-indigo-50 file:text-indigo-600 hover:file:bg-indigo-100 mt-1" accept="image/*">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#4F46E5',
            confirmButtonText: 'Broadcast',
            preConfirm: () => {
                return {
                    title: document.getElementById('ann_title').value,
                    message: document.getElementById('ann_msg').value,
                    image: document.getElementById('ann_image').files[0]
                }
            }
        });

        if (formValues) {
            const formData = new FormData();
            formData.append('action', 'announce');
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('title', formValues.title);
            formData.append('message', formValues.message);
            if (formValues.image) {
                formData.append('image', formValues.image);
            }

            fetch('dashboard.php', {
                method: 'POST',
                body: formData
            }).then(() => {
                window.location.href = 'dashboard.php?status=announced';
            });
        }
    }

    async function openRatingModal() {
        const { value: formValues } = await Swal.fire({
            title: 'Performance Evaluation',
            html: `
                <div class="text-left space-y-4">
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Select Trainee</label>
                        <select id="trainee_id" class="swal2-input w-full m-0 mt-1 text-sm">
                            ${TRAINEES.map(t => `<option value="${t.id}">${t.first_name} ${t.last_name}</option>`).join('')}
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Rating</label>
                        <select id="rating" class="swal2-input w-full m-0 mt-1 text-sm">
                            <option value="5">5 - Excellent</option>
                            <option value="4">4 - Very Good</option>
                            <option value="3">3 - Satisfactory</option>
                            <option value="2">2 - Needs Improvement</option>
                            <option value="1">1 - Poor</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">Comments</label>
                        <textarea id="comment" class="swal2-textarea w-full m-0 mt-1 text-sm" placeholder="Evaluation feedback..."></textarea>
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonColor: '#6D3089',
            confirmButtonText: 'Submit Rating',
            preConfirm: () => {
                return {
                    trainee_id: document.getElementById('trainee_id').value,
                    rating: document.getElementById('rating').value,
                    comment: document.getElementById('comment').value
                }
            }
        });

        if (formValues) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'dashboard.php';
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'rate';
            form.appendChild(actionInput);
            for (const key in formValues) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = formValues[key];
                form.appendChild(input);
            }
            document.body.appendChild(form);
            form.submit();
        }
    }
</script>

<?php renderFooter(); ?>
