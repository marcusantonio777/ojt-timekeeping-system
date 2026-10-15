<?php
/**
 * LAYOUT HELPER
 */

function renderHeader($title = 'Quest OJT Portal', $extra_head = '') {
    $project_name = defined('PROJECT_NAME') ? PROJECT_NAME : 'Quest Hotel OJT Portal';
    ?>
    <!DOCTYPE html>
    <html lang="en" class="light">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <link rel="icon" href="favicon.ico" type="image/x-icon">
        
        <title><?php echo $title; ?> | <?php echo $project_name; ?></title>
        <script src="https://cdn.tailwindcss.com"></script>
        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
        <style>
            :root { 
                --sidebar-width: 260px; 
                --accent-primary: #6D3089;
                --accent-gold: #D4AF37;
            }
            body { font-family: 'Inter', sans-serif; background: #F8FAFC; color: #1E293B; }
            img { max-width: 100%; height: auto; }
            
            /* Modern Scrollbar */
            ::-webkit-scrollbar { width: 6px; }
            ::-webkit-scrollbar-track { background: transparent; }
            ::-webkit-scrollbar-thumb { background: #E2E8F0; border-radius: 10px; }

            .sidebar { 
                width: var(--sidebar-width); 
                background: white;
                border-right: 1px solid #E2E8F0;
                flex-shrink: 0;
                display: flex;
                flex-direction: column;
                height: 100vh;
                position: sticky;
                top: 0;
            }

            .sidebar-link { 
                transition: all 0.2s ease; 
                border-radius: 12px; 
                display: flex; 
                align-items: center; 
                padding: 12px 16px;
                color: #64748B;
                font-weight: 600;
                font-size: 14px;
                margin-bottom: 4px;
            }
            .sidebar-link:hover { background: #F1F5F9; color: var(--accent-primary); }
            .sidebar-link.active { background: var(--accent-primary) !important; color: white !important; border-left: 4px solid var(--accent-gold); }
            
            .card { background: white; border: 1px solid #E2E8F0; border-radius: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            
            /* Toast Style */
            .toast-container { position: fixed; top: 24px; right: 24px; z-index: 9999; }
            .toast { padding: 16px 24px; border-radius: 16px; background: white; border: 1px solid #E2E8F0; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); display: flex; align-items: center; gap: 12px; animation: slideIn 0.3s ease-out; }
            @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

            .table-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; }
            .table-scroll > table { min-width: 720px; }

            @media (max-width: 1023px) {
                .sidebar {
                    display: none;
                    position: fixed;
                    top: 0;
                    left: 0;
                    height: 100vh;
                    z-index: 60;
                    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
                }
                .sidebar.open { display: flex; }
            }

            @media (max-width: 640px) {
                .swal2-popup { width: 94vw !important; }
                .flex.justify-between { flex-wrap: wrap; }
            }
        </style>
        <script>
            function showToast(message, type = 'success') {
                const container = document.getElementById('toast-container') || createToastContainer();
                const toast = document.createElement('div');
                toast.className = `toast border-l-4 ${type === 'success' ? 'border-emerald-500' : 'border-rose-500'}`;
                toast.innerHTML = `<span class="text-sm font-bold text-slate-700">${message}</span>`;
                container.appendChild(toast);
                setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 3000);
            }
            function createToastContainer() {
                const div = document.createElement('div');
                div.id = 'toast-container'; div.className = 'toast-container';
                document.body.appendChild(div); return div;
            }
        </script>
        <?php echo $extra_head; ?>
    </head>
    <body class="min-h-screen flex">
    <?php
}

function renderSidebar($active_page = 'dashboard') {
    $role = $_SESSION['role'] ?? 'trainee';
    $name = $_SESSION['full_name'] ?? 'User';
    $dashboardUrl = '/quest_ojt_system/dashboard.php';
    if ($role === 'superadmin') $dashboardUrl = '/quest_ojt_system/superadmin/dashboard.php';
    elseif ($role === 'admin') $dashboardUrl = '/quest_ojt_system/admin/dashboard.php';
    elseif ($role === 'supervisor') $dashboardUrl = '/quest_ojt_system/supervisor/dashboard.php';
    ?>
    <!-- Sidebar -->
    <div id="sidebarOverlay" class="fixed inset-0 bg-black/40 hidden lg:hidden z-50"></div>
    <aside id="appSidebar" class="sidebar">
        <div class="p-8 flex flex-col h-full">
            <!-- Branding -->
            <div class="mb-10 text-center">
                <img src="/quest_ojt_system/assets/questhotellogo.png" alt="Quest Hotel" class="w-40 mx-auto mb-4">
                <div class="h-px bg-slate-100 w-full mb-4"></div>
                <p class="text-[10px] font-black text-slate-400 uppercase tracking-[0.2em]">OJT Management System</p>
            </div>
            
            <nav class="flex-1">
                <a href="<?php echo $dashboardUrl; ?>" class="sidebar-link gap-3 <?php echo $active_page == 'dashboard' ? 'active' : ''; ?>">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    <span>Dashboard</span>
                </a>

                <?php if ($role === 'superadmin'): ?>
                    <a href="/quest_ojt_system/superadmin/users.php" class="sidebar-link gap-3 <?php echo $active_page == 'users' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        <span>User Accounts</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'admin' || $role === 'superadmin'): ?>
                    <a href="/quest_ojt_system/admin/trainees.php" class="sidebar-link gap-3 <?php echo $active_page == 'trainees' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span>Trainee Directory</span>
                    </a>
                    <a href="/quest_ojt_system/admin/requests.php" class="sidebar-link gap-3 <?php echo $active_page == 'requests' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span>Approval Queue</span>
                    </a>
                    <!-- <a href="/quest_ojt_system/admin/sanctions.php" class="sidebar-link gap-3 <?php echo $active_page == 'sanctions' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                        <span>Sanctions Management</span>
                    </a> -->
                    <a href="/quest_ojt_system/messages.php" class="sidebar-link gap-3 <?php echo $active_page == 'messages' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                        <span>Confidential Chat</span>
                    </a>
                    <a href="/quest_ojt_system/admin/settings.php" class="sidebar-link gap-3 <?php echo $active_page == 'settings' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                        <span>Settings</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'supervisor'): ?>
                    <a href="/quest_ojt_system/supervisor/trainees.php" class="sidebar-link gap-3 <?php echo $active_page == 'trainees' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                        <span>My Department</span>
                    </a>
                    <a href="/quest_ojt_system/supervisor/requests.php" class="sidebar-link gap-3 <?php echo $active_page == 'requests' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span>Dept Requests</span>
                    </a>
                <?php endif; ?>

                <?php if ($role === 'trainee'): ?>
                    <a href="/quest_ojt_system/requests.php" class="sidebar-link gap-3 <?php echo $active_page == 'requests' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                        <span>My Requests</span>
                    </a>
                    <a href="/quest_ojt_system/messages.php" class="sidebar-link gap-3 <?php echo $active_page == 'messages' ? 'active' : ''; ?>">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                        <span>Direct HR Chat</span>
                    </a>
                <?php endif; ?>
            </nav>

            <div class="mt-auto pt-8 border-t border-slate-100">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-10 h-10 rounded-full bg-slate-100 text-[#6D3089] flex items-center justify-center font-bold">
                        <?php echo substr($name, 0, 1); ?>
                    </div>
                    <div class="overflow-hidden">
                        <p class="text-sm font-bold text-slate-800 truncate"><?php echo $name; ?></p>
                        <p class="text-[10px] text-slate-400 font-bold uppercase"><?php echo $role; ?></p>
                    </div>
                </div>
                <a href="/quest_ojt_system/logout.php" class="sidebar-link gap-3 text-rose-500 hover:bg-rose-50">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                    <span>Sign Out</span>
                </a>
            </div>
        </div>
    </aside>
    <main class="flex-1 p-4 sm:p-6 lg:p-10 max-h-screen overflow-y-auto">
        <div class="lg:hidden sticky top-0 z-40 -mx-4 sm:-mx-6 px-4 sm:px-6 py-3 mb-6 bg-slate-50/90 backdrop-blur border-b border-slate-200 flex items-center justify-between gap-3">
            <button type="button" id="sidebarOpenBtn" class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white border border-slate-200 text-slate-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M4 6h16M4 12h16M4 18h16"></path></svg>
            </button>
            <div class="flex items-center gap-2 min-w-0">
                <img src="/quest_ojt_system/assets/questhotellogo.png" alt="Quest Hotel" class="h-6">
                <span class="text-[10px] font-black uppercase tracking-widest text-slate-500 truncate">OJT Portal</span>
            </div>
            <a href="/quest_ojt_system/logout.php" class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-white border border-slate-200 text-rose-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
            </a>
        </div>
    <?php
}

function renderFooter() {
    ?>
    </main>
    <script>
        (function () {
            const sidebar = document.getElementById('appSidebar');
            const overlay = document.getElementById('sidebarOverlay');
            const openBtn = document.getElementById('sidebarOpenBtn');

            function openSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.add('open');
                overlay.classList.remove('hidden');
                document.body.classList.add('overflow-hidden');
            }
            function closeSidebar() {
                if (!sidebar || !overlay) return;
                sidebar.classList.remove('open');
                overlay.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }

            if (openBtn) openBtn.addEventListener('click', openSidebar);
            if (overlay) overlay.addEventListener('click', closeSidebar);

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeSidebar();
            });

            const tables = document.querySelectorAll('table');
            tables.forEach((table) => {
                const parent = table.parentElement;
                if (!parent) return;
                if (parent.classList.contains('table-scroll') || parent.classList.contains('overflow-x-auto')) return;
                const wrapper = document.createElement('div');
                wrapper.className = 'table-scroll';
                parent.insertBefore(wrapper, table);
                wrapper.appendChild(table);
            });

            try {
                const b = document.body;
                const flag = 'qojt_queued_flush';
                if (b !== null && !b.dataset[flag]) {
                    b.dataset[flag] = '1';
                    const base = (document.currentScript && document.currentScript.src ? new URL(document.currentScript.src, location.href).origin + new URL(document.currentScript.src, location.href).pathname.split('/').slice(0, -1).join('/') : (location.origin + (location.pathname.lastIndexOf('/') > 0 ? location.pathname.substring(0, location.pathname.lastIndexOf('/')) : ''))) || location.origin;
                    const basePath = (base.replace(location.origin, '')) || '/quest_ojt_system';
                    const url = basePath + '/cron_queue.php?';
                    const seed = encodeURIComponent(String(Date.now()) + '-' + Math.random().toString(36).slice(2, 8));
                    const full = url + '_=' + seed;
                    if (window.fetch && typeof window.fetch === 'function') {
                        window.fetch(full, { method: 'GET', credentials: 'same-origin', keepalive: true, mode: 'no-cors' }).catch(() => {});
                    } else if (typeof Image !== 'undefined') {
                        var _img = new Image();
                        _img.onload = _img.onerror = function () {};
                        _img.src = full;
                    }
                }
            } catch (e) {}
        })();
    </script>
    </body>
    </html>
    <?php
}