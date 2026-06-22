<?php
/**
 * List available Groq models (debug helper)
 * Usage: php list_models.php
 */

require_once __DIR__ . '/config.php';

echo "=== Listing Available Groq Models ===\n\n";

$apiKey = GROQ_API_KEY;
if (!$apiKey) {
    echo "✗ GROQ_API_KEY is not set. Copy api/config.local.php.example to api/config.local.php and add your key from https://console.groq.com/keys\n";
    exit(1);
}

$apiUrl = "https://api.groq.com/openai/v1/models";

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($curlError) {
    echo "✗ cURL Error: {$curlError}\n";
    exit(1);
}

echo "HTTP Code: {$httpCode}\n\n";

if ($httpCode === 200) {
    $data = json_decode($response, true);
    if ($data && isset($data['data'])) {
        echo "✓ Found " . count($data['data']) . " available models:\n\n";
        foreach ($data['data'] as $model) {
            $id = $model['id'] ?? 'Unknown';
            $owner = $model['owned_by'] ?? 'N/A';
            $active = isset($model['active']) ? ($model['active'] ? 'yes' : 'no') : 'N/A';
            $context = $model['context_window'] ?? 'N/A';

            echo "Model: {$id}\n";
            echo "  Owned By: {$owner}\n";
            echo "  Active: {$active}\n";
            echo "  Context Window: {$context}\n\n";
        }
    } else {
        echo "✗ Invalid response format\n";
        echo "Response: " . substr($response, 0, 500) . "\n";
    }
} else {
    echo "✗ Failed to list models\n";
    echo "Response: {$response}\n";

    $errorData = json_decode($response, true);
    if ($errorData && isset($errorData['error']['message'])) {
        echo "\nError: " . $errorData['error']['message'] . "\n";
    }
}

echo "\n=== Complete ===\n";
