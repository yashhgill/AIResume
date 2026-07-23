<?php
/**
 * Institutions endpoint
 * GET  ?search=term        — autocomplete search (returns verified first)
 * GET  ?id=N               — single institution
 * POST                     — add new (unverified) institution
 * PUT  ?id=N  (admin)      — update / verify
 * DELETE ?id=N (admin)     — delete
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getPDO();

// ---- GET ----
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM institutions WHERE institution_id = ?');
        $stmt->execute([(int)$_GET['id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode($row ?: null);
        exit;
    }

    $search = trim($_GET['search'] ?? '');
    if ($search === '') {
        // Return top verified institutions
        $stmt = $pdo->query('SELECT institution_id, name, short_name, city, country, type, verified
                             FROM institutions ORDER BY verified DESC, name ASC LIMIT 50');
    } else {
        $like = '%' . $search . '%';
        $stmt = $pdo->prepare(
            'SELECT institution_id, name, short_name, city, country, type, verified
             FROM institutions
             WHERE name LIKE ? OR short_name LIKE ?
             ORDER BY verified DESC, name ASC
             LIMIT 20'
        );
        $stmt->execute([$like, $like]);
    }
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ---- POST  (anyone can add, starts unverified) ----
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $name = trim($data['name'] ?? '');
    if ($name === '') { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }

    // Check duplicate (case-insensitive)
    $stmt = $pdo->prepare('SELECT institution_id FROM institutions WHERE LOWER(name) = LOWER(?)');
    $stmt->execute([$name]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        echo json_encode(['institution_id' => (int)$existing, 'already_exists' => true]);
        exit;
    }

    $userId = null;
    $token  = get_bearer_token();
    if ($token) {
        $payload = verify_token($token);
        if ($payload) $userId = $payload['user_id'];
    }

    $stmt = $pdo->prepare(
        'INSERT INTO institutions (name, short_name, country, city, type, verified, created_by)
         VALUES (?,?,?,?,?,0,?)'
    );
    $stmt->execute([
        $name,
        trim($data['short_name'] ?? '') ?: null,
        trim($data['country'] ?? 'Malaysia') ?: 'Malaysia',
        trim($data['city'] ?? '') ?: null,
        $data['type'] ?? 'university',
        $userId,
    ]);
    echo json_encode(['institution_id' => (int)$pdo->lastInsertId('institutions_institution_id_seq'), 'created' => true]);
    exit;
}

// ---- PUT  (admin only) ----
if ($method === 'PUT') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $pdo->prepare(
        'UPDATE institutions SET name=COALESCE(?,name), short_name=COALESCE(?,short_name),
         country=COALESCE(?,country), city=COALESCE(?,city), type=COALESCE(?,type),
         verified=COALESCE(?,verified) WHERE institution_id=?'
    )->execute([
        $data['name'] ?? null, $data['short_name'] ?? null,
        $data['country'] ?? null, $data['city'] ?? null,
        $data['type'] ?? null, isset($data['verified']) ? (int)$data['verified'] : null,
        $id,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- DELETE  (admin only) ----
if ($method === 'DELETE') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id = ?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $id = (int)($_GET['id'] ?? 0);
    $pdo->prepare('DELETE FROM institutions WHERE institution_id = ?')->execute([$id]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
