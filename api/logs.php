<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query('SELECT log_id, user_id, action, status, timestamp FROM logs ORDER BY timestamp DESC');
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['action'])) { http_response_code(400); echo json_encode(['error' => 'Missing action']); exit; }
    $log_id = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO logs (log_id, user_id, action, status, details) VALUES (?, ?, ?, ?, ?)');
    try {
        $stmt->execute([
            $log_id,
            $data['user_id'] ?? null,
            $data['action'],
            $data['status'] ?? null,
            $data['details'] ?? null,
        ]);
        http_response_code(201);
        echo json_encode(['log_id' => $log_id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Insert failed', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
