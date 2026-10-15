<!-- Schedule Calendar Component -->
<script>
let currentCalendarDate = new Date();
let activeTraineeId = null;
let activeTraineeName = null;

async function openScheduleCalendar(traineeId = null, traineeName = null) {
    activeTraineeId = traineeId;
    activeTraineeName = traineeName;
    
    const { value: ignore } = await Swal.fire({
        title: traineeName ? `${traineeName}'s Schedule` : 'System Schedule Calendar',
        width: '700px',
        html: `
            <div class="text-left space-y-4">
                <div class="flex items-center justify-between bg-slate-50 p-3 rounded-xl border border-slate-100">
                    <div id="modal-calendar-header" class="text-sm font-black text-[#6D3089] uppercase tracking-widest"></div>
                    <div class="flex gap-2">
                        <button onclick="changeCalendarMonth(-1)" class="p-1.5 hover:bg-white hover:shadow-sm rounded-lg transition-all text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                        </button>
                        <button onclick="changeCalendarMonth(1)" class="p-1.5 hover:bg-white hover:shadow-sm rounded-lg transition-all text-slate-400">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </button>
                    </div>
                </div>
                
                <div class="grid grid-cols-7 gap-1 text-center">
                    ${['M','T','W','T','F','S','S'].map(d => `<span class="text-[10px] font-black text-slate-300 uppercase">${d}</span>`).join('')}
                </div>
                <div id="modal-calendar-days" class="grid grid-cols-7 gap-1 min-h-[300px]"></div>

                <div id="modal-date-info" class="mt-4 hidden animate-in slide-in-from-bottom duration-300">
                    <div class="p-4 bg-slate-50 rounded-2xl border border-slate-100 shadow-inner">
                        <div class="flex items-center justify-between mb-3">
                            <p id="modal-info-date" class="text-[10px] font-black text-[#6D3089] uppercase tracking-widest"></p>
                            <span class="text-[9px] font-bold text-slate-400 uppercase">${traineeId ? 'Daily Detail' : 'Scheduled OJTs'}</span>
                        </div>
                        <div id="modal-scheduled-list" class="space-y-2 max-h-48 overflow-y-auto pr-2 custom-scrollbar">
                            <!-- List injected here -->
                        </div>
                    </div>
                </div>
            </div>
        `,
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            renderModalCalendar();
        }
    });
}

function changeCalendarMonth(delta) {
    currentCalendarDate.setMonth(currentCalendarDate.getMonth() + delta);
    renderModalCalendar();
}

async function renderModalCalendar() {
    const daysContainer = document.getElementById('modal-calendar-days');
    const headerContainer = document.getElementById('modal-calendar-header');
    if (!daysContainer || !headerContainer) return;

    const year = currentCalendarDate.getFullYear();
    const month = currentCalendarDate.getMonth();
    
    headerContainer.textContent = new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(currentCalendarDate);
    
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    let startingDay = firstDay === 0 ? 6 : firstDay - 1;
    
    daysContainer.innerHTML = '';
    for (let i = 0; i < startingDay; i++) {
        daysContainer.innerHTML += '<div></div>';
    }
    
    let monthSchedules = {};
    if (activeTraineeId) {
        try {
            const response = await fetch(`/quest_ojt_system/ajax/get_scheduled_ojts.php?trainee_id=${activeTraineeId}&month=${month + 1}&year=${year}`);
            const data = await response.json();
            data.forEach(s => monthSchedules[s.date] = s);
        } catch (e) {}
    }
    
    const today = new Date().toISOString().split('T')[0];

    for (let d = 1; d <= daysInMonth; d++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const isToday = dateStr === today;
        const daySched = monthSchedules[dateStr];
        
        const dayBtn = document.createElement('button');
        dayBtn.className = `min-h-[60px] w-full flex flex-col items-center p-1 text-xs font-bold rounded-xl transition-all border
            ${isToday ? 'bg-purple-50 border-[#6D3089]/20' : 'bg-white border-slate-50 hover:bg-slate-50'}`;
        
        dayBtn.innerHTML = `
            <span class="${isToday ? 'bg-[#6D3089] text-white w-5 h-5 rounded-full flex items-center justify-center mb-1' : 'text-slate-400 mb-1'}">${d}</span>
            ${daySched ? `
                <div class="w-full px-1">
                    <span class="block text-[8px] font-black truncate rounded px-1 py-0.5 mb-0.5
                        ${daySched.status === 'day_off' ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600'}">
                        ${daySched.status === 'day_off' ? 'OFF' : (daySched.time_in ? daySched.time_in.substring(0,5) : daySched.shift_start.substring(0,5))}
                    </span>
                    ${daySched.status !== 'day_off' ? `<span class="block text-[8px] text-slate-400 font-bold tracking-tighter">${daySched.time_out ? daySched.time_out.substring(0,5) : daySched.shift_end.substring(0,5)}</span>` : ''}
                </div>
            ` : ''}
        `;
        
        dayBtn.onclick = () => showModalDaySchedule(dateStr, daySched);
        daysContainer.appendChild(dayBtn);
    }
}

async function showModalDaySchedule(date, preLoaded = null) {
    const infoContainer = document.getElementById('modal-date-info');
    const infoDate = document.getElementById('modal-info-date');
    const scheduledList = document.getElementById('modal-scheduled-list');
    
    infoContainer.classList.remove('hidden');
    infoDate.textContent = new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    
    if (activeTraineeId) {
        // Individual Detail
        if (!preLoaded) {
            // Also check for attendance even without schedule
            try {
                const attResponse = await fetch(`/quest_ojt_system/ajax/get_scheduled_ojts.php?date=${date}`);
                const attData = await attResponse.json();
                const traineeAtt = attData.find(x => x.first_name && x.last_name && (activeTraineeName ? (x.first_name + ' ' + x.last_name) === activeTraineeName : true));
                if (traineeAtt) {
                    preLoaded = traineeAtt;
                }
            } catch(e) {}
            if (!preLoaded) {
                scheduledList.innerHTML = '<div class="text-[10px] text-slate-400 italic py-4 text-center">No schedule plotted for this day.</div>';
                return;
            }
        }
        scheduledList.innerHTML = `
            <div class="p-3 bg-white rounded-xl border border-slate-100 shadow-sm">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-[10px] font-black text-slate-800 uppercase">${preLoaded.status ? preLoaded.status.replace('_', ' ') : 'Duty'}</p>
                        <div class="mt-1">
                            ${preLoaded.shift_start ? `<p class="text-xs font-bold text-slate-500">Schedule: ${preLoaded.shift_start} - ${preLoaded.shift_end}</p>` : ''}
                            ${preLoaded.time_in ? `<p class="text-xs font-bold text-emerald-600">Actual: ${preLoaded.time_in}${preLoaded.time_out ? ' - ' + preLoaded.time_out : ''}</p>` : ''}
                        </div>
                    </div>
                    <span class="w-2 h-2 rounded-full ${preLoaded.status === 'day_off' ? 'bg-rose-500 shadow-[0_0_8px_rgba(244,63,94,0.4)]' : 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]'}"></span>
                </div>
            </div>
        `;
        return;
    }

    // Global View
    scheduledList.innerHTML = '<div class="text-xs text-slate-400 italic py-4 text-center">Fetching schedule...</div>';

    try {
        const response = await fetch(`/quest_ojt_system/ajax/get_scheduled_ojts.php?date=${date}`);
        const data = await response.json();
        
        if (data.length === 0) {
            scheduledList.innerHTML = `
                <div class="flex flex-col items-center justify-center py-8 opacity-40">
                    <svg class="w-8 h-8 text-slate-300 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 0 002 2z"></path></svg>
                    <p class="text-[10px] font-bold text-slate-400 uppercase tracking-widest">No OJTs Scheduled</p>
                </div>`;
        } else {
            scheduledList.innerHTML = data.map(ojt => `
                <div class="group flex items-center justify-between p-2.5 bg-white rounded-xl border border-slate-100 hover:border-purple-200 transition-all shadow-sm">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-full bg-slate-50 flex items-center justify-center text-[10px] font-black text-[#6D3089] border border-slate-100 group-hover:bg-purple-50">
                            ${ojt.first_name[0]}${ojt.last_name[0]}
                        </div>
                        <div>
                            <p class="text-[10px] font-bold text-slate-800 uppercase tracking-tight">${ojt.first_name} ${ojt.last_name}</p>
                            <p class="text-[9px] font-bold text-slate-400 uppercase">${ojt.department}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="text-[9px] font-black ${ojt.status === 'day_off' ? 'text-rose-500 bg-rose-50' : 'text-emerald-500 bg-emerald-50'} px-2 py-0.5 rounded-full uppercase tracking-tighter">
                            ${ojt.status ? ojt.status.replace('_', ' ') : 'Duty'}
                        </span>
                        <div class="mt-1">
                            ${ojt.status !== 'day_off' && ojt.shift_start ? `
                                <p class="text-[9px] font-bold text-slate-400">Sched: ${ojt.shift_start.substring(0,5)} - ${ojt.shift_end.substring(0,5)}</p>
                            ` : ''}
                            ${ojt.time_in ? `
                                <p class="text-[9px] font-bold text-emerald-600">Actual: ${ojt.time_in.substring(0,5)}${ojt.time_out ? ' - ' + ojt.time_out.substring(0,5) : ''}</p>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `).join('');
        }
    } catch (error) {
        scheduledList.innerHTML = '<div class="text-xs text-rose-400 italic py-4 text-center">Failed to load schedule.</div>';
    }
}
</script>
<style>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-track { background: transparent; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: #e2e8f0; border-radius: 10px; }
.custom-scrollbar::-webkit-scrollbar-thumb:hover { background: #cbd5e1; }
</style>
