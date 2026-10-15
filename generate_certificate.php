<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';
checkAccess('trainee');

$user_id = $_SESSION['user_id'];

// 1. Fetch Trainee Data
$stmt = $pdo->prepare("SELECT * FROM trainees WHERE id = ?");
$stmt->execute([$user_id]);
$trainee = $stmt->fetch();

// 2. Verify Completion
$stmt = $pdo->prepare("SELECT time_in, time_out FROM attendance WHERE trainee_id = ? AND time_out IS NOT NULL");
$stmt->execute([$user_id]);
$logs = $stmt->fetchAll();

$total_seconds = 0;
foreach ($logs as $log) {
    $total_seconds += (strtotime($log['time_out']) - strtotime($log['time_in']));
}

$total_rendered = ($total_seconds / 3600) + $trainee['initial_hours'];
$required = $trainee['required_hours'];

// Fetch Sanctions to adjust required hours
$stmt = $pdo->prepare("SELECT SUM(hours_added) FROM sanctions WHERE trainee_id = ?");
$stmt->execute([$user_id]);
$sanction_hours = $stmt->fetchColumn() ?: 0;
$final_required = $required + $sanction_hours;

if ($total_rendered < $final_required) {
    die("You have not yet completed the required hours.");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Certificate of Completion - <?php echo $trainee['first_name']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@700&family=Plus+Jakarta+Sans:wght@400;600;800&display=swap" rel="stylesheet">
    <style>
        @media print {
            @page { size: landscape; margin: 0; }
            body { margin: 0; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
        }
        .cert-border { border: 20px solid #6D3089; border-image: linear-gradient(45deg, #6D3089, #9d50bb) 1; }
        .cinzel { font-family: 'Cinzel', serif; }
    </style>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen p-4 md:p-10">

    <div class="no-print fixed top-6 right-6 flex gap-4">
        <button onclick="window.print()" class="bg-[#6D3089] text-white px-8 py-4 rounded-2xl font-black uppercase tracking-widest shadow-xl hover:bg-slate-900 transition-all">Print / Save as PDF</button>
        <a href="dashboard.php" class="bg-white text-slate-400 px-8 py-4 rounded-2xl font-black uppercase tracking-widest border border-slate-200 hover:bg-slate-50 transition-all">Back</a>
    </div>

    <div class="bg-white w-full max-w-5xl aspect-[1.414/1] p-12 relative shadow-2xl overflow-hidden cert-border">
        <!-- Background Decorations -->
        <div class="absolute top-[-10%] left-[-10%] w-[40%] h-[40%] bg-purple-50 rounded-full blur-3xl opacity-50"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[40%] h-[40%] bg-purple-50 rounded-full blur-3xl opacity-50"></div>

        <div class="relative z-10 flex flex-col items-center text-center h-full border-4 border-slate-50 p-10">
            <img src="assets/questhotellogo.png" alt="Quest Hotel" class="h-16 mb-8">
            
            <h1 class="cinzel text-5xl text-slate-800 mb-2 uppercase tracking-[0.1em]">Certificate</h1>
            <p class="text-xs font-black text-purple-500 uppercase tracking-[0.5em] mb-12">of Completion</p>

            <p class="text-slate-400 font-medium uppercase tracking-widest text-sm mb-4">This is to certify that</p>
            <h2 class="cinzel text-6xl text-[#6D3089] mb-8"><?php echo htmlspecialchars($trainee['first_name'] . ' ' . $trainee['last_name']); ?></h2>
            
            <p class="max-w-2xl text-slate-600 leading-relaxed font-medium">
                Has successfully completed the <span class="font-black text-slate-800"><?php echo $final_required; ?>-hour</span> 
                On-the-Job Training program in the <span class="font-black text-slate-800 uppercase"><?php echo $trainee['department']; ?> Department</span> 
                at Quest Hotel & Conference Center Cebu, rendered from 
                <span class="font-black text-slate-800"><?php echo date('F d, Y', strtotime($trainee['created_at'])); ?></span> to 
                <span class="font-black text-slate-800"><?php echo date('F d, Y'); ?></span>.
            </p>

            <div class="mt-auto grid grid-cols-3 w-full items-end">
                <div class="flex flex-col items-center">
                    <div class="w-48 h-px bg-slate-300 mb-2"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">School Supervisor</p>
                    <p class="text-[9px] text-slate-300 font-bold uppercase mt-1"><?php echo htmlspecialchars($trainee['school']); ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="bg-slate-50 p-4 rounded-2xl border border-slate-100 mb-2">
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=VERIFIED-<?php echo $trainee['id']; ?>-<?php echo time(); ?>" class="w-16 h-16 grayscale opacity-50">
                    </div>
                    <p class="text-[8px] font-black text-slate-300 uppercase tracking-widest">ID: QUEST-OJT-<?php echo str_pad($trainee['id'], 4, '0', STR_PAD_LEFT); ?></p>
                </div>
                <div class="flex flex-col items-center">
                    <div class="w-48 h-px bg-slate-300 mb-2"></div>
                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">HR Manager</p>
                    <p class="text-[9px] text-slate-300 font-bold uppercase mt-1">Quest Hotel Cebu</p>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
