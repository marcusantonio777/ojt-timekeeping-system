<?php
require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';

// Maintenance Mode Check
if (isMaintenanceMode($pdo) && ($_SESSION['role'] ?? '') !== 'superadmin') {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Maintenance Mode</title><script src='https://cdn.tailwindcss.com'></script></head><body class='bg-[#0F172A] h-screen flex items-center justify-center text-center p-6'><div class='max-w-md'><div class='w-20 h-20 bg-amber-500/10 text-amber-500 rounded-3xl flex items-center justify-center mx-auto mb-8'><svg class='w-10 h-10' fill='none' stroke='currentColor' viewBox='0 0 24 24'><path stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'></path></svg></div><h1 class='text-3xl font-black text-white uppercase tracking-tighter mb-4'>System Maintenance</h1><p class='text-slate-400 text-sm leading-relaxed mb-8'>The Quest Hotel OJT Portal is currently undergoing scheduled maintenance. Please check back later.</p><a href='login.php' class='text-[10px] font-black text-slate-500 uppercase tracking-widest hover:text-white transition-colors'>Admin Login</a></div></body></html>");
}

$csrf_token = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Attendance Kiosk | <?php echo PROJECT_NAME; ?></title>
    <link rel="apple-touch-icon" href="assets/questhotellogo.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/webcamjs/1.0.26/webcam.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <meta http-equiv="refresh" content="900">
    <style>
        /* Ambient Deep Space Environment Container */
        body { 
            font-family: 'Inter', sans-serif; 
            background-color: #03050c; 
            background-image: 
                radial-gradient(circle at 35% 25%, rgba(109, 48, 137, 0.25) 0%, rgba(3, 7, 18, 0) 60%),
                radial-gradient(circle at 75% 75%, rgba(220, 38, 38, 0.15) 0%, rgba(3, 7, 18, 0) 50%);
            height: 100vh; 
            margin: 0; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
            overflow: hidden; 
            position: relative;
        }

        /* Animated HTML5 Wave Canvas Scaling */
        #bg-canvas {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: none;
        }

        /* Architectural Floating Grid Mesh Accent */
        body::before {
            content: "";
            position: absolute;
            bottom: 3rem;
            right: 4rem;
            width: 140px;
            height: 40px;
            background-image: radial-gradient(rgba(255, 255, 255, 0.15) 1px, transparent 1px);
            background-size: 10px 10px;
            opacity: 0.6;
            pointer-events: none;
            z-index: 2;
        }
        
        /* High-Intensity Multi-Layer Neon Back-light Mapping */
        .kiosk-card { 
            background: white; 
            width: 95vw; 
            max-width: 950px; 
            height: 85vh; 
            max-height: 550px;
            display: grid; 
            grid-template-columns: 42% 58%; 
            border-radius: 1.25rem; 
            overflow: hidden; 
            border: 1px solid rgba(255, 255, 255, 0.8);
            box-shadow: 
                0 0 25px 5px rgba(109, 48, 137, 0.4),    /* Tight volcanic magenta core */
                0 0 60px 20px rgba(147, 51, 234, 0.25),  /* Medium atmospheric violet layer */
                0 0 120px 45px rgba(219, 39, 119, 0.15), /* Outer global environment glow */
                0 35px 70px -15px rgba(0, 0, 0, 0.7);    /* Dropped core shadow anchoring */
            position: relative;
            z-index: 10;
        }

        .side-panel { 
            padding: 2rem; 
            display: flex; 
            flex-direction: column; 
            background: white; 
            justify-content: space-between;
        }
        
        .camera-panel { 
            background: #090d16; 
            position: relative; 
            overflow: hidden; 
            display: flex; 
            align-items: center; 
            justify-content: center; 
        }
        
        #my_camera { width: 100% !important; height: 100% !important; }
        #my_camera video { width: 100% !important; height: 100% !important; object-fit: cover; transform: scaleX(-1); }

        .id-input { 
            font-size: 1.5rem; 
            font-weight: 800; 
            text-align: center; 
            border: 2px solid #F1F5F9; 
            background: #F8FAFC; 
            border-radius: 0.75rem; 
            padding: 0.75rem; 
            width: 100%; 
            transition: all 0.3s; 
            color: #1E293B;
            letter-spacing: 0.05em;
        }
        .id-input:focus { border-color: #6D3089; background: white; outline: none; box-shadow: 0 0 0 4px rgba(109, 48, 137, 0.05); }

        .btn-attendance { 
            display: flex; 
            flex-direction: column; 
            align-items: center; 
            justify-content: center; 
            gap: 0.4rem; 
            padding: 1.25rem; 
            border-radius: 1rem; 
            font-weight: 800; 
            text-transform: uppercase; 
            font-size: 0.7rem; 
            transition: all 0.3s; 
            color: white;
            border: none;
            cursor: pointer;
            width: 100%;
        }
        .btn-attendance svg { width: 1.75rem; height: 1.75rem; }
        .btn-attendance:hover { transform: translateY(-2px); box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1); }
        .btn-attendance:active { transform: translateY(0); }
        
        .btn-in { background: #10B981; }
        .btn-in:hover { background: #059669; }
        .btn-out { background: #F43F5E; }
        .btn-out:hover { background: #E11D48; }

        /* Biometric Scanning Badge */
        .face-scan-badge {
            position: absolute;
            top: 1.5rem;
            left: 1.5rem;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(8px);
            padding: 0.6rem 1rem;
            border-radius: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            z-index: 10;
        }

        @media (max-width: 768px) {
            .kiosk-card { grid-template-columns: 1fr; max-width: 400px; height: auto; max-height: none; }
            .camera-panel { height: 250px; order: -1; }
            .side-panel { padding: 1.5rem; }
            body { overflow-y: auto; padding: 1rem; }
            body::before { display: none; }
        }
    </style>
</head>
<?php include 'includes/footer.php'; ?>
<body>

    <canvas id="bg-canvas"></canvas>

    <div class="kiosk-card">
        <div class="side-panel">
            <div class="text-center">
                <img src="assets/questhotellogo.png" alt="Quest Hotel" class="w-40 mx-auto mb-4 object-contain">
                <div class="flex items-center justify-center gap-3">
                    <div class="h-[1px] bg-slate-100 flex-1"></div>
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-[0.3em]">Attendance Kiosk</p>
                    <div class="h-[1px] bg-slate-100 flex-1"></div>
                </div>
            </div>

            <div class="space-y-6">
                <div class="space-y-3">
                    <div class="flex justify-between items-end px-1">
                        <label class="text-[9px] font-bold text-slate-500 uppercase tracking-widest">Identification</label>
                        <span class="text-[8px] font-bold text-[#6D3089] uppercase tracking-widest bg-purple-50 px-2 py-0.5 rounded">Trainee ID</span>
                    </div>
                    <input type="text" id="trainee_id" placeholder="QST0000" class="id-input" autocomplete="off">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <button onclick="submitAttendance('in')" class="btn-attendance btn-in">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                        <span>Time In</span>
                    </button>
                    <button onclick="submitAttendance('out')" class="btn-attendance btn-out">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                        <span>Time Out</span>
                    </button>
                </div>
            </div>

            <div class="pt-6 border-t border-slate-50 flex justify-between items-end">
                <div>
                    <div id="clock" class="text-2xl font-bold text-slate-800 tabular-nums leading-none">00:00:00</div>
                    <p class="text-[9px] font-bold text-slate-400 uppercase tracking-widest mt-1.5"><?php echo date('D, M d'); ?></p>
                </div>
                <a href="login.php" class="text-[20px] font-bold text-slate-400 uppercase tracking-widest hover:text-[#6D3089] transition-colors pb-1">Login</a>
            </div>
        </div>

        <div class="camera-panel">
            <div id="my_camera"></div>
            
            <div class="face-scan-badge">
                <div class="relative flex h-2 w-2">
                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                    <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                </div>
                <div>
                    <p class="text-[9px] text-white font-black uppercase tracking-widest leading-none">Active Scan</p>
                    <p class="text-[7px] text-emerald-400 font-bold uppercase tracking-widest mt-1">Face Recognition</p>
                </div>
            </div>

            <div class="absolute inset-0 pointer-events-none border-[1.5rem] border-black/10"></div>
            
            <div class="absolute bottom-6 left-6 right-6">
                <div class="bg-black/30 backdrop-blur-sm p-3 rounded-xl border border-white/5 text-center">
                    <p class="text-[8px] text-white/70 font-bold uppercase tracking-widest">Position your face within the frame</p>
                </div>
            </div>
        </div>
    </div>
        
    <script>
        // Synchronized Digital Clock
        function updateClock() {
            document.getElementById('clock').textContent = new Date().toLocaleTimeString('en-US', { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        setInterval(updateClock, 1000); updateClock();

        // High-Definition Background Wave Generator Engine
        const canvas = document.getElementById('bg-canvas');
        const ctx = canvas.getContext('2d');

        let width = canvas.width = window.innerWidth;
        let height = canvas.height = window.innerHeight;

        window.addEventListener('resize', () => {
            width = canvas.width = window.innerWidth;
            height = canvas.height = window.innerHeight;
        });

        let phase = 0;

        function drawFluidWaves() {
            ctx.clearRect(0, 0, width, height);
            
            const totalLines = 18;       // Increased for total page coverage
            const waveLength = 0.0022; 
            const baseAmplitude = 65; 
            
            ctx.lineWidth = 1.3;

            for (let i = 0; i < totalLines; i++) {
                ctx.beginPath();
                
                const factor = i / totalLines;
                const r = Math.floor(109 + (111 * factor));
                const g = Math.floor(48 - (10 * factor));
                const b = Math.floor(137 - (99 * factor));
                
                // Keep the opacity gentle since it spans the full screen height
                const opacity = 0.03 + (0.12 * (1 - factor));
                ctx.strokeStyle = `rgba(${r}, ${g}, ${b}, ${opacity})`;
                
                // UPDATED: Distributes the starting heights across the whole viewport height
                const targetY = (height * 0.1) + (height * 0.8 * factor);

                for (let x = 0; x < width; x += 3) {
                    const localAmplitude = Math.sin(x * 0.0012 + phase * 0.4) * baseAmplitude;
                    const y = targetY + 
                              Math.sin(x * waveLength + phase + (i * 0.35)) * localAmplitude + 
                              Math.cos(x * 0.0015 - phase * 0.25) * 25;
                              
                    if (x === 0) {
                        ctx.moveTo(x, y);
                    } else {
                        ctx.lineTo(x, y);
                    }
                }
                ctx.stroke();
            }
            
            phase += 0.006; // Elegant slow fluid pacing
            requestAnimationFrame(drawFluidWaves);
        }
        drawFluidWaves();

        // Optimized Hardware Webcam Stream Mapping
        Webcam.set({ 
            width: 1280, 
            height: 720, 
            dest_width: 1280, 
            dest_height: 720, 
            image_format: 'jpeg', 
            jpeg_quality: 90, 
            constraints: { 
                facingMode: 'user'
            } 
        });
        Webcam.attach('#my_camera');

        // Central Authentication Controller Process Dispatcher
        async function submitAttendance(type) {
            const traineeId = document.getElementById('trainee_id').value.trim();
            if (!traineeId) { 
                Swal.fire({ 
                    icon: 'warning', 
                    title: 'ID Required', 
                    text: 'Please enter your Trainee ID.', 
                    confirmButtonColor: '#6D3089',
                    confirmButtonText: 'OK'
                }); 
                return; 
            }

            Webcam.snap(async function(data_uri) {
                let narrative = '';
                if (type === 'out') {
                    const { value: text } = await Swal.fire({ 
                        title: 'Daily Accomplishment', 
                        input: 'textarea', 
                        inputPlaceholder: 'What did you do today?', 
                        confirmButtonColor: '#6D3089', 
                        showCancelButton: true,
                        inputValidator: (value) => { if (!value) return 'Required' }
                    });
                    if (text === undefined) return; 
                    narrative = text;
                }

                Swal.fire({ 
                    title: 'Verifying...', 
                    allowOutsideClick: false,
                    didOpen: () => { Swal.showLoading(); } 
                });

                const formData = new FormData();
                formData.append('trainee_username', traineeId);
                formData.append('type', type);
                formData.append('image', data_uri);
                formData.append('csrf_token', '<?php echo $csrf_token; ?>');
                formData.append('is_kiosk', '1');
                if (type === 'out') formData.append('narrative', narrative);

                if (navigator.geolocation) {
                    navigator.geolocation.getCurrentPosition(async (pos) => {
                        formData.append('lat', pos.coords.latitude);
                        formData.append('lng', pos.coords.longitude);
                        sendRequest(formData);
                    }, () => sendRequest(formData), { timeout: 3000 });
                } else { sendRequest(formData); }
            });
        }

        // Asynchronous Core Uplink Logic Request Controller
        async function sendRequest(formData) {
            try {
                const response = await fetch('attendance_process.php', { method: 'POST', body: formData });
                const result = await response.json();
                if (result.success) {
                    Swal.fire({ 
                        icon: 'success', 
                        title: 'Success', 
                        text: result.message, 
                        timer: 3000, 
                        showConfirmButton: false
                    });
                    document.getElementById('trainee_id').value = '';
                } else {
                    Swal.fire({ 
                        icon: 'error', 
                        title: 'Denied', 
                        text: result.message, 
                        confirmButtonColor: '#6D3089' 
                    });
                }
            } catch (error) {
                Swal.fire({ 
                    icon: 'error', 
                    title: 'Error', 
                    text: 'Connection failed.', 
                    confirmButtonColor: '#6D3089' 
                });
            }
        }
        window.onload = () => document.getElementById('trainee_id').focus();
    </script>
</body>
</html>