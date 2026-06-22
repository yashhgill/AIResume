<?php
/**
 * Save Resume HTML File
 * Saves edited HTML content to file
 * Usage: POST /api/save_resume_file.php?id=RESUME_ID
 */

require_once __DIR__ . '/config.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing resume ID']);
    exit;
}

$resumeId = $_GET['id'];
$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['html'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing HTML content']);
    exit;
}

$htmlContent = $data['html'];

// Save HTML file
$outputDir = __DIR__ . '/../assets/generated_designs/';
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0755, true);
}

$htmlPath = $outputDir . $resumeId . '.html';

try {
    file_put_contents($htmlPath, $htmlContent);
    echo json_encode([
        'ok' => true,
        'message' => 'File saved successfully',
        'path' => backend_base_url() . '/resume_generator/assets/generated_designs/' . $resumeId . '.html'
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to save file',
        'message' => $e->getMessage()
    ]);
}

?>
