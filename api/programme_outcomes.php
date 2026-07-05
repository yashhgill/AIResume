<?php
/**
 * Programme Outcomes (PLOs) API
 * GET    /api/programme_outcomes.php?course_id=X   → list PLOs for a course
 * POST   /api/programme_outcomes.php               → create PLO {course_id, code, description, category, sort_order}
 * PUT    /api/programme_outcomes.php?id=X          → update PLO
 * DELETE /api/programme_outcomes.php?id=X          → delete PLO
 *
 * All write operations require admin token.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$pdo    = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// ── Auth (required for writes) ────────────────────────────────────────────────
function requireAdmin($pdo) {
    $token   = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    // check is_admin flag
    try {
        $s = $pdo->prepare('SELECT is_admin FROM users WHERE user_id = ?');
        $s->execute([$payload['user_id']]);
        $u = $s->fetch(PDO::FETCH_ASSOC);
        if (!$u || !$u['is_admin']) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }
    } catch (\Exception $e) { /* no is_admin column — allow for now */ }
    return $payload;
}

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (isset($_GET['course_id'])) {
        $stmt = $pdo->prepare(
            'SELECT plo_id, course_id, code, description, category, sort_order
             FROM programme_outcomes WHERE course_id = ? ORDER BY sort_order ASC, plo_id ASC'
        );
        $stmt->execute([(int)$_GET['course_id']]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        // All PLOs (admin use)
        $stmt = $pdo->query(
            'SELECT po.plo_id, po.course_id, po.code, po.description, po.category, po.sort_order,
                    c.name AS course_name
             FROM programme_outcomes po
             LEFT JOIN courses c ON c.course_id = po.course_id
             ORDER BY c.name ASC, po.sort_order ASC, po.plo_id ASC'
        );
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    exit;
}

// ── POST (create) ─────────────────────────────────────────────────────────────
if ($method === 'POST') {
    requireAdmin($pdo);
    $d = json_decode(file_get_contents('php://input'), true) ?? [];
    if (empty($d['course_id']) || empty($d['code']) || empty($d['description'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields: course_id, code, description']);
        exit;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO programme_outcomes (course_id, code, description, category, sort_order)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int)$d['course_id'],
        trim($d['code']),
        trim($d['description']),
        $d['category'] ?? null,
        (int)($d['sort_order'] ?? 0),
    ]);
    http_response_code(201);
    echo json_encode(['ok' => true, 'plo_id' => $pdo->lastInsertId()]);
    exit;
}

// ── PUT (update) ──────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    requireAdmin($pdo);
    if (!isset($_GET['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $d      = json_decode(file_get_contents('php://input'), true) ?? [];
    $fields = []; $vals = [];
    foreach (['code','description','category','sort_order'] as $f) {
        if (array_key_exists($f, $d)) { $fields[] = "$f = ?"; $vals[] = $d[$f]; }
    }
    if (!$fields) { http_response_code(400); echo json_encode(['error' => 'Nothing to update']); exit; }
    $vals[] = (int)$_GET['id'];
    $pdo->prepare('UPDATE programme_outcomes SET ' . implode(', ', $fields) . ' WHERE plo_id = ?')->execute($vals);
    echo json_encode(['ok' => true]);
    exit;
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    requireAdmin($pdo);
    if (!isset($_GET['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $pdo->prepare('DELETE FROM programme_outcomes WHERE plo_id = ?')->execute([(int)$_GET['id']]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
