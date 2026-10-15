<?php
/**
 * CRON / AJAX endpoint — Email Queue Processor
 *
 * This file flushes the `email_queue` table by dequeuing pending messages
 * and sending them through `sendSystemEmail()` (SMTP + mail() fallback).
 *
 * Call it:
 *   1. Via a scheduled task (Windows Task Scheduler or Linux cron) from CLI:
 *        php.exe -f C:\xampp\htdocs\quest_ojt_system\cron_queue.php
 *   2. Or from any page load in-process (guaranteed-delivery, fire-and-forget):
 *        (fetch 'cron_queue.php?token=...' via async JS img/script tag after the
 *         response is flushed — this is the default fallback since most
 *         XAMPP dev systems don't have an external scheduler.)
 *   3. Or manually from any logged-in admin/superadmin session (no token required)
 *
 * Defaults:
 *   - MAX_PER_RUN = 50 emails per invocation
 *   - Max 2 attempts per email (so bad addresses don't loop forever)
 *   - On 2nd fail -> status = dead (no more auto-retries)
 */

define('CRON_MAX_PER_RUN', 50);
define('CRON_MAX_ATTEMPTS', 2);
define('CRON_TOKEN_ENV_NAME', 'CRON_QUEUE_TOKEN');

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth/auth_helper.php';

$requestToken = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$configuredToken = (string)(getenv(CRON_TOKEN_ENV_NAME) ?: '');
$isAuthenticated = false;

if (PHP_SAPI === 'cli') {
    $isAuthenticated = true;
} elseif ($requestToken !== '' && $configuredToken !== '' && hash_equals($configuredToken, $requestToken)) {
    $isAuthenticated = true;
} else {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!empty($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'superadmin'], true)) {
        $isAuthenticated = true;
    }
}

if (!$isAuthenticated) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Forbidden. Set " . CRON_TOKEN_ENV_NAME . " in .env or call from CLI or log in as admin.\n";
    exit(1);
}

ensureEmailQueueTable($pdo);

$workerId = bin2hex(random_bytes(8));
$sentCount = 0;
$failedCount = 0;
$deadCount = 0;
$processedIds = [];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT id, email_to, subject, body, attempts
        FROM email_queue
        WHERE status = 'queued' AND available_at <= CURRENT_TIMESTAMP
        ORDER BY priority ASC, created_at ASC, id ASC
        LIMIT " . (int)CRON_MAX_PER_RUN . "
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($items) {
        $ids = array_map('intval', array_column($items, 'id'));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtClaim = $pdo->prepare("UPDATE email_queue SET status = 'sending', claimed_by = ?, attempts = attempts + 1 WHERE id IN ($placeholders) AND status = 'queued'");
        $stmtClaim->execute(array_merge([$workerId], $ids));
    }
    $pdo->commit();
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    $items = false;
}

if ($items) {
    foreach ($items as $row) {
        $id = (int)$row['id'];
        $processedIds[] = $id;
        $ok = false;
        $errorMsg = null;
        try {
            $ok = sendSystemEmail($row['email_to'], $row['subject'], $row['body']);
        } catch (Throwable $e) {
            $ok = false;
            $errorMsg = $e->getMessage();
        }

        if ($ok) {
            $sentCount++;
            try {
                $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = CURRENT_TIMESTAMP, last_error = NULL WHERE id = ?")
                    ->execute([$id]);
            } catch (Exception $e) {}
        } else {
            $attempts = (int)$row['attempts'] + 1;
            if ($attempts >= CRON_MAX_ATTEMPTS) {
                $deadCount++;
                try {
                    $pdo->prepare("UPDATE email_queue SET status = 'dead', last_error = ? WHERE id = ?")
                        ->execute([$errorMsg ?? 'sendSystemEmail returned false (2 attempts)', $id]);
                } catch (Exception $e) {}
            } else {
                $failedCount++;
                try {
                    $pdo->prepare("UPDATE email_queue SET status = 'queued', last_error = ?, available_at = DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 SECOND), claimed_by = NULL WHERE id = ?")
                        ->execute([$errorMsg ?? 'sendSystemEmail returned false', $id]);
                } catch (Exception $e) {}
            }
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'worker' => $workerId,
    'sent' => $sentCount,
    'failed_retryable' => $failedCount,
    'dead' => $deadCount,
    'total_processed' => count($processedIds),
    'ids' => $processedIds,
    'remaining_queued' => (int)($pdo->query("SELECT COUNT(*) FROM email_queue WHERE status = 'queued'")->fetchColumn()),
], JSON_UNESCAPED_SLASHES), "\n";
