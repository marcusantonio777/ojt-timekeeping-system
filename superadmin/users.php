<?php
require_once '../config/db.php';
require_once '../includes/auth/auth_helper.php';
require_once '../includes/layouts/main_layout.php';

checkAccess('superadmin');

// Pagination & Search Settings
$limit = 15;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $limit;

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? $_GET['role'] : '';
$dept_filter = isset($_GET['dept']) ? $_GET['dept'] : '';

// Build Query
$query = "SELECT * FROM trainees WHERE deleted_at IS NULL";
$params = [];

if ($search) {
    $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param]);
}

if ($role_filter) {
    $query .= " AND role = ?";
    $params[] = $role_filter;
}

if ($dept_filter) {
    $query .= " AND department = ?";
    $params[] = $dept_filter;
}

// Get total count for pagination
$count_query = str_replace("*", "COUNT(*)", $query);
$stmt_count = $pdo->prepare($count_query);
$stmt_count->execute($params);
$total_records = $stmt_count->fetchColumn();
$total_pages = ceil($total_records / $limit);

$query .= " ORDER BY role ASC, first_name ASC LIMIT $limit OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        header("Location: users.php?error=csrf_failed");
        exit();
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $username = trim($_POST['username']);
        $email = trim($_POST['email']);
        $password = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        $role = $_POST['role'];
        $dept = $_POST['department'];
        
        try {
            $stmt = $pdo->prepare("INSERT INTO trainees (first_name, last_name, username, email, password, role, department, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([$first_name, $last_name, $username, $email, $password, $role, $dept]);
            logAudit($pdo, $_SESSION['user_id'], 'ADD_USER', "Created user: $username");
            header("Location: users.php?status=user_added");
        } catch (PDOException $e) {
            header("Location: users.php?error=exists");
        }
        exit();
    }

    if ($action === 'modify_user') {
        $id = $_POST['user_id'];
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $role = $_POST['role'];
        $dept = $_POST['department'];
        $status = $_POST['status'] ?? 'active';
        
        $sql = "UPDATE trainees SET first_name = ?, last_name = ?, role = ?, department = ?, status = ?";
        $params = [$first_name, $last_name, $role, $dept, $status];
        
        if (!empty($_POST['password'])) {
            $sql .= ", password = ?";
            $params[] = password_hash(trim($_POST['password']), PASSWORD_DEFAULT);
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        logAudit($pdo, $_SESSION['user_id'], 'MODIFY_USER', "Updated user ID: $id");
        header("Location: users.php?status=user_modified");
        exit();
    }

    if ($action === 'delete_user') {
        $id = $_POST['user_id'];
        $stmt = $pdo->prepare("UPDATE trainees SET deleted_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$id]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_USER', "Soft-deleted user ID: $id");
        header("Location: users.php?status=user_deleted");
        exit();
    }

    if ($action === 'ghost_in') {
        $target_id = $_POST['user_id'];
        if (impersonate($target_id, $pdo)) {
            $role = $_SESSION['role'];
            if ($role === 'superadmin') header("Location: dashboard.php");
            elseif ($role === 'admin') header("Location: ../admin/dashboard.php");
            elseif ($role === 'supervisor') header("Location: ../supervisor/dashboard.php");
            else header("Location: ../dashboard.php");
            exit();
        }
    }
}

$csrf_token = generateCsrfToken();
renderHeader('User Management');
renderSidebar('users');
?>

<div class="space-y-8">
    <div class="flex justify-between items-end gap-6">
        <div class="flex-1 flex gap-4 items-end">
            <div class="flex-1 max-w-sm">
                <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Live Search</label>
                <div class="relative group">
                    <input type="text" id="userSearch" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name, ID, or Email..." class="w-full pl-10 pr-4 py-3 bg-white border border-slate-100 rounded-2xl text-[11px] font-bold text-slate-600 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] transition-all group-hover:border-slate-200 outline-none">
                    <svg class="w-4 h-4 absolute left-4 top-3.5 text-slate-300 group-hover:text-slate-400 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                </div>
            </div>
            <div class="w-40">
                <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">System Role</label>
                <select onchange="updateFilters('role', this.value)" class="w-full px-4 py-3 bg-white border border-slate-100 rounded-2xl text-[10px] font-bold text-slate-600 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] outline-none transition-all">
                    <option value="">All Roles</option>
                    <option value="superadmin" <?php echo $role_filter === 'superadmin' ? 'selected' : ''; ?>>Superadmin</option>
                    <option value="admin" <?php echo $role_filter === 'admin' ? 'selected' : ''; ?>>HR Admin</option>
                    <option value="supervisor" <?php echo $role_filter === 'supervisor' ? 'selected' : ''; ?>>Supervisor</option>
                    <option value="trainee" <?php echo $role_filter === 'trainee' ? 'selected' : ''; ?>>Trainee</option>
                </select>
            </div>
            <div class="w-48">
                <label class="block text-[8px] font-black text-slate-400 uppercase tracking-widest mb-2 ml-1">Department</label>
                <select onchange="updateFilters('dept', this.value)" class="w-full px-4 py-3 bg-white border border-slate-100 rounded-2xl text-[10px] font-bold text-slate-600 focus:ring-2 focus:ring-[#6D3089] focus:border-[#6D3089] outline-none transition-all">
                    <option value="">All Departments</option>
                    <?php foreach ($DEPARTMENTS as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo $dept_filter === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button onclick="openAddUserModal()" class="px-8 py-4 bg-[#6D3089] text-white rounded-2xl font-black text-[9px] uppercase tracking-widest shadow-lg shadow-purple-500/20 hover:scale-105 transition-all mb-1">Add New User</button>
    </div>

    <div class="bg-white rounded-[2rem] border border-slate-100 overflow-hidden shadow-sm">
        <table class="w-full text-left">
            <thead>
                <tr class="bg-slate-50 text-[9px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                    <th class="px-8 py-5">User Account</th>
                    <th class="px-8 py-5">System Role</th>
                    <th class="px-8 py-5">Department</th>
                    <th class="px-8 py-5">Status</th>
                    <th class="px-8 py-5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($users as $u): ?>
                <tr class="hover:bg-slate-50/50 transition-all group">
                    <td class="px-8 py-5">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-2xl bg-slate-100 flex items-center justify-center text-[11px] font-black text-[#6D3089]">
                                <?php echo substr($u['first_name'], 0, 1); ?>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-slate-700"><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name']); ?></p>
                                <p class="text-[9px] text-slate-400 font-black uppercase tracking-widest mt-0.5">@<?php echo $u['username']; ?></p>
                            </div>
                        </div>
                    </td>
                    <td class="px-8 py-5">
                        <span class="px-4 py-1.5 rounded-full text-[8px] font-black uppercase tracking-widest <?php echo $u['role'] === 'superadmin' ? 'bg-amber-100 text-amber-600' : ($u['role'] === 'admin' ? 'bg-purple-100 text-[#6D3089]' : ($u['role'] === 'supervisor' ? 'bg-blue-100 text-blue-600' : 'bg-slate-100 text-slate-500')); ?>">
                            <?php echo $u['role']; ?>
                        </span>
                    </td>
                    <td class="px-8 py-5 text-[10px] font-bold text-slate-500 uppercase"><?php echo $u['department']; ?></td>
                    <td class="px-8 py-5">
                        <span class="text-[10px] font-black uppercase tracking-widest <?php echo $u['status'] === 'active' ? 'text-emerald-500' : 'text-amber-500'; ?>">
                            ● <?php echo $u['status']; ?>
                        </span>
                    </td>
                    <td class="px-8 py-5 text-right">
                        <div class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-all">
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <form method="POST" class="inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="action" value="ghost_in">
                                <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                <button type="submit" class="p-2.5 bg-emerald-50 text-emerald-600 rounded-xl hover:bg-emerald-600 hover:text-white transition-all" title="View as User">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                            <button onclick="openModifyUserModal(<?php echo htmlspecialchars(json_encode($u)); ?>)" class="p-2.5 bg-indigo-50 text-[#6D3089] rounded-xl hover:bg-[#6D3089] hover:text-white transition-all" title="Modify User">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                            </button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <button onclick="confirmDeleteUser(<?php echo $u['id']; ?>)" class="p-2.5 bg-rose-50 text-rose-600 rounded-xl hover:bg-rose-600 hover:text-white transition-all" title="Delete User">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($users)): ?>
                <tr>
                    <td colspan="5" class="px-8 py-20 text-center">
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">No users matching filters</p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="px-8 py-6 bg-slate-50 border-t border-slate-100 flex items-center justify-between">
            <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest">Showing <?php echo $offset + 1; ?> - <?php echo min($offset + $limit, $total_records); ?> of <?php echo $total_records; ?> Users</p>
            <div class="flex gap-2">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $role_filter ? '&role='.$role_filter : ''; ?><?php echo $dept_filter ? '&dept='.$dept_filter : ''; ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-[9px] font-black text-slate-600 hover:bg-slate-100 transition-all uppercase tracking-widest">Prev</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <a href="?page=<?php echo $i; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $role_filter ? '&role='.$role_filter : ''; ?><?php echo $dept_filter ? '&dept='.$dept_filter : ''; ?>" class="px-4 py-2 <?php echo $i == $page ? 'bg-[#6D3089] text-white' : 'bg-white text-slate-600 border border-slate-200 hover:bg-slate-100'; ?> rounded-xl text-[9px] font-black transition-all"><?php echo $i; ?></a>
                <?php endfor; ?>

                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search='.urlencode($search) : ''; ?><?php echo $role_filter ? '&role='.$role_filter : ''; ?><?php echo $dept_filter ? '&dept='.$dept_filter : ''; ?>" class="px-4 py-2 bg-white border border-slate-200 rounded-xl text-[9px] font-black text-slate-600 hover:bg-slate-100 transition-all uppercase tracking-widest">Next</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
    const DEPARTMENTS = <?php echo json_encode($DEPARTMENTS); ?>;

    // Filter Logic
    let searchTimer;
    const searchInput = document.getElementById('userSearch');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                updateFilters('search', e.target.value);
            }, 500);
        });
        if (searchInput.value) {
            searchInput.focus();
            searchInput.setSelectionRange(searchInput.value.length, searchInput.value.length);
        }
    }

    function updateFilters(key, value) {
        const url = new URL(window.location.href);
        if (value) url.searchParams.set(key, value);
        else url.searchParams.delete(key);
        url.searchParams.set('page', 1); // Reset to page 1 on filter
        window.location.href = url.href;
    }

    async function openAddUserModal() {
        const { value: formValues } = await Swal.fire({
            title: 'Add New User',
            html: `
                <div class="text-left space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">First Name</label>
                            <input id="nf_name" class="swal2-input w-full m-0 mt-1">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">Last Name</label>
                            <input id="nl_name" class="swal2-input w-full m-0 mt-1">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">Username</label>
                            <input id="nu_name" class="swal2-input w-full m-0 mt-1">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">Email</label>
                            <input id="n_email" type="email" class="swal2-input w-full m-0 mt-1">
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">User Role</label>
                        <select id="n_role" class="swal2-input w-full m-0 mt-1">
                            <option value="superadmin">System Admin</option>
                            <option value="admin">HR Admin</option>
                            <option value="supervisor">Supervisor</option>
                            <option value="trainee">Trainee</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">Department</label>
                        <select id="n_dept" class="swal2-input w-full m-0 mt-1">
                            ${DEPARTMENTS.map(d => `<option value="${d}">${d}</option>`).join('')}
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">Password</label>
                        <input id="n_pass" type="password" class="swal2-input w-full m-0 mt-1" placeholder="••••••••">
                    </div>
                </div>
            `,
            confirmButtonText: 'CREATE ACCOUNT',
            confirmButtonColor: '#6D3089',
            preConfirm: () => {
                return {
                    first_name: document.getElementById('nf_name').value,
                    last_name: document.getElementById('nl_name').value,
                    username: document.getElementById('nu_name').value,
                    email: document.getElementById('n_email').value,
                    role: document.getElementById('n_role').value,
                    department: document.getElementById('n_dept').value,
                    password: document.getElementById('n_pass').value
                }
            }
        });

        if (formValues) {
            const form = document.createElement('form');
            form.method = 'POST';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'add_user';
            form.appendChild(actionInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo $csrf_token; ?>';
            form.appendChild(csrfInput);

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

    async function openModifyUserModal(u) {
        const { value: formValues } = await Swal.fire({
            title: 'Modify User Settings',
            html: `
                <div class="text-left space-y-4">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">First Name</label>
                            <input id="f_name" class="swal2-input w-full m-0 mt-1" value="${u.first_name}">
                        </div>
                        <div>
                            <label class="text-[10px] font-black uppercase text-slate-500">Last Name</label>
                            <input id="l_name" class="swal2-input w-full m-0 mt-1" value="${u.last_name}">
                        </div>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">User Role</label>
                        <select id="role" class="swal2-input w-full m-0 mt-1">
                            <option value="superadmin" ${u.role === 'superadmin' ? 'selected' : ''}>System Admin</option>
                            <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>HR Admin</option>
                            <option value="supervisor" ${u.role === 'supervisor' ? 'selected' : ''}>Supervisor</option>
                            <option value="trainee" ${u.role === 'trainee' ? 'selected' : ''}>Trainee</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">Department</label>
                        <select id="dept" class="swal2-input w-full m-0 mt-1">
                            ${DEPARTMENTS.map(d => `<option value="${d}" ${d === u.department ? 'selected' : ''}>${d}</option>`).join('')}
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">Account Status</label>
                        <select id="status" class="swal2-input w-full m-0 mt-1">
                            <option value="active" ${u.status === 'active' ? 'selected' : ''}>Active</option>
                            <option value="pending" ${u.status === 'pending' ? 'selected' : ''}>Pending Approval</option>
                            <option value="suspended" ${u.status === 'suspended' ? 'selected' : ''}>Suspended</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-black uppercase text-slate-500">New Password (Empty to keep)</label>
                        <input id="pass" type="password" class="swal2-input w-full m-0 mt-1" placeholder="••••••••">
                    </div>
                </div>
            `,
            confirmButtonText: 'UPDATE ACCOUNT',
            confirmButtonColor: '#6D3089',
            preConfirm: () => {
                return {
                    user_id: u.id,
                    first_name: document.getElementById('f_name').value,
                    last_name: document.getElementById('l_name').value,
                    role: document.getElementById('role').value,
                    department: document.getElementById('dept').value,
                    status: document.getElementById('status').value,
                    password: document.getElementById('pass').value
                }
            }
        });

        if (formValues) {
            const form = document.createElement('form');
            form.method = 'POST';
            
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'modify_user';
            form.appendChild(actionInput);

            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = '<?php echo $csrf_token; ?>';
            form.appendChild(csrfInput);

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

    function confirmDeleteUser(id) {
        Swal.fire({
            title: 'Delete User Account?',
            text: "This action will soft-delete the user from the system.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            confirmButtonText: 'YES, DELETE'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'delete_user';
                form.appendChild(actionInput);

                const csrfInput = document.createElement('input');
                csrfInput.type = 'hidden';
                csrfInput.name = 'csrf_token';
                csrfInput.value = '<?php echo $csrf_token; ?>';
                form.appendChild(csrfInput);

                const idInput = document.createElement('input');
                idInput.type = 'hidden';
                idInput.name = 'user_id';
                idInput.value = id;
                form.appendChild(idInput);

                document.body.appendChild(form);
                form.submit();
            }
        });
    }
</script>

<?php renderFooter(); ?>
