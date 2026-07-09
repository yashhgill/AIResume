<?php
/**
 * Google Sign-In endpoint.
 *
 * Frontend flow (see frontendreact/login.html / register.html):
 *   1. Google Identity Services renders a "Sign in with Google" button.
 *   2. On success it gives us a signed ID token (a JWT) for the user.
 *   3. We POST that token here as { "credential": "<id_token>" }.
 *
 * Verification approach: rather than re-implementing JWT signature
 * verification (fetching Google's rotating public keys, checking JWK kid,
 * etc.), we hand the token to Google's own tokeninfo endpoint and let
 * Google tell us if it's genuine. This is simple, dependency-free, and
 * fine for a project at this scale. For a larger production app you'd use
 * a proper JWT/JWK library instead.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!GOOGLE_CLIENT_ID) {
    http_response_code(500);
    echo json_encode(['error' => 'Google Sign-In is not configured. Set GOOGLE_CLIENT_ID in api/config.local.php (see api/config.local.php.example).']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$credential = $data['credential'] ?? null;
if (!$credential) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing credential']);
    exit;
}

// Ask Google to validate the ID token's signature/expiry for us.
$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$ch = curl_init($verifyUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 15);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($curlError || $httpCode !== 200) {
    http_response_code(401);
    echo json_encode(['error' => 'Could not verify Google credential']);
    exit;
}

$tokenInfo = json_decode($response, true);
if (!$tokenInfo) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid Google credential']);
    exit;
}

// The token must have been issued for THIS app's Client ID.
if (($tokenInfo['aud'] ?? null) !== GOOGLE_CLIENT_ID) {
    http_response_code(401);
    echo json_encode(['error' => 'Token audience mismatch']);
    exit;
}

if (($tokenInfo['email_verified'] ?? 'false') !== 'true' && ($tokenInfo['email_verified'] ?? false) !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Google email is not verified']);
    exit;
}

$googleId = $tokenInfo['sub'] ?? null;
$email = $tokenInfo['email'] ?? null;
$name = $tokenInfo['name'] ?? ($tokenInfo['given_name'] ?? 'AI Resume User');
$avatar = $tokenInfo['picture'] ?? null;

if (!$googleId || !$email) {
    http_response_code(400);
    echo json_encode(['error' => 'Google credential missing required fields']);
    exit;
}

$pdo = getPDO();

// 1) Already linked by google_id -> log them in.
$stmt = $pdo->prepare('SELECT user_id, name, email, is_admin FROM users WHERE google_id = ?');
$stmt->execute([$googleId]);
$user = $stmt->fetch();

if (!$user) {
    // 2) Existing local account with the same email -> link it.
    $stmt = $pdo->prepare('SELECT user_id, name, email, is_admin FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user) {
        $upd = $pdo->prepare('UPDATE users SET google_id = ?, avatar_url = ? WHERE user_id = ?');
        $upd->execute([$googleId, $avatar, $user['user_id']]);
    } else {
        // 3) Brand new user, created via Google (no password).
        $user_id = bin2hex(random_bytes(16));
        $ins = $pdo->prepare('INSERT INTO users (user_id, name, email, password_hash, google_id, auth_provider, avatar_url) VALUES (?, ?, ?, NULL, ?, ?, ?)');
        try {
            $ins->execute([$user_id, $name, $email, $googleId, 'google', $avatar]);
            $user = ['user_id' => $user_id, 'name' => $name, 'email' => $email];
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not create account', 'message' => $e->getMessage()]);
            exit;
        }
    }
}

$payload = ['user_id' => $user['user_id'], 'email' => $user['email'], 'name' => $user['name'], 'is_admin' => (bool)($user['is_admin'] ?? false), 'exp' => time() + 2592000]; // 30 days
$token = sign_token($payload);

http_response_code(200);
echo json_encode(['token' => $token, 'user' => $payload]);
