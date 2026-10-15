<?php
/**
 * REQUESTS UI — SHARED DESIGN SYSTEM HELPERS
 * Professional, advanced, user-friendly components used by:
 *   - requests.php (trainee)
 *   - supervisor/requests.php
 *   - admin/requests.php
 *
 * All components render HTML-only (no global CSS needed beyond the
 * project's existing Tailwind classes + main layout stylesheet).
 */

if (!function_exists('requestTypeBadge')) {
    function requestTypeBadge($type, $size = 'md') {
        $type = strtolower(trim((string)$type));
        $sizes = [
            'sm'  => 'px-2.5 py-0.5 text-[9px] rounded-full',
            'md'  => 'px-3 py-1 text-[10px] rounded-full',
            'lg'  => 'px-4 py-1.5 text-xs rounded-2xl',
        ];
        $sizeCls = $sizes[$size] ?? $sizes['md'];
        $variants = [
            'leave'     => ['bg-amber-50 text-amber-700 border border-amber-100 ring-1 ring-inset ring-amber-200/60',
                            'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            'Leave'],
            'overtime'  => ['bg-emerald-50 text-emerald-700 border border-emerald-100 ring-1 ring-inset ring-emerald-200/60',
                            'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'Overtime'],
            'undertime' => ['bg-rose-50 text-rose-700 border border-rose-100 ring-1 ring-inset ring-rose-200/60',
                            'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
                            'Undertime'],
            'default'   => ['bg-slate-50 text-slate-700 border border-slate-200 ring-1 ring-inset ring-slate-200/60',
                            'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
                            ucfirst($type)],
        ];
        $v = $variants[$type] ?? $variants['default'];
        [$cls, $svg, $label] = $v;
        return '<span class="inline-flex items-center gap-1.5 font-black uppercase tracking-widest ' . $sizeCls . ' ' . $cls . '">'
            . '<svg class="' . ($size === 'sm' ? 'w-3 h-3' : ($size === 'lg' ? 'w-4 h-4' : 'w-3.5 h-3.5')) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $svg . '"></path></svg>'
            . htmlspecialchars($label)
            . '</span>';
    }
}

if (!function_exists('requestStatusDot')) {
    function requestStatusDot($status, $label = null) {
        $status = strtolower(trim((string)$status));
        $map = [
            'pending'  => ['cls' => 'bg-amber-500', 'text' => 'text-amber-600'],
            'approved' => ['cls' => 'bg-emerald-500', 'text' => 'text-emerald-600'],
            'rejected' => ['cls' => 'bg-rose-500',   'text' => 'text-rose-600'],
        ];
        $v = $map[$status] ?? ['cls' => 'bg-slate-400', 'text' => 'text-slate-500'];
        $label = $label ?? ucfirst($status);
        return '<span class="inline-flex items-center gap-2 ' . $v['text'] . ' text-[10px] font-black uppercase tracking-widest">'
            . '<span class="relative inline-flex h-2 w-2"><span class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-60 ' . $v['cls'] . '"></span><span class="relative inline-flex h-2 w-2 rounded-full ' . $v['cls'] . '"></span></span>'
            . htmlspecialchars($label)
            . '</span>';
    }
}

if (!function_exists('requestApprovalStepper')) {
    function requestApprovalStepper($supervisorStatus, $hrStatus, $compact = false) {
        $sp = strtolower((string)$supervisorStatus);
        $hp = strtolower((string)$hrStatus);
        $stepState = function($state, $label, $sub = '') use ($compact) {
            $box = match($state) {
                'done' => 'bg-emerald-500 text-white border-emerald-500 shadow-lg shadow-emerald-500/20',
                'active' => 'bg-white border-[#6D3089] text-[#6D3089] ring-4 ring-[#6D3089]/10 shadow-lg shadow-[#6D3089]/10',
                'rejected' => 'bg-rose-500 text-white border-rose-500 shadow-lg shadow-rose-500/20',
                default => 'bg-white text-slate-400 border-slate-200',
            };
            $connector = match($state) {
                'done' => 'bg-emerald-400',
                'rejected' => 'bg-rose-400',
                default => 'bg-slate-200',
            };
            $svgDone = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>';
            $svgReject = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>';
            $icon = $state === 'done' ? $svgDone : ($state === 'rejected' ? $svgReject : '');
            $h = $compact ? 'h-7 w-7 text-xs' : 'h-9 w-9 text-sm';
            $html = '<div class="flex items-center gap-' . ($compact ? '2' : '3') . ' min-w-0">';
            $html .= '<div class="flex-shrink-0 border inline-flex items-center justify-center rounded-full ' . $h . ' font-black ' . $box . '">' . $icon . '</div>';
            $html .= '<div class="min-w-0"><p class="text-[10px] font-black uppercase tracking-widest text-slate-800 truncate">' . htmlspecialchars($label) . '</p>';
            if ($sub !== '' && !$compact) {
                $html .= '<p class="text-[10px] text-slate-400 font-bold uppercase tracking-wide truncate">' . htmlspecialchars($sub) . '</p>';
            }
            $html .= '</div></div>';
            return ['html' => $html, 'connector' => $connector];
        };

        $supState = 'pending';
        if ($sp === 'approved') $supState = 'done';
        elseif ($sp === 'rejected') $supState = 'rejected';
        elseif ($sp === 'pending') $supState = $hp === 'pending' ? 'active' : 'done';
        if ($hp === 'rejected' && $sp === 'approved') {
            // Supervisor already approved, HR rejected -> mark both, HR shows red
        }
        $hrState = 'pending';
        if ($hp === 'approved') $hrState = 'done';
        elseif ($hp === 'rejected') $hrState = 'rejected';
        elseif ($sp === 'approved' && $hp === 'pending') $hrState = 'active';
        elseif ($sp === 'rejected') $hrState = 'rejected';

        $s1 = $stepState($supState, 'Supervisor Review', $sp === 'approved' ? 'Endorsed' : ($sp === 'rejected' ? 'Returned' : 'In department queue'));
        $s2 = $stepState($hrState,  'HR Final Review',  $hp === 'approved' ? 'Filed'    : ($hp === 'rejected' ? 'Rejected' : 'Awaiting HR'));
        $gap = $compact ? 'w-5' : 'w-10';

        $out = '<div class="flex items-center">';
        $out .= $s1['html'];
        $out .= '<div class="flex-shrink-0 mx-2"><div class="' . $gap . ' h-[2px] rounded-full ' . $s2['connector'] . '"></div></div>';
        $out .= $s2['html'];
        $out .= '</div>';
        return $out;
    }
}

if (!function_exists('requestKpiTile')) {
    function requestKpiTile($label, $value, $hint, $accent = 'slate', $icon = 'doc', $subvalue = null) {
        $accents = [
            'purple'   => ['bg-purple-50/80 border-purple-100 text-purple-600 from-white to-purple-50',    'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
            'amber'    => ['bg-amber-50/80 border-amber-100 text-amber-600 from-white to-amber-50',      'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z'],
            'blue'     => ['bg-blue-50/80 border-blue-100 text-blue-600 from-white to-blue-50',         'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            'emerald'  => ['bg-emerald-50/80 border-emerald-100 text-emerald-600 from-white to-emerald-50', 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            'rose'     => ['bg-rose-50/80 border-rose-100 text-rose-600 from-white to-rose-50',          'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
            'slate'    => ['bg-slate-50/80 border-slate-200 text-slate-600 from-white to-slate-50',       'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
        ];
        [$accentCls, $iconPath] = $accents[$accent] ?? $accents['slate'];
        [$bgCls, $borderCls, $labelCls, $bgGrad] = explode(' ', $accentCls, 4);
        $size = 'w-10 h-10 rounded-2xl';
        $iconSvg = '<div class="' . $size . ' inline-flex items-center justify-center ' . $bgCls . ' ' . $labelCls . '"><svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="' . $iconPath . '"></path></svg></div>';
        $html = '<div class="relative overflow-hidden rounded-[1.5rem] border bg-gradient-to-br p-5 shadow-sm transition-all hover:shadow-md hover:-translate-y-0.5 ' . $accentCls . '">';
        $html .= '<div class="absolute -right-6 -top-6 h-24 w-24 rounded-full opacity-60 blur-2xl ' . $bgCls . '"></div>';
        $html .= '<div class="relative flex items-start justify-between gap-4">';
        $html .= '<div class="min-w-0 flex-1">';
        $html .= '<p class="text-[10px] font-black uppercase tracking-[0.2em] ' . $labelCls . '">' . htmlspecialchars($label) . '</p>';
        $html .= '<p class="mt-3 flex items-baseline gap-2">';
        $html .= '<span class="text-3xl font-black tracking-tight text-slate-900">' . htmlspecialchars((string)$value) . '</span>';
        if ($subvalue !== null) {
            $html .= '<span class="text-[10px] font-bold uppercase tracking-widest text-slate-400">' . htmlspecialchars((string)$subvalue) . '</span>';
        }
        $html .= '</p>';
        $html .= '<p class="mt-2 text-xs font-semibold text-slate-500 leading-relaxed">' . htmlspecialchars($hint) . '</p>';
        $html .= '</div>' . $iconSvg . '</div></div>';
        return $html;
    }
}

if (!function_exists('requestEmptyState')) {
    function requestEmptyState($title, $subtitle, $accent = 'slate', $ctaHtml = '') {
        $accents = [
            'slate'   => 'text-slate-400 bg-slate-50',
            'purple'  => 'text-[#6D3089] bg-purple-50',
            'amber'   => 'text-amber-500 bg-amber-50',
            'emerald' => 'text-emerald-500 bg-emerald-50',
            'rose'    => 'text-rose-500 bg-rose-50',
        ];
        [$text, $bg] = explode(' ', $accents[$accent] ?? $accents['slate'], 2);
        $html = '<div class="flex flex-col items-center justify-center text-center px-6 py-14 w-full">';
        $html .= '<div class="flex h-20 w-20 items-center justify-center rounded-3xl ' . $bg . ' ' . $text . ' ring-1 ring-inset ' . $bg . '/60">';
        $html .= '<svg class="w-9 h-9" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>';
        $html .= '</div>';
        $html .= '<h3 class="mt-6 text-sm font-black uppercase tracking-[0.24em] text-slate-700">' . htmlspecialchars($title) . '</h3>';
        $html .= '<p class="mt-2 max-w-md text-sm text-slate-500 leading-relaxed font-medium">' . htmlspecialchars($subtitle) . '</p>';
        if ($ctaHtml !== '') $html .= '<div class="mt-6">' . $ctaHtml . '</div>';
        $html .= '</div>';
        return $html;
    }
}

if (!function_exists('requestViewTimeline')) {
    function requestViewTimeline($row) {
        $stages = [];
        $stages[] = [
            'title' => 'Request Filed',
            'sub'   => 'You submitted the request form',
            'date'  => $row['created_at'] ?? '',
            'state' => 'done',
        ];
        if (!empty($row['supervisor_approved_at'])) {
            $stages[] = [
                'title'   => 'Supervisor ' . ucfirst($row['supervisor_status']),
                'sub'     => $row['supervisor_note'] ?? 'Department reviewed',
                'date'    => $row['supervisor_approved_at'],
                'state'   => $row['supervisor_status'] === 'approved' ? 'done' : 'rejected',
            ];
        } elseif (!empty($row['supervisor_status'])) {
            $stages[] = [
                'title'   => 'Pending Supervisor Review',
                'sub'     => $row['supervisor_note'] ?? 'Awaiting department action',
                'date'    => '',
                'state'   => $row['supervisor_status'] === 'rejected' ? 'rejected' : 'active',
            ];
        }
        if (!empty($row['archived_at']) && !empty($row['hr_status'])) {
            $stages[] = [
                'title' => 'HR ' . ucfirst($row['hr_status']),
                'sub'   => $row['hr_note'] ?? 'HR finalized',
                'date'  => $row['archived_at'],
                'state' => $row['hr_status'] === 'approved' ? 'done' : 'rejected',
            ];
        } elseif (($row['supervisor_status'] ?? '') === 'approved') {
            $stages[] = [
                'title' => 'Pending HR Final Review',
                'sub'   => $row['hr_note'] ?? 'Endorsed by supervisor; HR queue',
                'date'  => '',
                'state' => 'active',
            ];
        }
        $out = '<ol class="relative border-s-slate-100 border-s ml-1 space-y-5">';
        foreach ($stages as $i => $s) {
            $stateColor = match ($s['state']) {
                'done' => 'bg-emerald-500 ring-emerald-100 text-white',
                'rejected' => 'bg-rose-500 ring-rose-100 text-white',
                default => 'bg-white ring-[#6D3089]/20 border-[#6D3089] text-[#6D3089] border-2',
            };
            $icon = $s['state'] === 'done'
                ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>'
                : ($s['state'] === 'rejected'
                    ? '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M6 18L18 6M6 6l12 12"></path></svg>'
                    : '<span class="text-[10px] font-black">' . ((int)$i + 1) . '</span>');
            $dateStr = !empty($s['date']) ? date('M d, Y · h:i A', strtotime($s['date'])) : '';
            $out .= '<li class="ms-6">';
            $out .= '<span class="absolute -start-[13px] inline-flex items-center justify-center rounded-full h-7 w-7 ring-4 ' . $stateColor . '">' . $icon . '</span>';
            $out .= '<div class="rounded-2xl border border-slate-100 bg-slate-50/50 p-3.5"><div class="flex items-start justify-between gap-3"><div class="min-w-0">';
            $out .= '<p class="text-[11px] font-black uppercase tracking-[0.18em] text-slate-800">' . htmlspecialchars($s['title']) . '</p>';
            $out .= '<p class="mt-1 text-[11px] text-slate-500 font-semibold leading-snug">' . htmlspecialchars($s['sub']) . '</p>';
            $out .= '</div>';
            if ($dateStr !== '') $out .= '<p class="flex-shrink-0 text-[9px] font-bold uppercase tracking-widest text-slate-400 whitespace-nowrap mt-0.5">' . htmlspecialchars($dateStr) . '</p>';
            $out .= '</div></div></li>';
        }
        $out .= '</ol>';
        return $out;
    }
}

if (!function_exists('requestFlashBanner')) {
    function requestFlashBanner() {
        $banners = [];
        $get = $_GET;
        $map = [
            'deleted'            => ['success', 'Request deleted',      'If this was an OT request, the pending OT card will reappear on your dashboard so you can re-file correctly.'],
            'success'            => ['success', 'Request submitted',    'Your request is now in your supervisor queue. You will receive notifications as it progresses.'],
            'status=approved'    => ['success', 'Approved',             'Selected requests have been approved successfully.'],
            'status=bulk_approved'=>['success', 'Bulk approved',        'Batch action completed. Approved requests updated in the workflow.'],
            'status=rejected'    => ['warning', 'Rejected',             'The request has been rejected. A note with the reason has been attached.'],
            'status=bulk_rejected'=>['warning', 'Bulk rejected',        'Selected requests have been rejected.'],
            'status=schedule_set' => ['success', 'Schedule saved',       'Trainee shift schedule has been successfully updated and notifications queued.'],
            'status=schedule_cleared' => ['success', 'Schedule cleared',  'Selected schedule days have been removed from the trainee calendar.'],
            'status=rated'      => ['success', 'Rating submitted',     'Performance evaluation has been saved to the trainee profile.'],
            'error=upload_failed' => ['error',   'Submission failed',   'We could not process the request or its attachment. Please try again.'],
            'error=csrf_failed'   => ['error',   'Security error',      'Your session timed out or the security token expired. Please refresh and try again.'],
            'error=invalid_password' => ['error', 'Authentication failed', 'The supervisor verification password you entered is incorrect.'],
            'error=db_error'    => ['error',   'Database error',       'The system encountered an error while saving. Please try again or contact IT.'],
            'error=cannot_delete' => ['warning', 'Cannot delete',       'This request is already in active review. Contact your supervisor or HR to amend it.'],
            'error=delete_failed' => ['error',   'Delete failed',       'Something went wrong while deleting. Please try again.'],
            'error=not_found'     => ['error',   'Request not found',   'This request no longer exists or belongs to another user.'],
            'reset_sent=1'        => ['success', 'Reset link sent',     'If an account exists for this email, a password reset link has been queued.'],
            'msg=approved'        => ['success', 'Trainee Approved',    'The trainee account has been activated and can now log in and record attendance.'],
            'msg=cleared'         => ['success', 'Graduation Cleared',  'This trainee has been officially cleared for completion. Their status is now Graduated.'],
            'msg=uncleared'       => ['warning', 'Clearance Revoked',   'Graduation clearance has been revoked. The trainee status has been restored to Active.'],
            'msg=created'         => ['success', 'Trainee Created',     'New trainee profile has been added successfully and is ready for onboarding.'],
            'msg=updated'         => ['success', 'Profile Updated',     'Trainee profile information has been saved successfully.'],
            'msg=transferred'     => ['success', 'Department Changed',  'Trainee has been transferred to the new department. The department history log has been updated.'],
            'msg=sanctioned'      => ['warning', 'Sanction Applied',    'Penalty hours have been added to this trainee\'s account and will appear in their progress calculation.'],
            'msg=adjusted'        => ['success', 'Hours Adjusted',      'Required and initial hour targets have been updated. Progress metrics will reflect the new values.'],
            'msg=hours_credited'  => ['success', 'Hours Credited',      'Credit hours have been manually added to the trainee\'s rendered total.'],
            'msg=schedule_set'    => ['success', 'Schedule Saved',      'Shift schedule has been saved. The trainee will receive an email notification with the updated dates.'],
            'msg=schedule_cleared'=> ['success', 'Schedule Cleared',    'Selected schedule days have been removed from this trainee\'s calendar.'],
            'msg=rated'           => ['success', 'Rating Saved',        'Performance rating and comment have been recorded successfully.'],
            'msg=sanction_removed'=> ['success', 'Sanction Removed',    'The selected penalty has been removed from the trainee record with the reason logged.'],
            'msg=deleted'         => ['success', 'Trainee Deleted',     'The trainee account has been soft-deleted and removed from active directory.'],
            'status=user_added'   => ['success', 'User account created','New user has been added successfully. They can now sign in with their credentials.'],
            'status=user_modified'=> ['success', 'User settings updated','Account changes have been saved and applied immediately.'],
            'status=user_deleted' => ['success', 'User removed',        'The account has been soft-deleted and is no longer active in the system.'],
            'error=exists'        => ['warning', 'Duplicate username',  'This username or email already exists. Please choose different credentials.'],
            'error=impersonate_failed'=>['error','Impersonation failed','Unable to switch to this user account. The user may not exist or be unavailable.'],
        ];
        $matched = null;
        foreach ($map as $qs => $v) {
            if (strpos($qs, '=') === false) {
                if (isset($get[$qs]) && (string)$get[$qs] !== '') { $matched = $v; break; }
            } else {
                [$k, $val] = explode('=', $qs, 2);
                if (isset($get[$k]) && (string)$get[$k] === $val) { $matched = $v; break; }
            }
        }
        if ($matched === null) return '';
        [$kind, $title, $desc] = $matched;
        $style = match($kind) {
            'success' => 'border-emerald-100 bg-emerald-50 text-emerald-900 ring-1 ring-inset ring-emerald-100',
            'warning' => 'border-amber-100 bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-100',
            'error'   => 'border-rose-100 bg-rose-50 text-rose-900 ring-1 ring-inset ring-rose-100',
            default   => 'border-slate-200 bg-slate-50 text-slate-800 ring-1 ring-inset ring-slate-100',
        };
        $iconCls = match($kind) {
            'success' => 'bg-emerald-100 text-emerald-600',
            'warning' => 'bg-amber-100 text-amber-600',
            'error'   => 'bg-rose-100 text-rose-600',
            default   => 'bg-slate-100 text-slate-500',
        };
        $icon = match($kind) {
            'success' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>',
            'warning' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"></path>',
            default   => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>',
        };
        return '<div class="relative overflow-hidden rounded-3xl border px-5 py-4 shadow-sm ' . $style . '">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 inline-flex items-center justify-center h-9 w-9 rounded-2xl ' . $iconCls . '">
                    <svg class="w-4.5 h-4.5" width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">' . $icon . '</svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-[11px] font-black uppercase tracking-[0.22em]">' . htmlspecialchars($title) . '</p>
                    <p class="mt-1 text-[11px] font-semibold leading-relaxed opacity-90">' . htmlspecialchars($desc) . '</p>
                </div>
            </div>
        </div>';
    }
}
