<?php
declare(strict_types=1);

require_once 'config/db.php';
require_once 'includes/auth/auth_helper.php';
require_once 'includes/layouts/main_layout.php';

checkAccess('trainee');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$user_id  = (int)($_SESSION['user_id'] ?? 0);
$full_name = trim((string)($_SESSION['full_name'] ?? ''));

if ($user_id <= 0) {
    header('Location: login.php');
    exit();
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit();
}

// function e(?string $value): string
// {
//     return htmlspecialchars(
//         (string)$value,
//         ENT_QUOTES | ENT_SUBSTITUTE,
//         'UTF-8'
//     );
// }

function formatTime(?string $time): string
{
    if (!$time) {
        return '--';
    }

    $timestamp = strtotime($time);

    return $timestamp !== false
        ? date('h:i A', $timestamp)
        : '--';
}

function formatDateShort(?string $date): string
{
    if (!$date) {
        return '--';
    }

    $timestamp = strtotime($date);

    return $timestamp !== false
        ? date('D, M d', $timestamp)
        : '--';
}

function calculateDuration(?string $timeIn, ?string $timeOut): ?string
{
    if (!$timeIn || !$timeOut) {
        return null;
    }

    $start = strtotime($timeIn);
    $end   = strtotime($timeOut);

    if ($start === false || $end === false) {
        return null;
    }

    /*
     * Handle overnight shifts.
     */
    if ($end < $start) {
        $end += 86400;
    }

    $seconds = max(0, $end - $start);

    $hours = intdiv($seconds, 3600);
    $minutes = intdiv($seconds % 3600, 60);

    return sprintf('+%02dh %02dm', $hours, $minutes);
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

$csrf_token = generateCsrfToken();

/*
|--------------------------------------------------------------------------
| Current Date
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

/*
|--------------------------------------------------------------------------
| API: Dashboard Widgets
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['api']) &&
    $_GET['api'] === 'widgets'
) {
    try {

        /*
         * Trainee metadata
         */
        $stmt = $pdo->prepare("
            SELECT
                required_hours,
                initial_hours,
                shift_start,
                shift_end
            FROM trainees
            WHERE id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $meta = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$meta) {
            jsonResponse([
                'success' => false,
                'error'   => 'trainee_not_found'
            ], 404);
        }

        /*
         * Standard rendered hours
         */
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(hours_worked), 0)
            FROM attendance
            WHERE trainee_id = ?
              AND time_out IS NOT NULL
              AND deleted_at IS NULL
        ");

        $stmt->execute([$user_id]);

        $standard_hours = (float)$stmt->fetchColumn();

        /*
         * Verified overtime
         */
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(verified_ot_seconds), 0)
            FROM attendance
            WHERE trainee_id = ?
              AND deleted_at IS NULL
        ");

        $stmt->execute([$user_id]);

        $ot_seconds = (int)$stmt->fetchColumn();

        /*
         * Sanction hours
         */
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(hours_added), 0)
            FROM sanctions
            WHERE trainee_id = ?
              AND deleted_at IS NULL
        ");

        $stmt->execute([$user_id]);

        $sanction_hours = (float)$stmt->fetchColumn();

        /*
         * Calculate progress.
         */
        $initial_hours = (float)($meta['initial_hours'] ?? 0);

        $rendered_hours =
            $initial_hours +
            $standard_hours +
            ($ot_seconds / 3600);

        $required_hours =
            (float)($meta['required_hours'] ?: DEFAULT_REQUIRED_HOURS) +
            $sanction_hours;

        $percentage = $required_hours > 0
            ? min(100, ($rendered_hours / $required_hours) * 100)
            : 0;

        /*
         * Active attendance
         */
        $stmt = $pdo->prepare("
            SELECT
                id,
                time_in
            FROM attendance
            WHERE trainee_id = ?
              AND date = ?
              AND time_out IS NULL
              AND deleted_at IS NULL
            ORDER BY id DESC
            LIMIT 1
        ");

        $stmt->execute([
            $user_id,
            $today
        ]);

        $active = $stmt->fetch(PDO::FETCH_ASSOC);

        /*
         * Today's schedule
         */
        $stmt = $pdo->prepare("
            SELECT
                shift_start,
                shift_end,
                status
            FROM schedules
            WHERE trainee_id = ?
              AND date = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([
            $user_id,
            $today
        ]);

        $schedule_today = $stmt->fetch(PDO::FETCH_ASSOC);

        /*
         * Upcoming schedules
         */
        $stmt = $pdo->prepare("
            SELECT
                date,
                shift_start,
                shift_end,
                status
            FROM schedules
            WHERE trainee_id = ?
              AND date >= ?
              AND deleted_at IS NULL
            ORDER BY date ASC
            LIMIT 10
        ");

        $stmt->execute([
            $user_id,
            $today
        ]);

        $upcoming = $stmt->fetchAll(PDO::FETCH_ASSOC);

        /*
         * Pending OT
         */
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(a.pending_ot_seconds), 0)
            FROM attendance a
            WHERE a.trainee_id = ?
              AND a.time_out IS NOT NULL
              AND a.pending_ot_seconds > 0
              AND (
                    a.verified_ot_seconds IS NULL
                    OR a.verified_ot_seconds = 0
                  )
              AND a.deleted_at IS NULL
              AND NOT EXISTS (
                    SELECT 1
                    FROM requests r
                    WHERE r.trainee_id = a.trainee_id
                      AND r.type = 'overtime'
                      AND r.start_date = a.date
                      AND r.deleted_at IS NULL
                      AND r.supervisor_status IN ('pending', 'approved')
                      AND r.hr_status IN ('pending', 'approved')
              )
        ");

        $stmt->execute([$user_id]);

        $pending_ot_seconds = (int)$stmt->fetchColumn();

        jsonResponse([
            'success' => true,

            'progress' => [
                'rendered_hours'  => round($rendered_hours, 2),
                'required_hours'  => round($required_hours, 2),
                'remaining_hours' => round(
                    max(0, $required_hours - $rendered_hours),
                    2
                ),
                'percent' => round($percentage, 1),
                'pending_ot_hours' => round(
                    $pending_ot_seconds / 3600,
                    2
                )
            ],

            'today' => [
                'date' => $today,
                'timed_in' => $active !== false,
                'time_in' => $active['time_in'] ?? null,
                'schedule' => $schedule_today ?: null
            ],

            'upcoming' => $upcoming
        ]);

    } catch (Throwable $e) {

        error_log(
            'Dashboard widgets error: ' . $e->getMessage()
        );

        jsonResponse([
            'success' => false,
            'error'   => 'server_error'
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| API: Complete Attendance History
|--------------------------------------------------------------------------
*/

if (
    isset($_GET['api']) &&
    $_GET['api'] === 'all_logs'
) {
    try {

        /*
         * Initial hours
         */
        $stmt = $pdo->prepare("
            SELECT initial_hours
            FROM trainees
            WHERE id = ?
              AND deleted_at IS NULL
            LIMIT 1
        ");

        $stmt->execute([$user_id]);

        $initial_hours = (float)($stmt->fetchColumn() ?: 0);

        /*
         * Fetch all attendance records once.
         *
         * This replaces the old N+1 query problem.
         */
        $stmt = $pdo->prepare("
            SELECT
                id,
                date,
                time_in,
                time_out,
                hours_worked,
                pending_ot_seconds,
                verified_ot_seconds,
                verified_at,
                narrative
            FROM attendance
            WHERE trainee_id = ?
              AND deleted_at IS NULL
            ORDER BY date ASC, time_in ASC, id ASC
        ");

        $stmt->execute([$user_id]);

        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $running_total = $initial_hours;
        $processed = [];

        foreach ($logs as $log) {

            if (!empty($log['time_out'])) {

                $regular_hours =
                    (float)($log['hours_worked'] ?? 0);

                $verified_ot_hours =
                    ((int)($log['verified_ot_seconds'] ?? 0))
                    / 3600;

                $shift_total =
                    $regular_hours +
                    $verified_ot_hours;

                $running_total += $shift_total;

                $log['shift_rendered_hours'] =
                    round($shift_total, 2);

                $log['cumulative_hours'] =
                    round($running_total, 2);

                $log['formatted_duration'] =
                    calculateDuration(
                        $log['time_in'],
                        $log['time_out']
                    );

            } else {

                $log['shift_rendered_hours'] = null;
                $log['cumulative_hours'] = null;
                $log['formatted_duration'] = null;
            }

            $processed[] = $log;
        }

        /*
         * Newest first for the UI.
         */
        $processed = array_reverse($processed);

        jsonResponse([
            'success' => true,
            'data' => $processed
        ]);

    } catch (Throwable $e) {

        error_log(
            'Dashboard attendance history error: ' .
            $e->getMessage()
        );

        jsonResponse([
            'success' => false,
            'error'   => 'server_error'
        ], 500);
    }
}

/*
|--------------------------------------------------------------------------
| POST: Update Profile
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'])
) {

    if (
        !isset($_POST['csrf_token']) ||
        !verifyCsrfToken($_POST['csrf_token'])
    ) {
        header('Location: dashboard.php?error=csrf_failed');
        exit();
    }

    if ($_POST['action'] === 'update_profile') {

        $email = trim(
            (string)($_POST['email'] ?? '')
        );

        $phone = trim(
            (string)($_POST['phone'] ?? '')
        );

        $new_password =
            (string)($_POST['new_password'] ?? '');

        /*
         * Validate email.
         */
        if ($email !== '' && !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )) {
            header(
                'Location: dashboard.php?error=invalid_email'
            );
            exit();
        }

        /*
         * Basic password validation.
         */
        if (
            $new_password !== '' &&
            strlen($new_password) < 8
        ) {
            header(
                'Location: dashboard.php?error=weak_password'
            );
            exit();
        }

        $profile_picture = null;
        $old_picture = null;

        try {

            /*
             * Handle profile image.
             */
            if (
                isset($_FILES['profile_pic']) &&
                $_FILES['profile_pic']['error'] !== UPLOAD_ERR_NO_FILE
            ) {

                if (
                    $_FILES['profile_pic']['error'] !==
                    UPLOAD_ERR_OK
                ) {
                    header(
                        'Location: dashboard.php?error=upload_failed'
                    );
                    exit();
                }

                /*
                 * File size: 5 MB max.
                 */
                if (
                    (int)$_FILES['profile_pic']['size'] >
                    5 * 1024 * 1024
                ) {
                    header(
                        'Location: dashboard.php?error=image_too_large'
                    );
                    exit();
                }

                /*
                 * Verify MIME type.
                 */
                $finfo = new finfo(FILEINFO_MIME_TYPE);

                $mime = $finfo->file(
                    $_FILES['profile_pic']['tmp_name']
                );

                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($allowed[$mime])) {
                    header(
                        'Location: dashboard.php?error=invalid_image'
                    );
                    exit();
                }

                /*
                 * Fetch old profile picture.
                 */
                $stmt = $pdo->prepare("
                    SELECT profile_picture
                    FROM trainees
                    WHERE id = ?
                    LIMIT 1
                ");

                $stmt->execute([$user_id]);

                $old_picture =
                    $stmt->fetchColumn();

                /*
                 * Make sure upload directory exists.
                 */
                $upload_directory =
                    __DIR__ . '/uploads/profiles';

                if (
                    !is_dir($upload_directory) &&
                    !mkdir(
                        $upload_directory,
                        0755,
                        true
                    )
                ) {
                    header(
                        'Location: dashboard.php?error=upload_directory'
                    );
                    exit();
                }

                /*
                 * Generate random filename.
                 */
                $extension = $allowed[$mime];

                $profile_picture =
                    'profile_' .
                    $user_id .
                    '_' .
                    bin2hex(random_bytes(8)) .
                    '.' .
                    $extension;

                $destination =
                    $upload_directory .
                    DIRECTORY_SEPARATOR .
                    $profile_picture;

                if (
                    !move_uploaded_file(
                        $_FILES['profile_pic']['tmp_name'],
                        $destination
                    )
                ) {
                    header(
                        'Location: dashboard.php?error=upload_failed'
                    );
                    exit();
                }
            }

            /*
             * Build update query.
             */
            $fields = [
                'email = ?',
                'phone = ?'
            ];

            $params = [
                $email,
                $phone
            ];

            if ($profile_picture !== null) {
                $fields[] = 'profile_picture = ?';
                $params[] = $profile_picture;
            }

            /*
             * Password update.
             */
            $new_session_token = null;

            if ($new_password !== '') {

                $new_session_token =
                    bin2hex(random_bytes(32));

                $fields[] = 'password = ?';
                $params[] = password_hash(
                    $new_password,
                    PASSWORD_DEFAULT
                );

                $fields[] = 'session_token = ?';
                $params[] = $new_session_token;
            }

            $params[] = $user_id;

            $sql = "
                UPDATE trainees
                SET " . implode(', ', $fields) . "
                WHERE id = ?
                  AND deleted_at IS NULL
            ";

            $pdo->beginTransaction();

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            /*
             * Delete old image only after successful DB update.
             */
            if (
                $profile_picture !== null &&
                $old_picture
            ) {

                $old_path =
                    __DIR__ .
                    '/uploads/profiles/' .
                    basename($old_picture);

                if (
                    is_file($old_path) &&
                    $old_picture !== $profile_picture
                ) {
                    @unlink($old_path);
                }
            }

            $pdo->commit();

            /*
             * Refresh session token after password change.
             */
            if ($new_session_token !== null) {

                $_SESSION['session_token'] =
                    $new_session_token;

                session_regenerate_id(true);
            }

            header(
                'Location: dashboard.php?status=profile_updated'
            );
            exit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            /*
             * Remove newly uploaded file if DB update failed.
             */
            if ($profile_picture !== null) {

                $new_path =
                    __DIR__ .
                    '/uploads/profiles/' .
                    basename($profile_picture);

                if (is_file($new_path)) {
                    @unlink($new_path);
                }
            }

            error_log(
                'Profile update error: ' .
                $e->getMessage()
            );

            header(
                'Location: dashboard.php?error=profile_update_failed'
            );
            exit();
        }
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Trainee Information
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        username,
        email,
        phone,
        required_hours,
        initial_hours,
        shift_start,
        shift_end,
        department,
        is_cleared,
        profile_picture
    FROM trainees
    WHERE id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

$stmt->execute([$user_id]);

$trainee_meta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$trainee_meta) {
    session_destroy();

    header('Location: login.php?error=account_not_found');
    exit();
}

$trainee_id_display =
    (string)($trainee_meta['username'] ?? '');

$trainee_email =
    (string)($trainee_meta['email'] ?? '');

$trainee_phone =
    (string)($trainee_meta['phone'] ?? '');

$required_target =
    (float)(
        $trainee_meta['required_hours']
        ?: DEFAULT_REQUIRED_HOURS
    );

$initial_rendered =
    (float)($trainee_meta['initial_hours'] ?? 0);

$default_shift_start =
    $trainee_meta['shift_start'] ?? null;

$default_shift_end =
    $trainee_meta['shift_end'] ?? null;

$current_dept =
    (string)($trainee_meta['department'] ?? '');

$is_cleared =
    (bool)($trainee_meta['is_cleared'] ?? false);

$profile_pic =
    $trainee_meta['profile_picture'] ?? null;

/*
|--------------------------------------------------------------------------
| Progress Metrics
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        COALESCE(SUM(hours_worked), 0) AS standard_hours,
        COALESCE(SUM(verified_ot_seconds), 0) AS ot_seconds
    FROM attendance
    WHERE trainee_id = ?
      AND time_out IS NOT NULL
      AND deleted_at IS NULL
");

$stmt->execute([$user_id]);

$attendance_totals =
    $stmt->fetch(PDO::FETCH_ASSOC);

$total_hours_standard =
    (float)($attendance_totals['standard_hours'] ?? 0);

$total_ot_seconds =
    (int)($attendance_totals['ot_seconds'] ?? 0);

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(hours_added), 0)
    FROM sanctions
    WHERE trainee_id = ?
      AND deleted_at IS NULL
");

$stmt->execute([$user_id]);

$sanction_hours =
    (float)$stmt->fetchColumn();

$total_rendered_hours =
    $initial_rendered +
    $total_hours_standard +
    ($total_ot_seconds / 3600);

$final_required_hours =
    $required_target +
    $sanction_hours;

$percentage =
    $final_required_hours > 0
        ? min(
            100,
            ($total_rendered_hours /
                $final_required_hours) * 100
        )
        : 0;

$remaining_hours =
    max(
        0,
        $final_required_hours -
        $total_rendered_hours
    );

/*
|--------------------------------------------------------------------------
| Recent Attendance
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        date,
        time_in,
        time_out,
        hours_worked,
        verified_ot_seconds,
        verified_at
    FROM attendance
    WHERE trainee_id = ?
      AND deleted_at IS NULL
    ORDER BY date DESC, time_in DESC, id DESC
    LIMIT 5
");

$stmt->execute([$user_id]);

$recent_logs =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
 * Calculate cumulative hours efficiently.
 *
 * Instead of doing a SQL query for every log,
 * calculate the total once and subtract from the
 * total as we move backwards.
 */
$completed_total =
    $total_hours_standard +
    ($total_ot_seconds / 3600);

$running_before =
    $initial_rendered +
    $completed_total;

$recent_logs_reversed =
    array_reverse($recent_logs);

foreach ($recent_logs_reversed as &$log) {

    if (!empty($log['time_out'])) {

        $shift_hours =
            (float)($log['hours_worked'] ?? 0);

        $shift_ot =
            ((int)($log['verified_ot_seconds'] ?? 0))
            / 3600;

        $shift_total =
            $shift_hours + $shift_ot;

        $log['shift_rendered'] =
            round($shift_total, 2);

        /*
         * running_before currently represents
         * cumulative total at the end of this row.
         */
        $log['cumulative_hours'] =
            round($running_before, 2);

        $running_before -= $shift_total;

    } else {

        $log['shift_rendered'] = null;
        $log['cumulative_hours'] = null;
    }
}

unset($log);

$recent_logs =
    array_reverse($recent_logs_reversed);

/*
|--------------------------------------------------------------------------
| Pending Overtime
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| Pending Overtime
|--------------------------------------------------------------------------
|
| Shows attendance records that:
| - Already have a time out
| - Have pending overtime
| - Have not yet been verified
| - Are not deleted
| - Do NOT already have an active overtime request
|
*/

$stmt = $pdo->prepare("
    SELECT
        a.id,
        a.date,
        a.time_in,
        a.time_out,
        a.pending_ot_seconds,
        a.narrative
    FROM attendance a

    WHERE a.trainee_id = ?

      AND a.time_out IS NOT NULL

      AND a.pending_ot_seconds > 0

      AND (
            a.verified_ot_seconds IS NULL
            OR a.verified_ot_seconds = 0
          )

      AND a.deleted_at IS NULL

      AND NOT EXISTS (
            SELECT 1
            FROM requests r

            WHERE r.trainee_id = a.trainee_id

              AND r.type = 'overtime'

              AND r.start_date = a.date

              AND r.deleted_at IS NULL

              AND r.archived_at IS NULL

              AND r.supervisor_status != 'rejected'

              AND r.hr_status != 'rejected'
      )

    ORDER BY
        a.date DESC,
        a.time_in DESC
");

$stmt->execute([$user_id]);

$pending_ot_attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt->execute([$user_id]);

$pending_ot_attendance =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Today's Attendance
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        time_in
    FROM attendance
    WHERE trainee_id = ?
      AND date = ?
      AND time_out IS NULL
      AND deleted_at IS NULL
    ORDER BY id DESC
    LIMIT 1
");

$stmt->execute([
    $user_id,
    $today
]);

$attendance_record =
    $stmt->fetch(PDO::FETCH_ASSOC);

$is_timed_in =
    $attendance_record !== false;

$time_in_start =
    $attendance_record['time_in'] ?? null;

/*
|--------------------------------------------------------------------------
| Today's Schedule
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        shift_start,
        shift_end,
        status
    FROM schedules
    WHERE trainee_id = ?
      AND date = ?
      AND deleted_at IS NULL
    LIMIT 1
");

$stmt->execute([
    $user_id,
    $today
]);

$assigned_schedule =
    $stmt->fetch(PDO::FETCH_ASSOC);

$display_shift_start =
    $assigned_schedule['shift_start']
    ?? $default_shift_start;

$display_shift_end =
    $assigned_schedule['shift_end']
    ?? $default_shift_end;

$is_day_off =
    $assigned_schedule &&
    $assigned_schedule['status'] === 'day_off';

/*
|--------------------------------------------------------------------------
| Upcoming Schedule
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        date,
        shift_start,
        shift_end,
        status
    FROM schedules
    WHERE trainee_id = ?
      AND date >= ?
      AND deleted_at IS NULL
    ORDER BY date ASC
    LIMIT 7
");

$stmt->execute([
    $user_id,
    $today
]);

$upcoming_schedules =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Sanctions
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        reason,
        hours_added,
        created_at
    FROM sanctions
    WHERE trainee_id = ?
      AND deleted_at IS NULL
    ORDER BY created_at DESC
");

$stmt->execute([$user_id]);

$sanction_logs =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        title,
        message,
        image_path,
        created_at
    FROM system_notifications
    WHERE role_target IN ('all', 'trainee')
    ORDER BY created_at DESC
    LIMIT 2
");

$stmt->execute();

$notifs =
    $stmt->fetchAll(PDO::FETCH_ASSOC);

/*
|--------------------------------------------------------------------------
| Render Layout
|--------------------------------------------------------------------------
*/

renderHeader('Trainee Dashboard');

renderSidebar('dashboard');
?>

<?php
include __DIR__ .
    '/includes/components/schedule_calendar_modal.php';
?>

<!-- ========================================================= -->
<!-- DASHBOARD -->
<!-- ========================================================= -->
<style>
    /* =========================================================
   ADVANCED DASHBOARD VISUAL THEME
   Features: CSS Custom Properties, Dark Mode Engine,
   Subtle Glassmorphism, Micro-interactions, & Performance Tuning
   ========================================================= */

/* --- ROOT VARIABLES & THEME TOKENS --- */
:root {
    /* Brand Palette */
    --dash-primary: #2563eb;
    --dash-primary-hover: #1d4ed8;
    --dash-primary-dark: #1e3a8a;
    --dash-accent: #06b6d4;
    --dash-accent-hover: #0891b2;

    /* Semantic Status Indicators */
    --dash-success: #10b981;
    --dash-warning: #f59e0b;
    --dash-danger: #ef4444;
    --dash-info: #8b5cf6;

    /* Base Surfaces & Light Theme Neutral Scale */
    --dash-bg: #f8fafc;
    --dash-card-bg: rgba(255, 255, 255, 0.85);
    --dash-card-border: rgba(226, 232, 240, 0.8);
    --dash-card-hover-border: rgba(37, 99, 235, 0.3);
    
    /* Typography Colors */
    --dash-text-main: #0f172a;
    --dash-text-muted: #64748b;
    --dash-text-subtle: #94a3b8;

    /* Glassmorphism & Elevation */
    --dash-glass-blur: 16px;
    --dash-shadow-sm: 0 2px 8px -2px rgba(15, 23, 42, 0.04);
    --dash-shadow-md: 0 12px 32px -4px rgba(15, 23, 42, 0.06), 0 4px 12px -2px rgba(15, 23, 42, 0.03);
    --dash-shadow-lg: 0 20px 40px -8px rgba(15, 23, 42, 0.12), 0 8px 16px -4px rgba(37, 99, 235, 0.08);

    /* Animation Timing Curves */
    --dash-ease-spring: cubic-bezier(0.175, 0.885, 0.32, 1.275);
    --dash-ease-smooth: cubic-bezier(0.4, 0, 0.2, 1);
    --dash-transition-fast: 0.15s var(--dash-ease-smooth);
    --dash-transition-normal: 0.25s var(--dash-ease-smooth);
}

/* --- DARK MODE OPT-IN OVERRIDES --- */
@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --dash-bg: #0b0f19;
        --dash-card-bg: rgba(17, 24, 39, 0.75);
        --dash-card-border: rgba(255, 255, 255, 0.08);
        --dash-card-hover-border: rgba(59, 130, 246, 0.4);
        --dash-text-main: #f8fafc;
        --dash-text-muted: #94a3b8;
        --dash-text-subtle: #64748b;
        --dash-shadow-md: 0 12px 32px -4px rgba(0, 0, 0, 0.36);
        --dash-shadow-lg: 0 20px 40px -8px rgba(0, 0, 0, 0.5);
    }
}

[data-theme="dark"] {
    --dash-bg: #0b0f19;
    --dash-card-bg: rgba(17, 24, 39, 0.75);
    --dash-card-border: rgba(255, 255, 255, 0.08);
    --dash-card-hover-border: rgba(59, 130, 246, 0.4);
    --dash-text-main: #f8fafc;
    --dash-text-muted: #94a3b8;
    --dash-text-subtle: #64748b;
    --dash-shadow-md: 0 12px 32px -4px rgba(0, 0, 0, 0.36);
    --dash-shadow-lg: 0 20px 40px -8px rgba(0, 0, 0, 0.5);
}

/* --- MAIN DASHBOARD BACKGROUND ENGINE --- */
html {
    scroll-behavior: smooth;
    -webkit-text-size-adjust: 100%;
}

body {
    background: #ffffff;
    color: var(--dash-text-main);
    min-height: 100vh;
    font-feature-settings: "cv02", "cv03", "cv04", "cv11";
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Base Layout Wrapper */
.dashboard-content {
    position: relative;
    z-index: 1;
}

/* Hardware-accelerated background ambient blurs */
.dashboard-content::before,
.dashboard-content::after {
    content: "";
    position: fixed;
    border-radius: 50%;
    pointer-events: none;
    z-index: -1;
    filter: blur(80px);
    opacity: 0.4;
    will-change: transform;
    transform: translateZ(0);
}

.dashboard-content::before {
    width: 320px;
    height: 320px;
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.25), rgba(6, 182, 212, 0.2));
    top: 5vh;
    right: 5vw;
    animation: floatAmbient 18s ease-in-out infinite alternate;
}

.dashboard-content::after {
    width: 280px;
    height: 280px;
    background: linear-gradient(135deg, rgba(139, 92, 246, 0.2), rgba(6, 182, 212, 0.15));
    bottom: 8vh;
    left: 4vw;
    animation: floatAmbient 22s ease-in-out infinite alternate-reverse;
}

@keyframes floatAmbient {
    0% { transform: translate(0, 0) scale(1); }
    100% { transform: translate(-30px, 40px) scale(1.08); }
}

/* --- GLASSMORPHIC DASHBOARD CARDS --- */
.dashboard-card {
    background: var(--dash-card-bg);
    border: 1px solid var(--dash-card-border);
    border-radius: 20px;
    box-shadow: var(--dash-shadow-md);
    backdrop-filter: blur(var(--dash-glass-blur));
    -webkit-backdrop-filter: blur(var(--dash-glass-blur));
    transition: 
        transform var(--dash-transition-normal),
        box-shadow var(--dash-transition-normal),
        border-color var(--dash-transition-normal),
        background-color var(--dash-transition-normal);
    will-change: transform, box-shadow;
}

.dashboard-card:hover {
    transform: translateY(-3px);
    box-shadow: var(--dash-shadow-lg);
    border-color: var(--dash-card-hover-border);
}

/* --- GRADIENT SURFACES & CONTAINERS --- */
.dashboard-gradient {
    background: linear-gradient(135deg, var(--dash-primary-dark) 0%, var(--dash-primary) 60%, var(--dash-accent) 100%);
    color: #ffffff;
    box-shadow: 0 8px 20px -4px rgba(37, 99, 235, 0.3);
}

/* --- DYNAMIC PROGRESS BARS --- */
.progress-container {
    width: 100%;
    height: 8px;
    background: rgba(148, 163, 184, 0.2);
    border-radius: 9999px;
    overflow: hidden;
    position: relative;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, var(--dash-primary) 0%, var(--dash-accent) 100%);
    border-radius: 9999px;
    box-shadow: 0 0 12px rgba(37, 99, 235, 0.4);
    transition: width 0.6s var(--dash-ease-smooth);
    position: relative;
}

.progress-bar::after {
    content: "";
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
    animation: shimmer 2s infinite linear;
}

@keyframes shimmer {
    0% { transform: translateX(-100%); }
    100% { transform: translateX(100%); }
}

/* --- ICON CONTAINERS --- */
.icon-box {
    display: inline-flex;
    items-center: center;
    justify-content: center;
    width: 42px;
    height: 42px;
    border-radius: 12px;
    transition: transform var(--dash-transition-fast);
}

.dashboard-card:hover .icon-box {
    transform: scale(1.06);
}

.icon-box-blue {
    background: rgba(37, 99, 235, 0.12);
    color: var(--dash-primary);
}

.icon-box-cyan {
    background: rgba(6, 182, 212, 0.12);
    color: var(--dash-accent-hover);
}

.icon-box-green {
    background: rgba(16, 185, 129, 0.12);
    color: var(--dash-success);
}

.icon-box-purple {
    background: rgba(139, 92, 246, 0.12);
    color: var(--dash-info);
}

/* --- STATUS BADGES --- */
.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 9999px;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    text-transform: uppercase;
}

.status-active {
    background: rgba(16, 185, 129, 0.12);
    color: var(--dash-success);
    border: 1px solid rgba(16, 185, 129, 0.25);
}

.status-pending {
    background: rgba(245, 158, 11, 0.12);
    color: var(--dash-warning);
    border: 1px solid rgba(245, 158, 11, 0.25);
}

/* --- INTERACTIVE BUTTONS --- */
.dashboard-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-weight: 600;
    border-radius: 12px;
    cursor: pointer;
    border: 1px solid transparent;
    transition: 
        transform var(--dash-transition-fast),
        box-shadow var(--dash-transition-fast),
        background-color var(--dash-transition-fast);
}

.dashboard-btn:hover {
    transform: translateY(-1.5px);
    box-shadow: 0 6px 16px -2px rgba(37, 99, 235, 0.22);
}

.dashboard-btn:active {
    transform: translateY(0);
    box-shadow: 0 2px 6px -1px rgba(37, 99, 235, 0.15);
}

/* --- SCHEDULE & LIST ITEMS --- */
.schedule-item {
    border: 1px solid var(--dash-card-border);
    background: rgba(255, 255, 255, 0.04);
    border-radius: 14px;
    padding: 12px 16px;
    transition: 
        background-color var(--dash-transition-fast),
        border-color var(--dash-transition-fast),
        transform var(--dash-transition-fast);
}

.schedule-item:hover {
    background: rgba(37, 99, 235, 0.05);
    border-color: var(--dash-card-hover-border);
    transform: translateX(4px);
}

/* --- NOTIFICATIONS --- */
.notification-card {
    background: linear-gradient(135deg, rgba(37, 99, 235, 0.06) 0%, rgba(6, 182, 212, 0.04) 100%);
    border: 1px solid rgba(37, 99, 235, 0.18);
    border-radius: 16px;
    padding: 16px;
}

/* --- TYPOGRAPHY & SECTIONS --- */
.dashboard-section-title {
    color: var(--dash-text-main);
    font-size: 1.125rem;
    font-weight: 700;
    letter-spacing: -0.02em;
}

/* --- ACCESSIBILITY & RESPONSIVENESS --- */
@media (prefers-reduced-motion: reduce) {
    *, ::before, ::after {
        animation-duration: 0.01ms !important;
        animation-iteration-count: 1 !important;
        transition-duration: 0.01ms !important;
        scroll-behavior: auto !important;
    }
}

@media (max-width: 768px) {
    .dashboard-card {
        border-radius: 16px;
    }

    .dashboard-content::before {
        width: 180px;
        height: 180px;
        filter: blur(60px);
    }

    .dashboard-content::after {
        width: 150px;
        height: 150px;
        filter: blur(60px);
    }
}
</style>
<div
    id="trainee-dashboard"
    class="grid grid-cols-1 lg:grid-cols-3 gap-6"
    data-user-id="<?php echo $user_id; ?>"
>

    <!-- ===================================================== -->
    <!-- LEFT COLUMN -->
    <!-- ===================================================== -->

    <div class="space-y-6">

        <!-- PROFILE -->
        <section
            class="card p-5 text-center shadow-sm border border-slate-200/80 rounded-2xl bg-white"
        >

            <div
                class="relative inline-block group mb-3"
            >

                <?php
                $profile_path = null;

                if (
                    $profile_pic &&
                    is_file(
                        __DIR__ .
                        '/uploads/profiles/' .
                        basename($profile_pic)
                    )
                ) {
                    $profile_path =
                        'uploads/profiles/' .
                        rawurlencode(
                            basename($profile_pic)
                        );
                }
                ?>

                <?php if ($profile_path): ?>

                    <img
                        src="<?php echo e($profile_path); ?>"
                        alt="Profile photo"
                        class="w-20 h-20 rounded-full object-cover border-2 border-purple-200 shadow-sm mx-auto"
                    >

                <?php else: ?>

                    <div
                        class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto border-2 border-purple-200 shadow-sm"
                    >
                        <span
                            class="text-2xl font-black text-[#6D3089] uppercase"
                        >
                            <?php
                            echo e(
                                strtoupper(
                                    mb_substr(
                                        $full_name,
                                        0,
                                        1
                                    )
                                )
                            );
                            ?>
                        </span>
                    </div>

                <?php endif; ?>

                <button
                    type="button"
                    onclick="document.getElementById('profile_pic_input').click()"
                    class="absolute bottom-0 right-0 p-1.5 bg-[#6D3089] text-white rounded-full shadow-md opacity-0 group-hover:opacity-100 transition-all hover:scale-105"
                    aria-label="Change profile photo"
                >
                    <svg
                        class="w-3.5 h-3.5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2.5"
                            d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0118.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
                        />
                    </svg>
                </button>

            </div>

            <h2
                class="text-lg font-bold text-slate-900 leading-snug"
            >
                <?php echo e($full_name); ?>
            </h2>

            <p class="text-xs text-slate-500 font-medium">
                <?php echo e($current_dept); ?> Department
            </p>

            <div
                class="mt-2 flex flex-wrap justify-center items-center gap-1.5"
            >

                <span
                    class="px-2.5 py-0.5 bg-slate-100 rounded-md text-[10px] font-bold text-slate-600 tracking-wide"
                >
                    ID:
                    <?php echo e($trainee_id_display); ?>
                </span>

                <?php if ($is_cleared): ?>

                    <span
                        class="px-2.5 py-0.5 bg-emerald-50 text-emerald-700 rounded-md text-[10px] font-bold uppercase tracking-wide flex items-center gap-1 border border-emerald-200"
                    >
                        <svg
                            class="w-3 h-3"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2.5"
                                d="M5 13l4 4L19 7"
                            />
                        </svg>
                        Cleared
                    </span>

                <?php endif; ?>

            </div>

            <!-- DTR -->
            <div
                class="mt-4 border-t border-slate-100 pt-3 text-left"
            >

                <label
                    class="text-[10px] font-bold uppercase text-slate-400 tracking-wider block mb-1.5"
                >
                    Generate DTR
                </label>

                <div class="flex flex-col gap-2">

                    <div class="grid grid-cols-2 gap-2">

                        <select
                            id="dtr_month"
                            class="w-full text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-[#6D3089]"
                        >

                            <?php
                            for ($i = 0; $i < 6; $i++) {

                                $month_date =
                                    date(
                                        'Y-m',
                                        strtotime(
                                            "-{$i} months"
                                        )
                                    );

                                $month_label =
                                    date(
                                        'F Y',
                                        strtotime(
                                            "-{$i} months"
                                        )
                                    );
                                ?>

                                <option
                                    value="<?php echo e($month_date); ?>"
                                >
                                    <?php echo e($month_label); ?>
                                </option>

                            <?php } ?>

                        </select>

                        <select
                            id="dtr_period"
                            class="w-full text-xs font-semibold text-slate-700 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 focus:outline-none focus:border-[#6D3089]"
                        >
                            <option value="1st_half">
                                1st Half (1-15)
                            </option>

                            <option value="2nd_half">
                                2nd Half (16-End)
                            </option>
                        </select>

                    </div>

                    <button
                        type="button"
                        onclick="triggerDtrExport()"
                        class="w-full inline-flex items-center justify-center gap-1.5 px-3 py-2 bg-purple-50 text-[#6D3089] border border-purple-200/60 rounded-xl text-[10px] font-bold uppercase tracking-wider hover:bg-[#6D3089] hover:text-white transition-all"
                    >
                        <svg
                            class="w-3.5 h-3.5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            />
                        </svg>

                        Download PDF
                    </button>

                </div>

            </div>

            <!-- ACTIONS -->
            <div class="mt-3 flex gap-2">

                <button
                    type="button"
                    onclick="openScheduleCalendar(
                        <?php echo $user_id; ?>,
                        <?php echo htmlspecialchars(
                            json_encode($full_name),
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>
                    )"
                    class="flex-1 inline-flex items-center justify-center gap-1 px-3 py-2 bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-[10px] font-bold uppercase tracking-wider hover:bg-slate-100 transition-all"
                >
                    Calendar
                </button>

                <button
                    type="button"
                    onclick="openProfileModal()"
                    class="flex-1 inline-flex items-center justify-center gap-1 px-3 py-2 bg-slate-50 text-slate-700 border border-slate-200 rounded-xl text-[10px] font-bold uppercase tracking-wider hover:bg-slate-100 transition-all"
                >
                    Edit Profile
                </button>

            </div>

        </section>

        <!-- PROGRESS -->
        <section
            class="card p-5 shadow-sm border border-slate-200/80 rounded-2xl bg-white"
        >

            <div
                class="flex justify-between items-end mb-3"
            >

                <div>

                    <span
                        class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
                    >
                        Total Progress
                    </span>

                    <h3
                        id="total-rendered-hours"
                        class="text-3xl font-black text-slate-900 mt-0.5"
                    >
                        <?php echo number_format(
                            $total_rendered_hours,
                            1
                        ); ?>

                        <span
                            class="text-xs font-bold text-slate-400 ml-1"
                        >
                            HRS
                        </span>
                    </h3>

                </div>

                <div class="text-right">

                    <span
                        class="text-[10px] font-bold text-slate-400 uppercase tracking-wider"
                    >
                        Target
                    </span>

                    <h3
                        id="required-hours"
                        class="text-sm font-bold text-slate-600 mt-0.5"
                    >
                        <?php echo number_format(
                            $final_required_hours,
                            1
                        ); ?>
                        hrs
                    </h3>

                </div>

            </div>

            <div
                class="w-full bg-slate-100 h-2.5 rounded-full overflow-hidden"
            >
                <div
                    id="progress-bar"
                    class="bg-[#6D3089] h-full rounded-full transition-all duration-700"
                    style="width: <?php echo e((string)$percentage); ?>%;"
                ></div>
            </div>

            <div
                class="flex justify-between items-center mt-2"
            >

                <span
                    id="progress-percent"
                    class="text-[10px] font-bold text-[#6D3089]"
                >
                    <?php echo number_format(
                        $percentage,
                        1
                    ); ?>% Completed
                </span>

                <span
                    id="remaining-hours"
                    class="text-[10px] font-bold text-slate-400"
                >
                    <?php echo number_format(
                        $remaining_hours,
                        1
                    ); ?>
                    hrs remaining
                </span>

            </div>

        </section>

        <!-- SANCTIONS -->
        <?php if (!empty($sanction_logs)): ?>

            <section
                class="card p-4 border-l-4 border-l-rose-500 bg-rose-50/30 rounded-2xl shadow-sm border border-slate-200/60"
            >

                <div
                    class="flex items-center justify-between mb-3"
                >

                    <div class="flex items-center gap-2">

                        <svg
                            class="w-4 h-4 text-rose-500"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                            />
                        </svg>

                        <h4
                            class="text-xs font-bold text-rose-800 uppercase tracking-wider"
                        >
                            Sanction History
                        </h4>

                    </div>

                    <span
                        class="text-[10px] font-extrabold text-rose-600 uppercase bg-rose-100 px-2 py-0.5 rounded-md"
                    >
                        +<?php echo number_format(
                            $sanction_hours,
                            1
                        ); ?>H Penalty
                    </span>

                </div>

                <div class="space-y-2">

                    <?php foreach ($sanction_logs as $sanction): ?>

                        <div
                            class="p-2.5 bg-white/80 rounded-xl border border-rose-100 flex justify-between items-center"
                        >

                            <div>

                                <p
                                    class="text-xs font-bold text-slate-800 uppercase"
                                >
                                    <?php echo e(
                                        $sanction['reason']
                                    ); ?>
                                </p>

                                <p
                                    class="text-[9px] text-slate-400 font-semibold uppercase mt-0.5"
                                >
                                    <?php echo e(
                                        date(
                                            'M d, Y',
                                            strtotime(
                                                $sanction['created_at']
                                            )
                                        )
                                    ); ?>
                                </p>

                            </div>

                            <span
                                class="text-xs font-black text-rose-600 bg-rose-50 px-2 py-0.5 rounded-md"
                            >
                                +<?php echo number_format(
                                    (float)$sanction['hours_added'],
                                    1
                                ); ?>h
                            </span>

                        </div>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endif; ?>

        <!-- PENDING OT -->
        <?php if (!empty($pending_ot_attendance)): ?>

            <section
                class="card bg-amber-50/40 border border-amber-200/80 rounded-2xl shadow-sm overflow-hidden"
            >

                <div
                    class="p-3.5 border-b border-amber-100 flex items-center justify-between"
                >

                    <div class="flex items-center gap-2">

                        <div
                            class="p-1.5 bg-amber-100 rounded-lg text-amber-700"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                                />
                            </svg>
                        </div>

                        <div>

                            <h4
                                class="text-xs font-bold text-slate-800 uppercase tracking-wide"
                            >
                                Pending Overtime
                            </h4>

                            <p
                                class="text-[10px] text-amber-700 font-semibold"
                            >
                                <?php echo count(
                                    $pending_ot_attendance
                                ); ?>
                                request(s) awaiting filing
                            </p>

                        </div>

                    </div>

                    <a
                        href="requests.php"
                        class="px-3 py-1.5 bg-amber-500 text-white rounded-lg text-[10px] font-bold uppercase tracking-wider hover:bg-amber-600 transition-all shadow-sm"
                    >
                        File OT
                    </a>

                </div>

                <div
                    class="divide-y divide-amber-100/60 max-h-48 overflow-y-auto"
                >

                    <?php foreach ($pending_ot_attendance as $ot): ?>

                        <?php
                        $ot_hours =
                            round(
                                ((int)$ot['pending_ot_seconds']) /
                                3600,
                                2
                            );
                        ?>

                        <a
                            href="requests.php?open_request_modal=1&preload_type=overtime&preload_date=<?php echo rawurlencode($ot['date']); ?>&preload_time_out=<?php echo rawurlencode($ot['time_out']); ?>&preload_pending_ot_seconds=<?php echo (int)$ot['pending_ot_seconds']; ?>&preload_narrative=<?php echo rawurlencode($ot['narrative'] ?? ''); ?>"
                            class="p-3 flex items-center justify-between hover:bg-amber-100/50 transition-colors group"
                        >

                            <div>

                                <p
                                    class="text-xs font-bold text-slate-800 group-hover:text-[#6D3089] transition-colors uppercase"
                                >
                                    <?php echo e(
                                        formatDateShort(
                                            $ot['date']
                                        )
                                    ); ?>
                                </p>

                                <p
                                    class="text-[10px] text-slate-500 font-medium"
                                >
                                    <?php echo e(
                                        formatTime(
                                            $ot['time_in']
                                        )
                                    ); ?>

                                    -

                                    <?php echo e(
                                        formatTime(
                                            $ot['time_out']
                                        )
                                    ); ?>
                                </p>

                                <?php if (!empty($ot['narrative'])): ?>

                                    <p
                                        class="text-[10px] text-purple-700 font-medium italic mt-0.5"
                                    >
                                        “<?php echo e(
                                            $ot['narrative']
                                        ); ?>”
                                    </p>

                                <?php endif; ?>

                            </div>

                            <div
                                class="text-right flex items-center gap-2"
                            >

                                <div>

                                    <span
                                        class="text-sm font-black text-amber-600"
                                    >
                                        +<?php echo $ot_hours; ?>h
                                    </span>

                                    <span
                                        class="block text-[8px] font-bold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded uppercase mt-0.5"
                                    >
                                        Pending
                                    </span>

                                </div>

                                <svg
                                    class="w-4 h-4 text-slate-400 group-hover:text-[#6D3089] transition-colors"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M9 5l7 7-7 7"
                                    />
                                </svg>

                            </div>

                        </a>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php endif; ?>

    </div>

    <!-- ===================================================== -->
    <!-- RIGHT COLUMN -->
    <!-- ===================================================== -->

    <div class="lg:col-span-2 space-y-6">

        <!-- TODAY'S SHIFT -->
        <section
            class="card p-5 shadow-sm border border-slate-200/80 rounded-2xl bg-white flex flex-col sm:flex-row items-center justify-between gap-4"
        >

            <div>

                <span
                    class="text-[10px] font-bold text-slate-400 uppercase tracking-wider block mb-1"
                >
                    Today's Shift
                </span>

                <?php if ($is_day_off): ?>

                    <h3
                        class="text-xl font-bold text-rose-500 uppercase"
                    >
                        Day Off
                    </h3>

                    <p
                        class="text-[10px] font-semibold text-rose-400 uppercase tracking-wide mt-0.5"
                    >
                        Scheduled Rest Day
                    </p>

                <?php else: ?>

                    <h3
                        class="text-xl font-bold text-slate-800"
                    >

                        <?php
                        echo e(
                            formatTime(
                                $display_shift_start
                            )
                        );
                        ?>

                        -

                        <?php
                        echo e(
                            formatTime(
                                $display_shift_end
                            )
                        );
                        ?>

                    </h3>

                    <span
                        class="text-[9px] font-bold text-[#6D3089] uppercase tracking-wider bg-purple-50 border border-purple-100 px-2 py-0.5 rounded-md mt-1 inline-block"
                    >
                        <?php
                        echo $assigned_schedule
                            ? 'Assigned Duty Schedule'
                            : 'Default Duty Shift';
                        ?>
                    </span>

                <?php endif; ?>

            </div>

            <div
                class="text-center sm:text-right"
            >

                <?php if ($is_timed_in): ?>

                    <div
                        class="inline-flex items-center gap-1.5 px-3 py-1 bg-emerald-50 border border-emerald-200 rounded-full text-emerald-700 mb-1.5"
                    >

                        <span
                            class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"
                        ></span>

                        <span
                            class="text-[10px] font-extrabold uppercase tracking-wider"
                        >
                            Active Shift
                        </span>

                    </div>

                    <div
                        id="live-timer"
                        class="text-2xl font-black text-slate-800 tabular-nums"
                    >
                        00:00:00
                    </div>

                <?php else: ?>

                    <span
                        class="px-3 py-1.5 bg-slate-100 text-slate-500 rounded-full font-bold text-[10px] uppercase tracking-wider inline-block"
                    >
                        Off Duty
                    </span>

                <?php endif; ?>

            </div>

        </section>

        <!-- UPCOMING -->
        <section
            class="card p-5 shadow-sm border border-slate-200/80 rounded-2xl bg-white"
        >

            <div
                class="flex items-center justify-between mb-3"
            >

                <h4
                    class="text-xs font-bold text-slate-700 uppercase tracking-wider"
                >
                    Upcoming Duty Schedule
                </h4>

                <span
                    class="text-[9px] font-extrabold text-slate-400 uppercase tracking-widest bg-slate-50 border border-slate-100 px-2 py-0.5 rounded-md"
                >
                    Next 7 Days
                </span>

            </div>

            <div
                id="upcoming-schedules"
                class="flex gap-3 overflow-x-auto pb-1"
            >

                <?php if (empty($upcoming_schedules)): ?>

                    <div
                        class="w-full py-6 text-center text-slate-400 italic text-xs bg-slate-50 rounded-xl border border-dashed border-slate-200"
                    >
                        No upcoming schedules found.
                    </div>

                <?php else: ?>

                    <?php foreach ($upcoming_schedules as $schedule): ?>

                        <div
                            class="flex-shrink-0 w-28 p-3 rounded-xl border <?php echo $schedule['status'] === 'day_off'
                                ? 'bg-rose-50/30 border-rose-100'
                                : 'bg-slate-50/70 border-slate-200/70'; ?>"
                        >

                            <p
                                class="text-[9px] font-bold text-slate-400 uppercase mb-1"
                            >
                                <?php echo e(
                                    date(
                                        'D, M d',
                                        strtotime(
                                            $schedule['date']
                                        )
                                    )
                                ); ?>
                            </p>

                            <?php if (
                                $schedule['status'] === 'day_off'
                            ): ?>

                                <p
                                    class="text-xs font-bold text-rose-500 uppercase"
                                >
                                    Day Off
                                </p>

                            <?php else: ?>

                                <p
                                    class="text-xs font-bold text-slate-800 uppercase"
                                >
                                    Duty
                                </p>

                                <p
                                    class="text-[9px] text-slate-500 font-semibold mt-1 leading-snug"
                                >
                                    <?php echo e(
                                        formatTime(
                                            $schedule['shift_start']
                                        )
                                    ); ?>

                                    <br>

                                    <?php echo e(
                                        formatTime(
                                            $schedule['shift_end']
                                        )
                                    ); ?>
                                </p>

                            <?php endif; ?>

                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>

            </div>

        </section>

        <!-- RECENT LOGS + ANNOUNCEMENTS -->
        <div
            class="grid grid-cols-1 md:grid-cols-2 gap-6"
        >

            <!-- RECENT LOGS -->
            <section
                class="card shadow-sm border border-slate-200/80 rounded-2xl bg-white overflow-hidden flex flex-col"
            >

                <div
                    class="p-4 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between"
                >

                    <h4
                        class="text-xs font-bold text-slate-700 uppercase tracking-wider"
                    >
                        Recent Logs
                    </h4>

                    <button
                        type="button"
                        onclick="AttendanceModal.open()"
                        class="text-[10px] font-extrabold text-[#6D3089] uppercase tracking-wider hover:underline"
                    >
                        View All →
                    </button>

                </div>

                <div
                    id="recent-logs-container"
                    class="divide-y divide-slate-100"
                >

                    <?php if (empty($recent_logs)): ?>

                        <div
                            class="p-6 text-center text-slate-400 italic text-xs"
                        >
                            No recent duty logs found.
                        </div>

                    <?php else: ?>

                        <?php foreach ($recent_logs as $log): ?>

                            <div
                                class="p-3.5 flex justify-between items-center hover:bg-slate-50/80 transition-colors"
                            >

                                <div>

                                    <p
                                        class="text-xs font-bold text-slate-800 uppercase"
                                    >
                                        <?php echo e(
                                            formatDateShort(
                                                $log['date']
                                            )
                                        ); ?>
                                    </p>

                                    <div
                                        class="flex items-center gap-1.5 mt-0.5"
                                    >

                                        <span
                                            class="text-[10px] text-slate-500 font-medium"
                                        >

                                            <?php echo e(
                                                formatTime(
                                                    $log['time_in']
                                                )
                                            ); ?>

                                            -

                                            <?php echo !empty(
                                                $log['time_out']
                                            )
                                                ? e(
                                                    formatTime(
                                                        $log['time_out']
                                                    )
                                                )
                                                : 'Active'; ?>

                                        </span>

                                        <?php if (
                                            !empty(
                                                $log['verified_at']
                                            )
                                        ): ?>

                                            <span
                                                class="text-emerald-600 text-[9px] font-bold"
                                            >
                                                ✓ Verified
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </div>

                                <div
                                    class="text-right"
                                >

                                    <?php if (
                                        !empty(
                                            $log['time_out']
                                        )
                                    ): ?>

                                        <span
                                            class="text-xs font-bold text-[#6D3089]"
                                        >
                                            <?php echo e(
                                                calculateDuration(
                                                    $log['time_in'],
                                                    $log['time_out']
                                                ) ?? '--'
                                            ); ?>
                                        </span>

                                        <p
                                            class="text-[10px] font-bold text-slate-400 mt-0.5"
                                        >
                                            Total:
                                            <span
                                                class="text-slate-700 font-black"
                                            >
                                                <?php echo number_format(
                                                    (float)$log['cumulative_hours'],
                                                    2
                                                ); ?>
                                                hrs
                                            </span>
                                        </p>

                                    <?php else: ?>

                                        <span
                                            class="inline-flex items-center gap-1 text-[9px] font-extrabold text-amber-700 bg-amber-50 border border-amber-200/60 px-2 py-0.5 rounded-md uppercase tracking-wider"
                                        >
                                            <span
                                                class="w-1.5 h-1.5 rounded-full bg-amber-500 animate-pulse"
                                            ></span>
                                            In Progress
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </div>

                <div
                    class="mt-auto p-3 bg-slate-50/50 border-t border-slate-100"
                >

                    <button
                        type="button"
                        onclick="AttendanceModal.open()"
                        class="w-full py-1.5 bg-white border border-slate-200 text-slate-600 rounded-xl text-[10px] font-bold uppercase tracking-wider hover:bg-slate-100 transition-all"
                    >
                        Open Complete Attendance History
                    </button>

                </div>

            </section>

            <!-- ANNOUNCEMENTS -->
            <section
                class="card shadow-sm border border-slate-200/80 rounded-2xl bg-white overflow-hidden"
            >

                <div
                    class="p-4 border-b border-slate-100 bg-slate-50/50"
                >
                    <h4
                        class="text-xs font-bold text-slate-700 uppercase tracking-wider"
                    >
                        Announcements
                    </h4>
                </div>

                <div class="p-4">

                    <?php if (empty($notifs)): ?>

                        <div
                            class="text-center py-6"
                        >
                            <p
                                class="text-xs text-slate-400 italic"
                            >
                                No announcements today.
                            </p>
                        </div>

                    <?php else: ?>

                        <div class="space-y-4">

                            <?php foreach ($notifs as $notification): ?>

                                <article
                                    class="border-b border-slate-100 pb-3 last:border-0 last:pb-0"
                                >

                                    <?php if (
                                        !empty(
                                            $notification['image_path']
                                        )
                                    ): ?>

                                        <div
                                            class="mb-2 rounded-xl overflow-hidden border border-slate-100 aspect-video bg-slate-50"
                                        >

                                            <img
                                                src="uploads/announcements/<?php echo rawurlencode(
                                                    basename(
                                                        $notification['image_path']
                                                    )
                                                ); ?>"
                                                alt="<?php echo e(
                                                    $notification['title']
                                                ); ?>"
                                                class="w-full h-full object-cover"
                                                loading="lazy"
                                            >

                                        </div>

                                    <?php endif; ?>

                                    <h5
                                        class="text-xs font-bold text-slate-800 uppercase leading-tight"
                                    >
                                        <?php echo e(
                                            $notification['title']
                                        ); ?>
                                    </h5>

                                    <p
                                        class="text-xs text-slate-500 mt-1 leading-relaxed line-clamp-2"
                                    >
                                        <?php echo e(
                                            $notification['message']
                                        ); ?>
                                    </p>

                                    <time
                                        class="text-[9px] text-slate-400 font-bold uppercase mt-1 block"
                                    >
                                        <?php echo e(
                                            date(
                                                'M d, h:i A',
                                                strtotime(
                                                    $notification['created_at']
                                                )
                                            )
                                        ); ?>
                                    </time>

                                </article>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                </div>

            </section>

        </div>

    </div>

</div>

<!-- ========================================================= -->
<!-- ATTENDANCE HISTORY MODAL -->
<!-- ========================================================= -->

<div
    id="full_logs_modal"
    class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-50 hidden opacity-0 transition-opacity duration-200 flex items-center justify-center p-4"
    aria-hidden="true"
>

    <div
        id="modal_dialog"
        class="bg-white rounded-2xl max-w-4xl w-full max-h-[85vh] flex flex-col shadow-2xl border border-slate-200 overflow-hidden transform scale-95 transition-transform duration-200"
    >

        <div
            class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center"
        >

            <div>

                <h3
                    class="text-sm font-bold text-slate-800 uppercase tracking-wider"
                >
                    Complete Attendance History
                </h3>

                <p
                    class="text-[10px] text-slate-500 font-semibold"
                >
                    Complete record of rendered hours
                </p>

            </div>

            <button
                type="button"
                onclick="AttendanceModal.close()"
                class="text-slate-400 hover:text-slate-600 text-lg font-bold p-1 leading-none"
                aria-label="Close"
            >
                &times;
            </button>

        </div>

        <div
            id="full_logs_container"
            class="p-4 overflow-auto flex-1"
        >

            <div
                class="text-center py-8 text-xs text-slate-400"
            >
                Loading attendance history...
            </div>

        </div>

        <div
            class="p-3 bg-slate-50 border-t border-slate-100 text-right"
        >

            <button
                type="button"
                onclick="AttendanceModal.close()"
                class="px-4 py-2 bg-slate-200 text-slate-700 rounded-xl text-xs font-bold uppercase tracking-wider hover:bg-slate-300 transition-all"
            >
                Close
            </button>

        </div>

    </div>

</div>

<!-- ========================================================= -->
<!-- PROFILE IMAGE FORM -->
<!-- ========================================================= -->

<form
    id="profile_pic_form"
    method="POST"
    enctype="multipart/form-data"
    class="hidden"
>

    <input
        type="hidden"
        name="action"
        value="update_profile"
    >

    <input
        type="hidden"
        name="csrf_token"
        value="<?php echo e($csrf_token); ?>"
    >

    <input
        type="hidden"
        name="email"
        value="<?php echo e($trainee_email); ?>"
    >

    <input
        type="hidden"
        name="phone"
        value="<?php echo e($trainee_phone); ?>"
    >

    <input
        type="file"
        id="profile_pic_input"
        name="profile_pic"
        accept="image/jpeg,image/png,image/webp"
        onchange="submitProfilePicture()"
    >

</form>

<script>
'use strict';

/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

const DashboardConfig = Object.freeze({
    api: 'dashboard.php',
    refreshInterval: 60000,
    timerInterval: 1000
});

/*
|--------------------------------------------------------------------------
| Utility
|--------------------------------------------------------------------------
*/

function escapeHtml(value) {

    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/*
|--------------------------------------------------------------------------
| DTR Export
|--------------------------------------------------------------------------
*/

function triggerDtrExport() {

    const month =
        document.getElementById('dtr_month')?.value;

    const period =
        document.getElementById('dtr_period')?.value;

    if (!month || !period) {
        return;
    }

    const url =
        'export_dtr.php' +
        '?id=<?php echo $user_id; ?>' +
        '&month=' +
        encodeURIComponent(month) +
        '&period=' +
        encodeURIComponent(period);

    window.location.href = url;
}

/*
|--------------------------------------------------------------------------
| Profile Picture
|--------------------------------------------------------------------------
*/

function submitProfilePicture() {

    const input =
        document.getElementById('profile_pic_input');

    if (!input || !input.files.length) {
        return;
    }

    const file = input.files[0];

    const allowed = [
        'image/jpeg',
        'image/png',
        'image/webp'
    ];

    if (!allowed.includes(file.type)) {

        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: 'Invalid Image',
                text: 'Please upload a JPG, PNG, or WEBP image.'
            });
        }

        input.value = '';
        return;
    }

    if (file.size > 5 * 1024 * 1024) {

        if (window.Swal) {
            Swal.fire({
                icon: 'error',
                title: 'Image Too Large',
                text: 'Maximum profile image size is 5 MB.'
            });
        }

        input.value = '';
        return;
    }

    document
        .getElementById('profile_pic_form')
        .submit();
}

/*
|--------------------------------------------------------------------------
| Profile Modal
|--------------------------------------------------------------------------
*/

async function openProfileModal() {

    if (!window.Swal) {
        return;
    }

    const result = await Swal.fire({

        title: 'Edit Profile',

        html: `
            <div class="text-left space-y-3">

                <div>
                    <label class="text-[10px] font-bold uppercase text-slate-500">
                        Email
                    </label>

                    <input
                        type="email"
                        id="p_email"
                        class="swal2-input w-full m-0 mt-1 text-xs"
                        value="<?php echo e($trainee_email); ?>"
                    >
                </div>

                <div>
                    <label class="text-[10px] font-bold uppercase text-slate-500">
                        Phone
                    </label>

                    <input
                        type="text"
                        id="p_phone"
                        class="swal2-input w-full m-0 mt-1 text-xs"
                        value="<?php echo e($trainee_phone); ?>"
                    >
                </div>

                <div>
                    <label class="text-[10px] font-bold uppercase text-slate-500">
                        New Password
                    </label>

                    <input
                        type="password"
                        id="p_pass"
                        class="swal2-input w-full m-0 mt-1 text-xs"
                        minlength="8"
                        autocomplete="new-password"
                    >

                    <p class="text-[9px] text-slate-400 mt-1">
                        Leave blank to keep your current password.
                    </p>
                </div>

            </div>
        `,

        showCancelButton: true,

        confirmButtonText: 'Update Profile',

        cancelButtonText: 'Cancel',

        confirmButtonColor: '#6D3089',

        preConfirm: () => {

            const email =
                document.getElementById('p_email')
                    ?.value
                    .trim();

            const phone =
                document.getElementById('p_phone')
                    ?.value
                    .trim();

            const password =
                document.getElementById('p_pass')
                    ?.value ?? '';

            if (
                email &&
                !/^[^\s@]+@[^\s@]+\.[^\s@]+$/
                    .test(email)
            ) {

                Swal.showValidationMessage(
                    'Please enter a valid email address.'
                );

                return false;
            }

            if (
                password &&
                password.length < 8
            ) {

                Swal.showValidationMessage(
                    'Password must contain at least 8 characters.'
                );

                return false;
            }

            return {
                email,
                phone,
                password
            };
        }

    });

    if (!result.isConfirmed || !result.value) {
        return;
    }

    const form =
        document.createElement('form');

    form.method = 'POST';

    form.action = 'dashboard.php';

    const fields = {
        action: 'update_profile',
        csrf_token: <?php
            echo json_encode(
                $csrf_token,
                JSON_HEX_TAG |
                JSON_HEX_AMP |
                JSON_HEX_APOS |
                JSON_HEX_QUOT
            );
        ?>,
        email: result.value.email,
        phone: result.value.phone,
        new_password: result.value.password
    };

    Object.entries(fields).forEach(
        ([name, value]) => {

            const input =
                document.createElement('input');

            input.type = 'hidden';
            input.name = name;
            input.value = value ?? '';

            form.appendChild(input);
        }
    );

    document.body.appendChild(form);

    form.submit();
}

/*
|--------------------------------------------------------------------------
| Attendance Modal
|--------------------------------------------------------------------------
*/

const AttendanceModal = (() => {

    const modal =
        document.getElementById(
            'full_logs_modal'
        );

    const dialog =
        document.getElementById(
            'modal_dialog'
        );

    const container =
        document.getElementById(
            'full_logs_container'
        );

    let loaded = false;
    let loading = false;

    function open() {

        if (!modal || !dialog) {
            return;
        }

        modal.classList.remove('hidden');

        modal.setAttribute(
            'aria-hidden',
            'false'
        );

        requestAnimationFrame(() => {

            modal.classList.remove(
                'opacity-0'
            );

            dialog.classList.remove(
                'scale-95'
            );

            dialog.classList.add(
                'scale-100'
            );

        });

        document.body.style.overflow =
            'hidden';

        if (!loaded && !loading) {
            fetchLogs();
        }
    }

    function close() {

        if (!modal || !dialog) {
            return;
        }

        modal.classList.add(
            'opacity-0'
        );

        dialog.classList.remove(
            'scale-100'
        );

        dialog.classList.add(
            'scale-95'
        );

        setTimeout(() => {

            modal.classList.add(
                'hidden'
            );

            modal.setAttribute(
                'aria-hidden',
                'true'
            );

            document.body.style.overflow =
                '';

        }, 200);
    }

    async function fetchLogs() {

        if (!container) {
            return;
        }

        loading = true;

        container.innerHTML = `
            <div class="flex flex-col items-center justify-center py-12">
                <div class="w-7 h-7 border-2 border-slate-200 border-t-[#6D3089] rounded-full animate-spin"></div>
                <p class="text-xs text-slate-400 mt-3">
                    Loading attendance history...
                </p>
            </div>
        `;

        try {

            const response =
                await fetch(
                    `${DashboardConfig.api}?api=all_logs`,
                    {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept':
                                'application/json'
                        },
                        cache: 'no-store'
                    }
                );

            if (!response.ok) {
                throw new Error(
                    'Request failed'
                );
            }

            const result =
                await response.json();

            if (
                !result.success ||
                !Array.isArray(result.data)
            ) {
                throw new Error(
                    'Invalid server response'
                );
            }

            renderLogs(result.data);

            loaded = true;

        } catch (error) {

            console.error(
                'Attendance history:',
                error
            );

            container.innerHTML = `
                <div class="text-center py-12">
                    <div class="text-rose-500 text-2xl mb-2">
                        !
                    </div>

                    <p class="text-xs font-bold text-rose-600">
                        Unable to load attendance history.
                    </p>

                    <button
                        type="button"
                        onclick="AttendanceModal.reload()"
                        class="mt-3 px-3 py-1.5 bg-slate-100 rounded-lg text-[10px] font-bold"
                    >
                        Try Again
                    </button>
                </div>
            `;

        } finally {

            loading = false;
        }
    }

    function renderLogs(logs) {

        if (!logs.length) {

            container.innerHTML = `
                <div class="text-center py-12">
                    <p class="text-xs text-slate-400 italic">
                        No attendance records found.
                    </p>
                </div>
            `;

            return;
        }

        let html = `
            <div class="overflow-x-auto">

                <table class="w-full text-left text-xs">

                    <thead>
                        <tr class="bg-slate-100 text-slate-500 uppercase text-[9px] font-black tracking-wider">
                            <th class="p-3">Date</th>
                            <th class="p-3">Time</th>
                            <th class="p-3 text-center">Rendered</th>
                            <th class="p-3 text-right">Cumulative</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
        `;

        logs.forEach(log => {

            const date =
                new Date(
                    `${log.date}T00:00:00`
                );

            const dateText =
                date.toLocaleDateString(
                    'en-US',
                    {
                        month: 'short',
                        day: 'numeric',
                        year: 'numeric'
                    }
                );

            const timeIn =
                escapeHtml(
                    formatClientTime(
                        log.time_in
                    )
                );

            const timeOut =
                log.time_out
                    ? escapeHtml(
                        formatClientTime(
                            log.time_out
                        )
                    )
                    : 'Active';

            const rendered =
                log.shift_rendered_hours !== null
                    ? `+${Number(
                        log.shift_rendered_hours
                    ).toFixed(2)} h`
                    : '--';

            const cumulative =
                log.cumulative_hours !== null
                    ? `${Number(
                        log.cumulative_hours
                    ).toFixed(2)} hrs`
                    : 'In Progress';

            const activeClass =
                log.time_out
                    ? 'text-slate-900'
                    : 'text-amber-600';

            html += `
                <tr class="hover:bg-slate-50 transition-colors">

                    <td class="p-3 font-bold text-slate-800">
                        ${escapeHtml(dateText)}
                    </td>

                    <td class="p-3 text-slate-600 font-medium whitespace-nowrap">
                        ${timeIn} - ${timeOut}
                    </td>

                    <td class="p-3 text-center font-bold text-[#6D3089]">
                        ${escapeHtml(rendered)}
                    </td>

                    <td class="p-3 text-right font-black ${activeClass}">
                        ${escapeHtml(cumulative)}
                    </td>

                </tr>
            `;
        });

        html += `
                    </tbody>
                </table>

            </div>
        `;

        container.innerHTML = html;
    }

    function formatClientTime(time) {

        if (!time) {
            return '--';
        }

        const parts =
            String(time).split(':');

        if (parts.length < 2) {
            return time;
        }

        let hour =
            parseInt(parts[0], 10);

        const minute =
            parts[1];

        const second =
            parts[2] ?? '00';

        const suffix =
            hour >= 12 ? 'PM' : 'AM';

        hour =
            hour % 12 || 12;

        return `${String(hour).padStart(2, '0')}:${minute}:${second} ${suffix}`;
    }

    function reload() {

        loaded = false;

        fetchLogs();
    }

    if (modal) {

        modal.addEventListener(
            'click',
            event => {

                if (
                    event.target === modal
                ) {
                    close();
                }

            }
        );
    }

    document.addEventListener(
        'keydown',
        event => {

            if (
                event.key === 'Escape' &&
                modal &&
                !modal.classList.contains(
                    'hidden'
                )
            ) {
                close();
            }

        }
    );

    return {
        open,
        close,
        reload
    };

})();

/*
|--------------------------------------------------------------------------
| Live Timer
|--------------------------------------------------------------------------
*/

<?php if ($is_timed_in && $time_in_start): ?>

(function () {

    const timerElement =
        document.getElementById(
            'live-timer'
        );

    if (!timerElement) {
        return;
    }

    const timeInParts =
        "<?php echo e($time_in_start); ?>"
            .split(':');

    const startHour =
        parseInt(timeInParts[0] || 0, 10);

    const startMinute =
        parseInt(timeInParts[1] || 0, 10);

    const startSecond =
        parseInt(timeInParts[2] || 0, 10);

    function updateTimer() {

        const now =
            new Date();

        const start =
            new Date();

        start.setHours(
            startHour,
            startMinute,
            startSecond,
            0
        );

        let difference =
            now.getTime() -
            start.getTime();

        /*
         * Protect against small client clock
         * synchronization differences.
         */
        difference =
            Math.max(0, difference);

        const totalSeconds =
            Math.floor(
                difference / 1000
            );

        const hours =
            Math.floor(
                totalSeconds / 3600
            );

        const minutes =
            Math.floor(
                (totalSeconds % 3600) /
                60
            );

        const seconds =
            totalSeconds % 60;

        timerElement.textContent =
            [
                String(hours).padStart(2, '0'),
                String(minutes).padStart(2, '0'),
                String(seconds).padStart(2, '0')
            ].join(':');
    }

    updateTimer();

    setInterval(
        updateTimer,
        DashboardConfig.timerInterval
    );

})();

<?php endif; ?>

/*
|--------------------------------------------------------------------------
| Dashboard Auto Refresh
|--------------------------------------------------------------------------
|
| Refreshes important values without forcing the entire page
| to reload.
|--------------------------------------------------------------------------
*/

(function () {

    let refreshing = false;

    async function refreshDashboard() {

        if (refreshing) {
            return;
        }

        /*
         * Do not refresh while a modal is open.
         */
        const modal =
            document.getElementById(
                'full_logs_modal'
            );

        if (
            modal &&
            !modal.classList.contains(
                'hidden'
            )
        ) {
            return;
        }

        refreshing = true;

        try {

            const response =
                await fetch(
                    `${DashboardConfig.api}?api=widgets`,
                    {
                        credentials: 'same-origin',
                        headers: {
                            'Accept':
                                'application/json'
                        },
                        cache: 'no-store'
                    }
                );

            if (!response.ok) {
                return;
            }

            const result =
                await response.json();

            if (!result.success) {
                return;
            }

            updateProgress(
                result.progress
            );

        } catch (error) {

            console.debug(
                'Dashboard refresh skipped:',
                error
            );

        } finally {

            refreshing = false;
        }
    }

    function updateProgress(progress) {

        if (!progress) {
            return;
        }

        const total =
            document.getElementById(
                'total-rendered-hours'
            );

        const target =
            document.getElementById(
                'required-hours'
            );

        const bar =
            document.getElementById(
                'progress-bar'
            );

        const percent =
            document.getElementById(
                'progress-percent'
            );

        const remaining =
            document.getElementById(
                'remaining-hours'
            );

        if (total) {

            total.innerHTML =
                `${Number(
                    progress.rendered_hours
                ).toFixed(1)}
                <span class="text-xs font-bold text-slate-400 ml-1">
                    HRS
                </span>`;
        }

        if (target) {

            target.textContent =
                `${Number(
                    progress.required_hours
                ).toFixed(1)} hrs`;
        }

        if (bar) {

            bar.style.width =
                `${Math.min(
                    100,
                    Number(progress.percent)
                )}%`;
        }

        if (percent) {

            percent.textContent =
                `${Number(
                    progress.percent
                ).toFixed(1)}% Completed`;
        }

        if (remaining) {

            remaining.textContent =
                `${Number(
                    progress.remaining_hours
                ).toFixed(1)} hrs remaining`;
        }
    }

    setInterval(
        refreshDashboard,
        DashboardConfig.refreshInterval
    );

})();

/*
|--------------------------------------------------------------------------
| SweetAlert Status Messages
|--------------------------------------------------------------------------
*/

<?php if (
    isset($_GET['status']) &&
    $_GET['status'] === 'profile_updated'
): ?>

if (window.Swal) {

    Swal.fire({
        icon: 'success',
        title: 'Profile Updated',
        text: 'Your profile has been updated successfully.',
        confirmButtonColor: '#6D3089',
        timer: 2200,
        timerProgressBar: true
    });

}

<?php elseif (
    isset($_GET['error'])
): ?>

if (window.Swal) {

    const errors = {

        csrf_failed:
            'Your session security token is invalid. Please try again.',

        invalid_email:
            'Please provide a valid email address.',

        weak_password:
            'Password must contain at least 8 characters.',

        upload_failed:
            'The profile image could not be uploaded.',

        invalid_image:
            'Only JPG, PNG, and WEBP images are allowed.',

        image_too_large:
            'The profile image must not exceed 5 MB.',

        profile_update_failed:
            'The profile could not be updated. Please try again.',

        upload_directory:
            'The profile upload directory could not be created.'
    };

    const message =
        errors[
            <?php
            echo json_encode(
                $_GET['error']
            );
            ?>
        ] ||
        'An unexpected error occurred.';

    Swal.fire({
        icon: 'error',
        title: 'Something Went Wrong',
        text: message,
        confirmButtonColor: '#6D3089'
    });

}

<?php endif; ?>

</script>

<?php
renderFooter();
?>