<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getPDO();

// verify_token() is defined once in config.php and shared across
// auth.php, users.php, and google_auth.php.

if ($method === 'GET') {
    // If user_id query param provided, return single user
    if (isset($_GET['user_id'])) {
        $user_id = $_GET['user_id'];
        $stmt = $pdo->prepare('SELECT user_id, name, email, phone, register_date FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        if (!$user) { http_response_code(404); echo json_encode(['error'=>'User not found']); exit; }
        echo json_encode(['user'=>$user]);
        exit;
    }

    // Otherwise list users
    $stmt = $pdo->query('SELECT user_id, name, email, phone, register_date FROM users');
    $users = $stmt->fetchAll();
    echo json_encode($users);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['name']) || !isset($data['email']) || !isset($data['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields: name, email, password']);
        exit;
    }

    // Simple password hash (bcrypt)
    $password_hash = password_hash($data['password'], PASSWORD_BCRYPT);
    $user_id = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO users (user_id, name, email, phone, password_hash) VALUES (?, ?, ?, ?, ?)');
    try {
        $stmt->execute([$user_id, $data['name'], $data['email'], $data['phone'] ?? null, $password_hash]);
        // Auto-promote if this email is on the admin allow-list.
        if (is_admin_email($data['email'])) {
            $pdo->prepare('UPDATE users SET is_admin=1 WHERE user_id=?')->execute([$user_id]);
        }
        http_response_code(201);
        echo json_encode(['user_id' => $user_id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Insert failed', 'message' => $e->getMessage()]);
    }
    exit;
}

// Update / change user (PUT)
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    // require authorization — uses get_bearer_token() which also checks
    // X-Auth-Token (InfinityFree Apache strips the Authorization header)
    $token = get_bearer_token();
    if (!$token) {
        http_response_code(401);
        echo json_encode(['error' => 'Missing Authorization token']);
        exit;
    }
    $payload = verify_token($token);
    if (!$payload) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }

    $user_id = $payload['user_id'];

    // If changing password: require current_password and new_password
    if (isset($data['current_password']) && isset($data['new_password'])) {
        // fetch current hash
        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error'=>'User not found']); exit; }
        if (!$row['password_hash']) {
            http_response_code(400);
            echo json_encode(['error'=>'This account uses Google Sign-In and has no password set.']);
            exit;
        }
        if (!password_verify($data['current_password'], $row['password_hash'])) {
            http_response_code(403);
            echo json_encode(['error'=>'Current password incorrect']);
            exit;
        }
        $new_hash = password_hash($data['new_password'], PASSWORD_BCRYPT);
        $up = $pdo->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?');
        $up->execute([$new_hash, $user_id]);
        echo json_encode(['ok'=>true]);
        exit;
    }

    // Otherwise allow updating basic profile fields (name, email, phone)
    $fields = [];
    $params = [];
    if (isset($data['name'])) { $fields[] = 'name = ?'; $params[] = $data['name']; }
    if (isset($data['email'])) { $fields[] = 'email = ?'; $params[] = $data['email']; }
    if (isset($data['phone'])) { $fields[] = 'phone = ?'; $params[] = $data['phone']; }
    if (count($fields) === 0) { http_response_code(400); echo json_encode(['error'=>'No fields to update']); exit; }
    $params[] = $user_id;
    $sql = 'UPDATE users SET ' . implode(', ', $fields) . ' WHERE user_id = ?';
    $stmt = $pdo->prepare($sql);
    try {
        $stmt->execute($params);
        echo json_encode(['ok'=>true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error'=>'Update failed', 'message'=>$e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
