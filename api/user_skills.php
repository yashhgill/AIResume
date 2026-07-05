<?php
/**
 * User Skills endpoint
 * GET                    — list user's skills
 * POST                   — add a skill (manual) or bulk-save AI-inferred skills
 * PUT  ?id=N             — update a skill
 * DELETE ?id=N           — remove a skill
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
        'SELECT id, skill_name, category, source, proficiency,
                certification_name, certification_url, show_in_resume, sort_order
         FROM user_skills
         WHERE user_id = ?
         ORDER BY source DESC, category ASC, skill_name ASC'
    );
    $stmt->execute([$userId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---- POST ----
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    // Support bulk array: { skills: [{skill_name, category, source, ...}] }
    $items = isset($data['skills']) ? $data['skills'] : [$data];
    $saved = 0;

    $stmt = $pdo->prepare(
        'INSERT INTO user_skills
         (user_id, skill_name, category, source, proficiency, certification_name, certification_url, show_in_resume, sort_order)
         VALUES (?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
           category=COALESCE(VALUES(category), category),
           source=IF(source="manual","manual",VALUES(source)),
           proficiency=COALESCE(VALUES(proficiency), proficiency),
           show_in_resume=COALESCE(VALUES(show_in_resume), show_in_resume)'
    );

    foreach ($items as $s) {
        $name = trim($s['skill_name'] ?? '');
        if (!$name) continue;
        $stmt->execute([
            $userId,
            $name,
            trim($s['category']             ?? '') ?: null,
            in_array($s['source'] ?? '', ['ai','manual']) ? $s['source'] : 'manual',
            in_array($s['proficiency'] ?? '', ['beginner','intermediate','advanced','expert'])
                ? $s['proficiency'] : 'intermediate',
            trim($s['certification_name']   ?? '') ?: null,
            trim($s['certification_url']    ?? '') ?: null,
            isset($s['show_in_resume'])             ? (int)$s['show_in_resume'] : 1,
            (int)($s['sort_order']          ?? 0),
        ]);
        $saved++;
    }

    // Return full updated list
    $list = $pdo->prepare('SELECT id, skill_name, category, source, proficiency, certification_name, certification_url, show_in_resume FROM user_skills WHERE user_id=? ORDER BY source DESC, category ASC, skill_name ASC');
    $list->execute([$userId]);
    echo json_encode(['ok' => true, 'saved' => $saved, 'skills' => $list->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

// ---- PUT ----
if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $stmt = $pdo->prepare('SELECT id FROM user_skills WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $allowed = ['skill_name','category','proficiency','certification_name',
                'certification_url','show_in_resume','sort_order'];
    $fields = []; $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) {
            $fields[] = "{$f}=?";
            $params[] = $data[$f] === '' ? null : $data[$f];
        }
    }
    if ($fields) {
        $params[] = $id;
        $pdo->prepare('UPDATE user_skills SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---- DELETE ----
if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM user_skills WHERE id=? AND user_id=?')->execute([$id, $userId]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
