<?php
// ============================================================
// SMART SHIFT ASSISTANT — AI Chat
// File: api/chat.php   |   POST   |   No database
// Data source: ../config.json
// Body: { "message": "What are today's hazards?" }
// ============================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit; }

// ── Load config ──────────────────────────────────────────────
$config_path = __DIR__ . '/../config.json';

if (!file_exists($config_path)) {
    http_response_code(500);
    echo json_encode(['error' => 'config.json not found']);
    exit;
}

$cfg = json_decode(file_get_contents($config_path), true);

// ── Auth (JWT decode — simple base64 payload read) ──────────
function getAuthUser($cfg) {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$header) return null;
    $token  = str_replace('Bearer ', '', $header);
    $parts  = explode('.', $token);
    if (count($parts) !== 3) return null;
    $payload = json_decode(base64_decode(str_pad(strtr($parts[1], '-_', '+/'), strlen($parts[1]) % 4 == 0 ? strlen($parts[1]) : strlen($parts[1]) + 4 - strlen($parts[1]) % 4, '=', STR_PAD_RIGHT)), true);
    if (!$payload || !isset($payload['emp_num'])) return null;
    return $payload;
}

$user = getAuthUser($cfg);
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Parse request ────────────────────────────────────────────
$body    = json_decode(file_get_contents('php://input'), true);
$message = trim(preg_replace('/\s+/', ' ', $body['message'] ?? ''));

if (!$message) {
    http_response_code(400);
    echo json_encode(['error' => 'message is required']);
    exit;
}

if (strlen($message) > 500) {
    http_response_code(400);
    echo json_encode(['error' => 'message too long']);
    exit;
}

// ── Load data from config ────────────────────────────────────
$zone_id  = $user['zone_id'] ?? '';
$emp_num  = $user['emp_num'] ?? '';
$emp_role = $user['role']    ?? '';

$hazards = array_values(array_filter($cfg['hazards'] ?? [], function($h) use ($zone_id) {
    return $h['zone_id'] === $zone_id && $h['active'];
}));

$tasks = array_values(array_filter($cfg['tasks'] ?? [], function($t) use ($zone_id) {
    return $t['zone_id'] === $zone_id;
}));

$docs = array_values(array_filter($cfg['safety_documents'] ?? [], function($d) {
    return $d['is_active'];
}));

// ── Build reply ──────────────────────────────────────────────
$reply = buildReply($message, $hazards, $tasks, $docs);
echo json_encode(['reply' => $reply]);

// ============================================================
// RULE-BASED CHAT ENGINE
// ============================================================

function buildReply($msg, $hazards, $tasks, $docs)
{
    $m = strtolower($msg);

    // Hazard query
    if (containsAny($m, ['hazard', 'danger', 'risk', 'unsafe'])) {
        if (empty($hazards)) {
            return 'No active hazards in your zone right now. Stay alert.';
        }
        $lines = ["⚠ Active hazards in your zone:\n"];
        foreach ($hazards as $i => $h) {
            $lines[] = ($i + 1) . '. ' . strtoupper($h['severity']) . ' — ' . $h['description'];
        }
        $critical = array_filter($hazards, fn($h) => strtolower($h['severity']) === 'critical');
        if ($critical) $lines[] = "\nCritical hazard detected — notify your supervisor immediately.";
        $lines[] = "\nAlways check your gas monitor before entering any tunnel.";
        return implode("\n", $lines);
    }

    // Priority / next task
    if (containsAny($m, ['first', 'start', 'priority', 'next', 'what should'])) {
        $pending = array_filter($tasks, fn($t) => ($t['log_status'] ?? '') !== 'completed');
        usort($pending, fn($a, $b) => priorityScore($b['priority']) - priorityScore($a['priority']));
        if (empty($pending)) return 'All tasks are completed. Great work!';
        $lines = ["Based on priority, here's what to do next:\n"];
        foreach (array_slice($pending, 0, 3) as $i => $t) {
            $lines[] = ($i + 1) . '. ' . $t['title'] . ' [' . strtoupper($t['priority']) . ']';
        }
        return implode("\n", $lines);
    }

    // Methane / gas protocol
    if (containsAny($m, ['methane', 'gas', 'gas protocol'])) {
        return "Methane Safety Protocol:\n\n" .
            "• Reading <1%: Normal operation\n" .
            "• 1–2%: Increase ventilation, alert supervisor\n" .
            "• >2%: Evacuate immediately, pull emergency stop\n\n" .
            "PPE is mandatory before entering Tunnel 7. Ensure gas monitor is calibrated.";
    }

    // Progress
    if (containsAny($m, ['progress', 'how many', 'status', 'how far'])) {
        $done  = count(array_filter($tasks, fn($t) => ($t['log_status'] ?? '') === 'completed'));
        $total = count($tasks);
        return "Shift progress: {$done} of {$total} tasks completed.\n" .
            ($done < $total ? 'Keep going!' : 'All tasks done — ready for handover.');
    }

    // Documents
    if (containsAny($m, ['document', 'procedure', 'manual', 'protocol', 'guide'])) {
        if (empty($docs)) return 'No safety documents available yet.';
        $titles = array_map(fn($d) => '• ' . $d['title'], $docs);
        return "Available safety documents:\n\n" . implode("\n", $titles) . "\n\nAsk me about any specific procedure.";
    }

    // Default
    return "I can help with:\n• Today's hazards\n• Task priorities\n• Safety protocols\n• Shift progress\n\nWhat would you like to know?";
}

function containsAny($text, array $keywords)
{
    foreach ($keywords as $kw) { if (strpos($text, $kw) !== false) return true; }
    return false;
}

function priorityScore($p)
{
    return match(strtolower($p)) { 'critical' => 4, 'urgent' => 3, 'normal' => 2, default => 1 };
}
