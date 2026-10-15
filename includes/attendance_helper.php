<?php
/**
 * ATTENDANCE BUSINESS LOGIC
 */

/**
 * Calculate distance between two points in meters
 */
function getDistance($lat1, $lon1, $lat2, $lng2) {
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lng2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $earth_radius * $c;
}

/**
 * Process Attendance Record
 */
function processAttendance($pdo, $user_id, $type, $image_data = null, $extra_data = [], $upload_dir = 'uploads/attendance/') {
    $date = date('Y-m-d');
    $time = date('H:i:s');

    // If image is missing, try to get from POST if not passed as arg
    if (!$image_data && isset($_POST['image'])) $image_data = $_POST['image'];
    if (!$image_data && isset($_POST['photo'])) $image_data = $_POST['photo'];

    // 0. Fetch System Settings for Rules
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $late_threshold = (int)($settings['auto_sanction_late_minutes'] ?? 15);
    $late_penalty = (float)($settings['auto_sanction_hours_penalty'] ?? 1);
    $geofence_radius = (float)($settings['geofencing_radius_meters'] ?? 500);
    $hotel_lat = (float)($settings['hotel_lat'] ?? 10.3167);
    $hotel_lng = (float)($settings['hotel_lng'] ?? 123.9000);

    // 1. Validate Image Data
    if (empty($image_data)) {
        return ['success' => false, 'message' => 'No image data received. Please ensure camera is working.'];
    }

    // 2. Process Image (Base64 to File)
    try {
        if (strpos($image_data, 'data:image/jpeg;base64,') === 0) {
            $image_parts = explode(";base64,", $image_data);
            $image_base64 = base64_decode($image_parts[1]);
        } else {
            $image_base64 = base64_decode($image_data);
        }

        if (!$image_base64) {
            return ['success' => false, 'message' => 'Invalid image format.'];
        }

        $filename = "att_" . $user_id . "_" . time() . ".jpg";
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $file_path = $upload_dir . $filename;
        if (file_put_contents($file_path, $image_base64) === false) {
            return ['success' => false, 'message' => 'Failed to save image file.'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Image processing error: ' . $e->getMessage()];
    }

    // 3. Database Logic
    try {
        if ($type === 'in') {
            // Check for existing active session
            $stmt = $pdo->prepare("SELECT id FROM attendance WHERE trainee_id = ? AND date = ? AND time_out IS NULL AND deleted_at IS NULL");
            $stmt->execute([$user_id, $date]);
            if ($stmt->fetch()) {
                return ['success' => false, 'message' => 'You are already Timed-In!'];
            }

            // GEOFENCING CHECK
            $lat = $extra_data['lat'] ?? null;
            $lng = $extra_data['lng'] ?? null;
            if ($lat && $lng) {
                $distance = getDistance($lat, $lng, $hotel_lat, $hotel_lng);
                if ($distance > $geofence_radius) {
                    return ['success' => false, 'message' => 'Geofencing Error: You must be within ' . $geofence_radius . 'm of the hotel to Time-In. (Current: ' . round($distance) . 'm)'];
                }
            }

            // 1. Get Today's Specific Schedule (if any)
            $stmt = $pdo->prepare("SELECT shift_start FROM schedules WHERE trainee_id = ? AND date = ? AND deleted_at IS NULL");
            $stmt->execute([$user_id, $date]);
            $shift_start = $stmt->fetchColumn();

            if (!$shift_start) {
                // Fallback to default trainee shift
                $stmt = $pdo->prepare("SELECT shift_start FROM trainees WHERE id = ?");
                $stmt->execute([$user_id]);
                $shift_start = $stmt->fetchColumn() ?: '08:00:00';
            }
            
            // AUTOMATED SANCTION LOGIC
            $is_late = (strtotime($time) > strtotime($shift_start . " +$late_threshold minutes")) ? 1 : 0;

            // Insert new record
            $stmt = $pdo->prepare("INSERT INTO attendance (trainee_id, date, time_in, photo_in, latitude, longitude, is_late) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $date, $time, $filename, $lat, $lng, $is_late]);
            $attendance_id = $pdo->lastInsertId();

            // If late, auto-add sanction
            if ($is_late && $late_penalty > 0) {
                $stmt = $pdo->prepare("INSERT INTO sanctions (trainee_id, hours_added, reason, admin_id) VALUES (?, ?, ?, 1)");
                $reason = "Automated System Sanction: Late for shift (Threshold: $late_threshold min)";
                $stmt->execute([$user_id, $late_penalty, $reason]);
            }

            logAudit($pdo, $user_id, 'TIME_IN', "Timed in via Kiosk/Dashboard");
            return ['success' => true, 'message' => 'Time-In Successful! Have a great shift.', 'time' => $time];

        } elseif ($type === 'out') {
            // Check for active session
            $stmt = $pdo->prepare("SELECT id, time_in, date FROM attendance WHERE trainee_id = ? AND time_out IS NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
            $stmt->execute([$user_id]);
            $record = $stmt->fetch();

            if (!$record) {
                return ['success' => false, 'message' => 'No active Time-In session found.'];
            }

            // 0. Fetch Today's Schedule for Hard-Cap Logic
            $stmt = $pdo->prepare("SELECT shift_start, shift_end, status FROM schedules WHERE trainee_id = ? AND date = ? AND deleted_at IS NULL");
            $stmt->execute([$user_id, $record['date']]);
            $schedule = $stmt->fetch();

            $is_day_off = ($schedule && ($schedule['status'] ?? 'duty') === 'day_off');

            if (!$schedule) {
                // Fallback to default trainee shift
                $stmt = $pdo->prepare("SELECT shift_start, shift_end FROM trainees WHERE id = ?");
                $stmt->execute([$user_id]);
                $schedule = $stmt->fetch();
            }

            $shift_start_ts = strtotime($record['date'] . ' ' . ($schedule['shift_start'] ?? '08:00:00'));
            $shift_end_ts   = strtotime($record['date'] . ' ' . ($schedule['shift_end'] ?? '17:00:00'));
            
            // Handle shifts spanning midnight (e.g., 10 PM to 6 AM)
            if ($shift_end_ts < $shift_start_ts) {
                $shift_end_ts = strtotime("+1 day", $shift_end_ts);
            }

            $time_in_ts  = strtotime($record['date'] . ' ' . $record['time_in']);
            $time_out_ts = strtotime($record['date'] . ' ' . $time);
            
            // If time_out is earlier than time_in, it must be the next day
            if ($time_out_ts < $time_in_ts) {
                $time_out_ts = strtotime("+1 day", $time_out_ts);
            }

            // --- FIXED HOURS BY DEPARTMENT LOGIC (Quest Professional Standard) ---
            // Fetch sa department sa trainee aron mahibaluan kung Back Office ba o Operations
            $stmt = $pdo->prepare("SELECT department FROM trainees WHERE id = ?");
            $stmt->execute([$user_id]);
            $trainee_dept = $stmt->fetchColumn() ?: '';

            // I-define kung kinsa ra ang Back Office (10 Hours Fixed Max)
            // Ang wala dinhi (lakip ang IT ug F&B Service) kay automatic Operations (8 Hours Fixed Max)
            $back_office_departments = ['Finance', 'Human Resource', 'Sales & Marketing'];

            if (in_array($trainee_dept, $back_office_departments)) {
                $max_allowed_seconds = 10 * 3600; // 10 Hours in seconds
            } else {
                $max_allowed_seconds = 8 * 3600;  // 8 Hours in seconds (IT & Operations)
            }

            if ($is_day_off) {
                // On Day Off, NO standard hours are credited. ALL hours are pending OT.
                $standard_seconds = 0;
                $pending_ot_seconds = $time_out_ts - $time_in_ts;
            } else {
                // Effective In: Either when they timed in, or when the shift started (whichever is LATER)
                $effective_in = max($time_in_ts, $shift_start_ts);

                // Effective Out: Either when they timed out, or when the shift ended (whichever is EARLIER)
                $effective_out = min($time_out_ts, $shift_end_ts);

                // Kalkulahon ang actual standard hours sulod sa shift duration
                $actual_shift_seconds = max(0, $effective_out - $effective_in);
                
                // I-clamp ang standard hours base sa iyang department dynamic maximum limit
                $standard_seconds = min($actual_shift_seconds, $max_allowed_seconds);
                
                // --- LOITERING / PENDING OT LOGIC ---
                $pending_ot_seconds = 0;
                
                // 1. Oras sa wala pa nagsugod ang shift
                if ($time_in_ts < $shift_start_ts) {
                    $pending_ot_seconds += ($shift_start_ts - $time_in_ts);
                }
                // 2. Oras pagkahuman sa shift end
                if ($time_out_ts > $shift_end_ts) {
                    $pending_ot_seconds += ($time_out_ts - $shift_end_ts);
                }
                
                // 3. HR Strict Rule: Kung ang iyang oras sa shift mas dako kay sa iyang required daily cap (8 or 10 hours)
                // Ang sobra nga oras awtomatikong ilabay ngadto sa Pending OT field
                if ($actual_shift_seconds > $max_allowed_seconds) {
                    $pending_ot_seconds += ($actual_shift_seconds - $max_allowed_seconds);
                }
            }

            $hours_worked = round($standard_seconds / 3600, 2);
            $narrative = $extra_data['narrative'] ?? '';

            // Update record - AUTO-VERIFY INIG TIME OUT
            // Automatic na nato i-set ang verified_at ug verified_by para bypass na sa manual supervisor approval
            $stmt = $pdo->prepare("UPDATE attendance SET time_out = ?, photo_out = ?, hours_worked = ?, narrative = ?, pending_ot_seconds = ?, verified_at = CURRENT_TIMESTAMP, verified_by = 1 WHERE id = ?");
            $stmt->execute([$time, $filename, $hours_worked, $narrative, $pending_ot_seconds, $record['id']]);

            logAudit($pdo, $user_id, 'TIME_OUT', "Timed out. Worked: $hours_worked hrs (Pending OT: " . round($pending_ot_seconds/3600, 2) . "h)");
            
            $msg = 'Time-Out Successful! Total: ' . $hours_worked . ' hours.';
            if ($pending_ot_seconds > 0) {
                $msg .= ' Extra time (' . round($pending_ot_seconds/3600, 2) . 'h) requires an approved OT request to be credited.';
            }
            
            return ['success' => true, 'message' => $msg, 'time' => $time];
        }

        return ['success' => false, 'message' => 'Invalid attendance type.'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Database Error: ' . $e->getMessage()];
    }
}

function calculateApprovedOtSeconds($pdo, $trainee_id, $date, $ot_start_time, $ot_end_time) {
    $date = (string)$date;
    $ot_start_time = (string)$ot_start_time;
    $ot_end_time = (string)$ot_end_time;
    if ($date === '' || $ot_start_time === '' || $ot_end_time === '') {
        return 0;
    }

    $stmt = $pdo->prepare("SELECT time_in, time_out FROM attendance WHERE trainee_id = ? AND date = ? AND time_out IS NOT NULL AND deleted_at IS NULL ORDER BY id DESC LIMIT 1");
    $stmt->execute([(int)$trainee_id, $date]);
    $att = $stmt->fetch();
    if (!$att) {
        return 0;
    }

    $time_in_ts = strtotime($date . ' ' . $att['time_in']);
    $time_out_ts = strtotime($date . ' ' . $att['time_out']);
    if ($time_out_ts < $time_in_ts) {
        $time_out_ts = strtotime("+1 day", $time_out_ts);
    }

    $stmt = $pdo->prepare("SELECT shift_start, shift_end, status FROM schedules WHERE trainee_id = ? AND date = ? AND deleted_at IS NULL");
    $stmt->execute([(int)$trainee_id, $date]);
    $schedule = $stmt->fetch();
    $is_day_off = ($schedule && ($schedule['status'] ?? 'duty') === 'day_off');

    if (!$schedule) {
        $stmt = $pdo->prepare("SELECT shift_start, shift_end FROM trainees WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([(int)$trainee_id]);
        $schedule = $stmt->fetch() ?: ['shift_start' => '08:00:00', 'shift_end' => '17:00:00'];
    }

    $shift_start_ts = strtotime($date . ' ' . ($schedule['shift_start'] ?? '08:00:00'));
    $shift_end_ts = strtotime($date . ' ' . ($schedule['shift_end'] ?? '17:00:00'));
    if ($shift_end_ts < $shift_start_ts) {
        $shift_end_ts = strtotime("+1 day", $shift_end_ts);
    }

    $ot_start_ts = strtotime($date . ' ' . $ot_start_time);
    $ot_end_ts = strtotime($date . ' ' . $ot_end_time);
    if ($ot_end_ts <= $ot_start_ts) {
        $ot_end_ts = strtotime("+1 day", $ot_end_ts);
    }

    $segments = [];
    if ($is_day_off) {
        $segments[] = [$time_in_ts, $time_out_ts];
    } else {
        // QUEST HOTEL RULE: NO EARLY TIME-IN AS OT! ONLY AFTER SHIFT END!
        if ($time_out_ts > $shift_end_ts) {
            $segments[] = [max($time_in_ts, $shift_end_ts), $time_out_ts];
        }
    }

    $approved = 0;
    foreach ($segments as [$segStart, $segEnd]) {
        $start = max($segStart, $ot_start_ts);
        $end = min($segEnd, $ot_end_ts);
        if ($end > $start) {
            $approved += ($end - $start);
        }
    }

    $approved_hours = $approved / 3600;
    if ($approved_hours < 1.0) {
        return 0;
    }

    return max(0, (int)(floor($approved_hours) * 3600));
}
