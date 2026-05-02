<?php
// ============================================================
// SMART SHIFT ASSISTANT — AI Chat
// File: api/chat.php
// Method: POST
// Body: { "message": "What are today's hazards?" }
// ============================================================

// ── Safe helper loading ──────────────────────────────────────
$helpers = __DIR__ . '/../includes/helpers.php';

if (!file_exists($helpers)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'Server configuration error: helpers.php not found'
    ]);
    exit;
}

require_once $helpers;

setHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respondError('Method not allowed', 405);
}

$auth    = requireAuth();
$emp_num = $auth['emp_num'];
$db      = getDB();
$body    = getBody();

// ── Validate input ───────────────────────────────────────────
$message = preg_replace('/\s+/', ' ', trim($body['message'] ?? ''));

if (!$message) {
    respondError('message is required');
}

if (strlen($message) > 500) {
    respondError('message too long', 400);
}

// ── Load hazards for worker zone ─────────────────────────────
$hazards = $db->prepare("
    SELECT type, severity, description
    FROM hazards
    WHERE zone_id = :zone
      AND active = 1
");
$hazards->execute([':zone' => $auth['zone_id']]);
$hazardRows = $hazards->fetchAll(PDO::FETCH_ASSOC);

// ── Load tasks (latest log only per task) ────────────────────
$tasks = $db->prepare("
    SELECT
        t.task_id,
        t.title,
        t.priority,
        tl.status AS log_status
    FROM tasks t
    JOIN shifts s
        ON s.shift_id = t.shift_id
    LEFT JOIN task_logs tl
        ON tl.log_id = (
            SELECT tl2.log_id
            FROM task_logs tl2
            WHERE tl2.task_id = t.task_id
              AND tl2.emp_num = :emp
            ORDER BY tl2.log_id DESC
            LIMIT 1
        )
    WHERE s.emp_num = :emp2
      AND s.status = 'active'
");
$tasks->execute([
    ':emp'  => $emp_num,
    ':emp2' => $emp_num
]);
$taskRows = $tasks->fetchAll(PDO::FETCH_ASSOC);

// ── Load safety documents ────────────────────────────────────
$docs = $db->prepare("
    SELECT title, category
    FROM safety_documents
    WHERE is_active = 1
");
$docs->execute();
$docRows = $docs->fetchAll(PDO::FETCH_ASSOC);

// ── Build response ───────────────────────────────────────────
$reply = buildReply($message, $hazardRows, $taskRows, $docRows);

respond(['reply' => $reply]);

// ============================================================
// RULE-BASED CHAT ENGINE
// ============================================================

function buildReply($msg, $hazards, $tasks, $docs)
{
    $m = strtolower($msg);

    // ── Hazard query ─────────────────────────────────────────
    if (containsAny($m, ['hazard', 'danger', 'risk', 'unsafe'])) {
        if (!$hazards) {
            return 'No active hazards in your zone right now. Stay alert.';
        }

        $lines = ["⚠ Active hazards in your zone:\n"];

        foreach ($hazards as $i => $h) {
            $lines[] = ($i + 1) . '. ' .
                strtoupper($h['severity']) .
                ' — ' .
                $h['description'];
        }

        $critical = array_filter($hazards, function ($h) {
            return strtolower($h['severity']) === 'critical';
        });

        if ($critical) {
            $lines[] = "\nCritical hazard detected — notify your supervisor immediately.";
        }

        $lines[] = "\nAlways check your gas monitor before entering any tunnel.";

        return implode("\n", $lines);
    }

    // ── Priority / next task ─────────────────────────────────
    if (containsAny($m, ['first', 'start', 'priority', 'next'])) {

        $pending = array_filter($tasks, function ($t) {
            return ($t['log_status'] ?? '') !== 'completed';
        });

        usort($pending, function ($a, $b) {
            $priorityCompare = priorityScore($b['priority']) - priorityScore($a['priority']);

            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            return strcmp($a['title'], $b['title']);
        });

        if (!$pending) {
            return 'All tasks are completed. Great work on the shift!';
        }

        $lines = ["Based on priority, here's what to do next:\n"];

        foreach (array_slice($pending, 0, 3) as $i => $t) {
            $lines[] = ($i + 1) . '. ' .
                $t['title'] .
                ' [' . strtoupper($t['priority']) . ']';
        }

        return implode("\n", $lines);
    }

    // ── Methane / gas protocol ───────────────────────────────
    if (containsAny($m, ['methane', 'gas', 'gas protocol'])) {
        return "Methane Safety Protocol:\n\n" .
            "• Reading <1%: Normal operation\n" .
            "• 1–2%: Increase ventilation, alert supervisor\n" .
            "• >2%: Evacuate immediately, pull emergency stop\n\n" .
            "PPE is mandatory before entering Tunnel 7. Ensure gas monitor is calibrated.";
    }

    // ── Task log / completion ────────────────────────────────
    if (containsAny($m, ['log', 'complete', 'completed', 'done', 'finish'])) {
        return "✓ Understood. Use the Tasks tab to mark specific tasks complete, or type the task name and I'll log it for you.";
    }

    // ── Progress ─────────────────────────────────────────────
    if (containsAny($m, ['progress', 'how many', 'status'])) {

        $done = count(array_filter($tasks, function ($t) {
            return ($t['log_status'] ?? '') === 'completed';
        }));

        $total = count($tasks);

        return "Shift progress: {$done} of {$total} tasks completed.\n" .
            ($done < $total
                ? "Keep going — you're doing well!"
                : "All tasks done. Ready for handover.");
    }

    // ── Documents / procedures ───────────────────────────────
    if (containsAny($m, ['document', 'procedure', 'manual', 'safety protocol'])) {

        if (!$docs) {
            return 'No safety documents are available yet.';
        }

        $titles = array_map(function ($d) {
            return '• ' . $d['title'];
        }, $docs);

        return "Available safety documents:\n\n" .
            implode("\n", $titles) .
            "\n\nAsk me about any specific procedure.";
    }

    // ── Default fallback ─────────────────────────────────────
    return "I can help with:\n" .
        "• Today's hazards\n" .
        "• Task priorities\n" .
        "• Safety protocols\n" .
        "• Shift progress\n\n" .
        "What would you like to know?";
}

// ============================================================
// HELPERS
// ============================================================

function containsAny($text, array $keywords)
{
    foreach ($keywords as $keyword) {
        if (strpos($text, $keyword) !== false) {
            return true;
        }
    }
    return false;
}

function priorityScore($p)
{
    switch (strtolower($p)) {
        case 'critical':
            return 4;
        case 'urgent':
            return 3;
        case 'normal':
            return 2;
        default:
            return 1;
    }
}