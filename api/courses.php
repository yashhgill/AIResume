<?php
/**
 * Courses endpoint
 * GET  ?institution_id=N&search=term   — search courses for an institution
 * GET  ?id=N                           — single course
 * POST                                 — add new course (unverified)
 * PUT  ?id=N  (admin)                  — update / verify
 * DELETE ?id=N (admin)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare(
            'SELECT c.*, i.name AS institution_name FROM courses c
             JOIN institutions i ON i.institution_id = c.institution_id
             WHERE c.course_id = ?'
        );
        $stmt->execute([(int)$_GET['id']]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        exit;
    }

    $instId = (int)($_GET['institution_id'] ?? 0);
    $search = trim($_GET['search'] ?? '');

    if ($instId === 0 && $search === '') {
        echo json_encode([]);
        exit;
    }

    $params = [];
    $where  = [];
    if ($instId > 0) { $where[] = 'c.institution_id = ?'; $params[] = $instId; }
    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(c.name LIKE ? OR c.code LIKE ?)';
        $params[] = $like; $params[] = $like;
    }
    $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $stmt = $pdo->prepare(
        "SELECT c.course_id, c.code, c.name, c.level, c.faculty, c.verified,
                i.name AS institution_name
         FROM courses c
         JOIN institutions i ON i.institution_id = c.institution_id
         {$whereSQL}
         ORDER BY c.verified DESC, c.name ASC LIMIT 30"
    );
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($method === 'POST') {
    $data      = json_decode(file_get_contents('php://input'), true) ?? [];
    $name      = trim($data['name'] ?? '');
    $instId    = (int)($data['institution_id'] ?? 0);
    if (!$name || !$instId) { http_response_code(400); echo json_encode(['error' => 'name and institution_id required']); exit; }

    // Duplicate check
    $stmt = $pdo->prepare('SELECT course_id FROM courses WHERE institution_id=? AND LOWER(name)=LOWER(?)');
    $stmt->execute([$instId, $name]);
    $existing = $stmt->fetchColumn();
    if ($existing) { echo json_encode(['course_id' => (int)$existing, 'already_exists' => true]); exit; }

    $userId = null;
    $token  = get_bearer_token();
    if ($token) { $payload = verify_token($token); if ($payload) $userId = $payload['user_id']; }

    $stmt = $pdo->prepare(
        'INSERT INTO courses (institution_id, code, name, level, faculty, verified, created_by)
         VALUES (?,?,?,?,?,0,?)'
    );
    $stmt->execute([
        $instId,
        trim($data['code'] ?? '') ?: null,
        $name,
        $data['level'] ?? 'degree',
        trim($data['faculty'] ?? '') ?: null,
        $userId,
    ]);
    echo json_encode(['course_id' => (int)$pdo->lastInsertId('courses_course_id_seq'), 'created' => true]);
    exit;
}

if ($method === 'PUT') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id=?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pdo->prepare(
        'UPDATE courses SET name=COALESCE(?,name), code=COALESCE(?,code), level=COALESCE(?,level),
         faculty=COALESCE(?,faculty), verified=COALESCE(?,verified) WHERE course_id=?'
    )->execute([
        $data['name'] ?? null, $data['code'] ?? null, $data['level'] ?? null,
        $data['faculty'] ?? null, isset($data['verified']) ? (int)$data['verified'] : null,
        $id,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($method === 'DELETE') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id=?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $pdo->prepare('DELETE FROM courses WHERE course_id=?')->execute([(int)($_GET['id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
