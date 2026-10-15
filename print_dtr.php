<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';

checkAccess('trainee, supervisor, admin, superadmin');

$role = $_SESSION['role'] ?? '';
$viewer_id = (int)($_SESSION['user_id'] ?? 0);

$id = (int)($_GET['id'] ?? 0);
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
if ($id <= 0) die("Invalid trainee.");
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2000 || $year > 2100) $year = (int)date('Y');

// Security check
if ($role === 'trainee' && $viewer_id !== $id) die("Denied");
if ($role === 'supervisor') {
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$viewer_id]);
    $supervisor_dept = $stmt->fetchColumn();
    $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ? AND deleted_at IS NULL");
    $stmt->execute([$id]);
    $trainee_dept = $stmt->fetchColumn();
    if (!$supervisor_dept || !$trainee_dept || $supervisor_dept !== $trainee_dept) die("Denied");
}

$stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) die("Trainee not found.");

$stmt = $pdo->prepare("SELECT * FROM attendance WHERE trainee_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND deleted_at IS NULL ORDER BY date ASC");
$stmt->execute([$id, $month, $year]);
$logs = [];
foreach ($stmt->fetchAll() as $log) {
    $logs[$log['date']] = $log;
}

$stmt = $pdo->prepare("SELECT * FROM schedules WHERE trainee_id = ? AND MONTH(date) = ? AND YEAR(date) = ? AND deleted_at IS NULL");
$stmt->execute([$id, $month, $year]);
$schedules = [];
foreach ($stmt->fetchAll() as $sched) {
    $schedules[$sched['date']] = $sched;
}

$stmt = $pdo->prepare("SELECT date, name, type FROM holidays WHERE MONTH(date) = ? AND YEAR(date) = ?");
$stmt->execute([$month, $year]);
$holidays = [];
foreach ($stmt->fetchAll() as $h) {
    $holidays[$h['date']] = $h;
}

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DTR Print - <?php echo $user['last_name']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print {
            .no-print { display: none; }
            body { background: white; }
            @page { margin: 1cm; }
        }
        body { font-family: 'Times New Roman', serif; }
    </style>
</head>
<body class="bg-slate-50 p-8">
    <div class="max-w-4xl mx-auto bg-white p-12 shadow-sm border border-slate-100">
        <div class="flex justify-between items-start mb-10 border-b-2 border-slate-900 pb-6">
            <div>
                <img src="assets/questhotellogo.png" class="w-40 mb-4">
                <h1 class="text-2xl font-bold uppercase">Daily Time Record</h1>
                <p class="text-sm text-slate-500">Official OJT Attendance Summary</p>
            </div>
            <div class="text-right">
                <p class="font-bold text-lg"><?php echo strtoupper($user['first_name'] . ' ' . $user['last_name']); ?></p>
                <p class="text-sm"><?php echo $user['department']; ?> Department</p>
                <p class="text-sm"><?php echo $user['school']; ?></p>
                <p class="text-xs mt-2 text-slate-400">Period: <?php echo date('F Y', mktime(0,0,0,$month,1,$year)); ?></p>
            </div>
        </div>

        <table class="w-full border-collapse border border-slate-900 text-sm">
            <thead>
                <tr class="bg-slate-100">
                    <th class="border border-slate-900 p-2">Date</th>
                    <th class="border border-slate-900 p-2">Time In</th>
                    <th class="border border-slate-900 p-2">Time Out</th>
                    <th class="border border-slate-900 p-2">Hours</th>
                    <th class="border border-slate-900 p-2">Narrative / Task Done</th>
                    <th class="border border-slate-900 p-2">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_seconds = 0;
                for ($d = 1; $d <= $daysInMonth; $d++): 
                    $currentDate = sprintf('%04d-%02d-%02d', $year, $month, $d);
                    $log = $logs[$currentDate] ?? null;
                    $sched = $schedules[$currentDate] ?? null;
                    $holiday = $holidays[$currentDate] ?? null;
                    
                    if (!$log && !$sched && !$holiday) continue; // Skip days with no activity/schedule/holiday

                    $diff = ($log && $log['time_out']) ? strtotime($log['time_out']) - strtotime($log['time_in']) : 0;
                    $total_seconds += $diff;
                ?>
                <tr>
                    <td class="border border-slate-900 p-2 text-center"><?php echo date('M d, Y', strtotime($currentDate)); ?></td>
                    <td class="border border-slate-900 p-2 text-center font-bold">
                        <?php 
                        if ($log) echo date('h:i A', strtotime($log['time_in']));
                        else if ($sched && $sched['status'] === 'day_off') echo '<span class="text-rose-600">OFF</span>';
                        else if ($holiday) echo '<span class="text-indigo-600">HOL</span>';
                        else echo '--:--';
                        ?>
                    </td>
                    <td class="border border-slate-900 p-2 text-center font-bold">
                        <?php 
                        if ($log && $log['time_out']) echo date('h:i A', strtotime($log['time_out']));
                        else if ($sched && $sched['status'] === 'day_off') echo '<span class="text-rose-600">OFF</span>';
                        else if ($holiday) echo '<span class="text-indigo-600">HOL</span>';
                        else echo '--:--';
                        ?>
                    </td>
                    <td class="border border-slate-900 p-2 text-center"><?php echo ($log && $log['time_out']) ? number_format($diff/3600, 1) . 'h' : '-'; ?></td>
                    <td class="border border-slate-900 p-2 text-xs">
                        <?php 
                        if ($log) echo htmlspecialchars($log['narrative']);
                        else if ($sched && $sched['status'] === 'day_off') echo '<span class="italic text-slate-400">Scheduled Day Off</span>';
                        else if ($holiday) echo '<span class="italic text-slate-500">' . htmlspecialchars($holiday['name']) . '</span>';
                        ?>
                    </td>
                    <td class="border border-slate-900 p-2 text-center text-[10px] font-bold">
                        <?php 
                        if ($log) echo $log['verified_at'] ? 'VERIFIED' : 'PENDING';
                        else if ($sched && $sched['status'] === 'day_off') echo 'OFF';
                        else if ($holiday) echo 'HOLIDAY';
                        ?>
                    </td>
                </tr>
                <?php endfor; ?>
            </tbody>
            <tfoot>
                <tr class="bg-slate-50 font-bold">
                    <td colspan="3" class="border border-slate-900 p-3 text-right uppercase">Total Rendered Hours</td>
                    <td class="border border-slate-900 p-3 text-center"><?php echo number_format($total_seconds/3600, 1); ?> Hrs</td>
                    <td colspan="2" class="border border-slate-900 p-3"></td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-16 grid grid-cols-2 gap-20">
            <div class="text-center">
                <div class="border-b border-slate-900 mb-2 h-10"></div>
                <p class="text-sm font-bold uppercase"><?php echo $user['first_name'] . ' ' . $user['last_name']; ?></p>
                <p class="text-xs text-slate-500">Trainee Signature</p>
            </div>
            <div class="text-center">
                <div class="border-b border-slate-900 mb-2 h-10"></div>
                <p class="text-sm font-bold uppercase italic">Department Supervisor</p>
                <p class="text-xs text-slate-500">Verified and Approved By</p>
            </div>
        </div>

        <div class="mt-20 pt-10 border-t border-slate-100 text-center text-[10px] text-slate-400 italic">
            This document is electronically generated by the Quest Hotel OJT Attendance System on <?php echo date('Y-m-d H:i:s'); ?>.
        </div>
    </div>

    <div class="fixed bottom-8 right-8 flex gap-4 no-print">
        <button onclick="window.history.back()" class="bg-slate-800 text-white px-6 py-3 rounded-xl font-bold shadow-lg">Back</button>
        <button onclick="window.print()" class="bg-[#6D3089] text-white px-8 py-3 rounded-xl font-bold shadow-lg hover:scale-105 transition-all">Print Report</button>
    </div>
</body>
</html>
