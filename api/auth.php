<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$pdo = getPDO();

// sign_token() / verify_token() are defined once in config.php and shared
// across auth.php, users.php, and google_auth.php.

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['action'])) { http_response_code(400); echo json_encode(['error'=>'Missing action']); exit; }
    $action = $data['action'];
    if ($action === 'login') {
        if (!isset($data['email']) || !isset($data['password'])) { http_response_code(400); echo json_encode(['error'=>'Missing email or password']); exit; }
        $stmt = $pdo->prepare('SELECT user_id, name, email, password_hash, auth_provider, is_admin FROM users WHERE email = ?');
        $stmt->execute([$data['email']]);
        $user = $stmt->fetch();
        if (!$user || !$user['password_hash'] || !password_verify($data['password'], $user['password_hash'])) {
            http_response_code(401);
            if ($user && !$user['password_hash']) {
                echo json_encode(['error'=>'This account was created with Google Sign-In. Use the Google button to log in.']);
            } else {
                echo json_encode(['error'=>'Invalid credentials']);
            }
            exit;
        }
        // Plug-and-play admin: promote if this email is on the admin allow-list.
        if (is_admin_email($user['email']) && empty($user['is_admin'])) {
            $pdo->prepare('UPDATE users SET is_admin=1 WHERE user_id=?')->execute([$user['user_id']]);
            $user['is_admin'] = 1;
        }
        $payload = ['user_id'=>$user['user_id'], 'email'=>$user['email'], 'name'=>$user['name'], 'is_admin'=>(bool)($user['is_admin'] ?? false), 'exp'=>time()+2592000]; // 30 days
        $token = sign_token($payload);
        echo json_encode(['token'=>$token, 'user'=>$payload]);
        exit;
    }
    if ($action === 'verify') {
        // verify token in body
        if (!isset($data['token'])) { http_response_code(400); echo json_encode(['error'=>'Missing token']); exit; }
        $payload = verify_token($data['token']);
        if (!$payload) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }
        echo json_encode(['ok'=>true,'payload'=>$payload]);
        exit;
    }
}

// For GET, optionally allow token via Authorization header and return payload
if ($method === 'GET') {
    $token = get_bearer_token();
    if ($token) {
        $payload = verify_token($token);
        if (!$payload) { http_response_code(401); echo json_encode(['error'=>'Invalid token']); exit; }
        echo json_encode(['ok'=>true,'payload'=>$payload]);
        exit;
    }
    http_response_code(400); echo json_encode(['error'=>'No token provided']);
}

http_response_code(405); echo json_encode(['error'=>'Method not allowed']);
