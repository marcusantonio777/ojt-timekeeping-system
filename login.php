<?php
require_once 'includes/auth/auth_helper.php';
require_once 'config/db.php';

// Maintenance Mode Check
if (isMaintenanceMode($pdo) && ($_SESSION['role'] ?? '') !== 'superadmin') {
    $maintenance_active = true;
} else {
    $maintenance_active = false;
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'superadmin') header("Location: superadmin/dashboard.php");
    elseif ($_SESSION['role'] === 'admin') header("Location: admin/dashboard.php");
    elseif ($_SESSION['role'] === 'supervisor') header("Location: supervisor/dashboard.php");
    else header("Location: dashboard.php");
    exit();
}

$csrf_token = generateCsrfToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?php echo PROJECT_NAME; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #F8FAFC; }
        .bg-hospitality { 
            background-image: linear-gradient(rgba(109, 48, 137, 0.4), rgba(15, 23, 42, 0.9)), url('https://quest-hotel-conference-center.hotels-cebu-ph.com/data/Pictures/OriginalPhoto/16005/1600536/1600536222/cebu-quest-hotel-conference-center-cebu-picture-29.JPEG');
            background-size: cover;
            background-position: center;
        }
    </style>
</head>
<?php include 'includes/footer.php'; ?>
<body class="h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-[1000px] h-[600px] bg-white rounded-2xl shadow-xl flex overflow-hidden border border-slate-200">
        <!-- Left Side -->
        <div class="hidden md:flex md:w-1/2 bg-hospitality p-10 flex-col justify-between text-white">
            <img src="assets/questhotellogo.png" alt="Quest Hotel" class="w-48 mb-8">
            <div>
                <h2 class="text-3xl font-bold leading-tight mb-3">Student Trainee<br>Timekeeping Portal</h2>
                <p class="text-slate-200 text-xs leading-relaxed">Official platform for trainee attendance and management.</p>
                
            </div>
        </div>

        <!-- Right Side -->
        <div class="w-full md:w-1/2 p-10 flex flex-col justify-center">
            <div class="mb-8 text-center md:text-left">
                <img src="assets/questhotellogo.png" alt="Quest Hotel" class="w-48 mb-8 mx-auto md:hidden">
                <?php $reset_token = isset($_GET['reset']) ? trim($_GET['reset']) : ''; ?>
                <?php if ($reset_token !== ''): ?>
                    <h3 class="text-2xl font-bold text-slate-900">Reset Password</h3>
                    <p class="text-xs text-slate-500 mt-2">Set a new password for your account.</p>
                <?php else: ?>
                    <h3 class="text-2xl font-bold text-slate-900">Sign In</h3>
                    <p class="text-xs text-slate-500 mt-2">Enter your credentials to access the system.</p>
                <?php endif; ?>
            </div>

            <?php if ($reset_token !== ''): ?>
                <form action="auth_process.php" method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($reset_token); ?>">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 tracking-widest">New Password</label>
                        <input type="password" name="new_password" required minlength="8" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-purple-500/20 focus:border-[#6D3089] outline-none transition-all font-medium text-slate-700 text-sm" placeholder="Minimum 8 characters">
                        <p class="text-[9px] text-slate-400 mt-2 leading-relaxed">After reset, you’ll be signed out from other devices.</p>
                    </div>
                    <button type="submit" class="w-full bg-[#6D3089] hover:bg-[#5a2771] text-white font-bold py-3 rounded-lg transition-all shadow-lg shadow-purple-100 text-sm">
                        Set New Password
                    </button>
                </form>
            <?php else: ?>
                <form action="auth_process.php" method="POST" class="space-y-5">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="login">
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 tracking-widest">Username / ID</label>
                        <input type="text" name="username" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-purple-500/20 focus:border-[#6D3089] outline-none transition-all font-medium text-slate-700 text-sm" placeholder="QST0000">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-slate-500 uppercase mb-2 tracking-widest">Password</label>
                        <input type="password" name="password" required class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg focus:ring-2 focus:ring-purple-500/20 focus:border-[#6D3089] outline-none transition-all font-medium text-slate-700 text-sm" placeholder="••••••••">
                        <div class="flex justify-end mt-2">
                            <button type="button" onclick="openResetRequest()" class="text-[10px] font-black text-slate-400 hover:text-[#6D3089] uppercase tracking-widest">Forgot password?</button>
                        </div>
                    </div>
                    <button type="submit" class="w-full bg-[#6D3089] hover:bg-[#5a2771] text-white font-bold py-3 rounded-lg transition-all shadow-lg shadow-purple-100 text-sm">
                        Sign In
                    </button>
                </form>
            <?php endif; ?>

            <div class="mt-8 pt-8 border-t border-slate-100 text-center">
                <a href="index.php" class="text-xs font-bold text-slate-400 hover:text-[#6D3089] uppercase tracking-wider">Back to Kiosk</a>
            </div>
        </div>
        
    </div>
    <?php if (isset($_GET['expired'])): ?>
    <script>
        Swal.fire({ title: 'Session Expired', text: 'Please sign in again.', icon: 'info', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['csrf_error'])): ?>
    <script>
        Swal.fire({ title: 'Security Check Failed', text: 'Please refresh the page and try again. If it keeps happening, allow cookies for this site.', icon: 'warning', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['reset_sent'])): ?>
    <script>
        Swal.fire({ title: 'Reset Link Sent', text: 'If the account exists, a reset link was sent to the email on file.', icon: 'success', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['reset_done'])): ?>
    <script>
        Swal.fire({ title: 'Password Updated', text: 'You can now sign in with your new password.', icon: 'success', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['reset_invalid'])): ?>
    <script>
        Swal.fire({ title: 'Invalid Link', text: 'This reset link is invalid or expired. Request a new one.', icon: 'error', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['reset_error'])): ?>
    <script>
        Swal.fire({ title: 'Reset Failed', text: 'Please try again.', icon: 'error', confirmButtonColor: '#6D3089' });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
    <script>
        <?php if ($_GET['error'] == 'pending'): ?> Swal.fire({ title: 'Pending Review', text: 'Your account is still being verified by HR.', icon: 'info', confirmButtonColor: '#6D3089' });
        <?php elseif ($_GET['error'] == 'maintenance'): ?> Swal.fire({ title: 'System Lockdown', text: 'Access is restricted to God Mode entities only.', icon: 'warning', confirmButtonColor: '#f59e0b' });
        <?php elseif ($_GET['error'] == 'locked'): ?> Swal.fire({ title: 'Too Many Attempts', text: 'Please wait and try again later.', icon: 'warning', confirmButtonColor: '#6D3089' });
        <?php elseif ($_GET['error'] == 'failed' || $_GET['error'] == '1'): ?> Swal.fire({ title: 'Access Denied', text: 'Invalid credentials. Please try again.', icon: 'error', confirmButtonColor: '#6D3089' });
        <?php endif; ?>
    </script>
    <?php endif; ?>

    <script>
        function openResetRequest() {
            Swal.fire({
                title: 'Reset Password',
                html: `
                    <div class="text-left">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Username or Email</label>
                        <input id="reset_identifier" class="swal2-input w-full m-0" placeholder="QST0000 or email">
                        <p class="text-[10px] text-slate-400 font-bold mt-3">We will email a reset link if the account exists.</p>
                    </div>
                `,
                showCancelButton: true,
                confirmButtonText: 'Send Link',
                confirmButtonColor: '#6D3089',
                preConfirm: () => {
                    const v = document.getElementById('reset_identifier').value.trim();
                    if (!v) {
                        Swal.showValidationMessage('Required');
                        return false;
                    }
                    return { identifier: v };
                }
            }).then((res) => {
                if (!res.isConfirmed) return;
                const f = document.createElement('form');
                f.method = 'POST';
                f.action = 'auth_process.php';
                const csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = 'csrf_token';
                csrf.value = <?php echo json_encode($csrf_token); ?>;
                f.appendChild(csrf);

                const a = document.createElement('input');
                a.type = 'hidden';
                a.name = 'action';
                a.value = 'request_reset';
                f.appendChild(a);

                const i = document.createElement('input');
                i.type = 'hidden';
                i.name = 'identifier';
                i.value = res.value.identifier;
                f.appendChild(i);
                document.body.appendChild(f);
                f.submit();
            });
        }
    </script>
    
</body>
</html>
