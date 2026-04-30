<?php
// ============================================================
//  SMART SHIFT ASSISTANT — AI Chat
//  File: api/chat.php
//  Method: POST
//  Body:   { "message": "What are today's hazards?" }
// ============================================================

require_once __DIR__ . '/../includes/helpers.php';
setHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respondError('Method not allowed', 405);

$auth    = requireAuth();
$emp_num = $auth['emp_num'];
$db      = getDB();
$body    = getBody();
$message = trim($body['message'] ?? '');

if (!$message) respondError('message is required');

// ── Load context from DB for this worker ─────────────────────
$hazards = $db->prepare(
  "SELECT type, severity, description FROM hazards
   WHERE zone_id = :zone AND active = 1"
);
$hazards->execute([':zone' => $auth['zone_id']]);
$hazardRows = $hazards->fetchAll();

$tasks = $db->prepare(
  "SELECT t.title, t.priority, tl.status AS log_status
   FROM   tasks t
   LEFT JOIN task_logs tl ON tl.task_id = t.task_id AND tl.emp_num = :emp
   JOIN   shifts s ON s.shift_id = t.shift_id
   WHERE  s.emp_num = :emp2 AND s.status = 'active'"
);
$tasks->execute([':emp' => $emp_num, ':emp2' => $emp_num]);
$taskRows = $tasks->fetchAll();

$docs = $db->prepare("SELECT title, category FROM safety_documents WHERE is_active = 1");
$docs->execute();
$docRows = $docs->fetchAll();

// ── Build rule-based AI response ─────────────────────────────
// (Replace this section with a real AI API call when ready)
$reply = buildReply($message, $hazardRows, $taskRows, $docRows);

respond(['reply' => $reply]);

// ── Rule-based chat engine ────────────────────────────────────
function buildReply(string $msg, array $hazards, array $tasks, array $docs): string {
  $m = strtolower($msg);

  // Hazard query
  if (str_contains($m, 'hazard') || str_contains($m, 'danger') || str_contains($m, 'risk')) {
    if (!$hazards) return 'No active hazards in your zone right now. Stay alert.';
    $lines = ["⚠ Active hazards in your zone:\n"];
    foreach ($hazards as $i => $h) {
      $lines[] = ($i+1) . '. ' . strtoupper($h['severity']) . ' — ' . $h['description'];
    }
    $lines[] = "\nAlways check your gas monitor before entering any tunnel.";
    return implode("\n", $lines);
  }

  // Priority / what first
  if (str_contains($m, 'first') || str_contains($m, 'start') || str_contains($m, 'priority') || str_contains($m, 'next')) {
    $pending = array_filter($tasks, fn($t) => $t['log_status'] !== 'completed');
    usort($pending, fn($a,$b) => priorityScore($b['priority']) - priorityScore($a['priority']));
    if (!$pending) return 'All tasks are completed. Great work on the shift!';
    $lines = ["Based on priority, here's what to do next:\n"];
    foreach (array_slice($pending, 0, 3) as $i => $t) {
      $lines[] = ($i+1) . '. ' . $t['title'] . ' [' . strtoupper($t['priority']) . ']';
    }
    return implode("\n", $lines);
  }

  // Methane / gas protocol
  if (str_contains($m, 'methane') || str_contains($m, 'gas') || str_contains($m, 'protocol')) {
    return "Methane Safety Protocol:\n\n" .
      "• Reading <1%: Normal operation\n" .
      "• 1–2%: Increase ventilation, alert supervisor\n" .
      ">2%: Evacuate immediately, pull emergency stop\n\n" .
      "PPE is mandatory before entering Tunnel 7. Ensure gas monitor is calibrated.";
  }

  // Task log
  if (str_contains($m, 'log') || str_contains($m, 'complet') || str_contains($m, 'done') || str_contains($m, 'finish')) {
    return "✓ Understood. Use the Tasks tab to mark specific tasks complete, or type the task name and I'll log it for you.";
  }

  // Progress
  if (str_contains($m, 'progress') || str_contains($m, 'how many') || str_contains($m, 'status')) {
    $done  = count(array_filter($tasks, fn($t) => $t['log_status'] === 'completed'));
    $total = count($tasks);
    return "Shift progress: {$done} of {$total} tasks completed.\n" .
           ($done < $total ? "Keep going — you're doing well!" : "All tasks done. Ready for handover.");
  }

  // Available documents
  if (str_contains($m, 'document') || str_contains($m, 'protocol') || str_contains($m, 'procedure')) {
    if (!$docs) return 'No safety documents are available yet.';
    $titles = array_map(fn($d) => '• ' . $d['title'], $docs);
    return "Available safety documents:\n\n" . implode("\n", $titles) . "\n\nAsk me about any specific protocol.";
  }

  return "I've noted that. I can help with:\n• Today's hazards\n• Task priorities\n• Safety protocols\n• Shift progress\n\nWhat would you like to know?";
}

function priorityScore(string $p): int {
  return match($p) {
    'critical' => 4,
    'urgent'   => 3,
    'normal'   => 2,
    default    => 1,
  };
}
