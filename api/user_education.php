<?php
/**
 * User Education endpoint
 * GET                — get all education entries for the authenticated user
 * POST               — add an education entry
 * PUT  ?id=N         — update an entry
 * DELETE ?id=N       — delete an entry
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method  = $_SERVER['REQUEST_METHOD'];
$pdo     = getPDO();
$token   = get_bearer_token();
$payload = $token ? verify_token($token) : null;

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}
$userId = $payload['user_id'];

// ---- GET ----
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT ue.*,
                i.name  AS institution_display,
                i.short_name AS institution_short,
                c.name  AS course_display,
                c.level AS course_level_display,
                c.faculty
         FROM user_education ue
         LEFT JOIN institutions i ON i.institution_id = ue.institution_id
         LEFT JOIN courses      c ON c.course_id      = ue.course_id
         WHERE ue.user_id = ?
         ORDER BY ue.sort_order ASC, ue.end_year DESC'
    );
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---- POST ----
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $instId    = isset($data['institution_id'])  ? (int)$data['institution_id']  : null;
    $instName  = trim($data['institution_name']  ?? '');
    $courseId  = isset($data['course_id'])        ? (int)$data['course_id']        : null;
    $courseName= trim($data['course_name']        ?? '');
    $level     = trim($data['course_level']       ?? '');
    $startYear = isset($data['start_year'])       ? (int)$data['start_year']       : null;
    $endYear   = isset($data['end_year'])         ? (int)$data['end_year']         : null;
    $isCurrent = !empty($data['is_current'])      ? 1 : 0;
    $cgpa      = isset($data['cgpa'])             ? (float)$data['cgpa']           : null;

    $stmt = $pdo->prepare(
        'INSERT INTO user_education
         (user_id, institution_id, institution_name, course_id, course_name, course_level,
          start_year, end_year, is_current, cgpa)
         VALUES (?,?,?,?,?,?,?,?,?,?)'
    );
    $stmt->execute([$userId, $instId, $instName ?: null, $courseId, $courseName ?: null,
                    $level ?: null, $startYear, $endYear, $isCurrent, $cgpa]);
    $newId = (int)$pdo->lastInsertId();
    echo json_encode(['ok' => true, 'id' => $newId]);
    exit;
}

// ---- PUT ----
if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    // Verify ownership
    $stmt = $pdo->prepare('SELECT id FROM user_education WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $fields = [];
    $params = [];
    $allowed = ['institution_id','institution_name','course_id','course_name','course_level',
                'start_year','end_year','is_current','cgpa','sort_order'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f}=?";
            $params[] = $data[$f] === '' ? null : $data[$f];
        }
    }
    if ($fields) {
        $params[] = $id;
        $pdo->prepare('UPDATE user_education SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---- DELETE ----
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM user_education WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    echo json_encode(['ok' => true, 'deleted' => $stmt->rowCount() > 0]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
