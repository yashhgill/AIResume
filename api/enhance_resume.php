<?php
/**
 * AI Enhance Resume endpoint.
 *
 * Accepts the current resume body HTML (with user's manual edits applied),
 * sends it to Groq, and returns an improved version with:
 *   - Spelling and grammar fixes
 *   - Stronger, more impactful bullet points (action verbs, quantified where possible)
 *   - More professional phrasing
 *   - Additional relevant detail where content is thin or vague
 *
 * The HTML structure, CSS classes, inline styles, and IDs are preserved —
 * only the text content is improved.
 *
 * POST /api/enhance_resume.php
 * Body: { "html": "<body content string>" }
 * Returns: { "ok": true, "improved_html": "..." }
 */

require_once __DIR__ . '/config.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$html = trim($data['html'] ?? '');

if (empty($html)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing html']);
    exit;
}

// Groq llama-3.3-70b supports 128k context, but keep it sane
if (strlen($html) > 80000) {
    $html = substr($html, 0, 80000);
}

$systemPrompt = 'You are an expert professional resume writer and editor. You will receive the HTML body content of a resume and must return an improved version.

Your improvements:
1. Fix ALL spelling and grammar errors
2. Strengthen bullet points with powerful action verbs (e.g. "helped with" → "spearheaded", "worked on" → "delivered")
3. Quantify achievements where plausible (e.g. "improved performance" → "improved system performance by ~30%")
4. Replace vague or generic phrases with specific, professional language
5. Add relevant detail where descriptions are too thin (1-2 words or generic placeholder text)
6. Ensure consistent verb tense (past for previous roles, present for current)
7. Make the overall tone confident and impactful

STRICT RULES — these override everything above:
- Keep the EXACT same HTML structure: all tags, attributes, CSS classes, inline styles, IDs
- Only modify the visible TEXT CONTENT inside HTML tags — never change any HTML/CSS
- Do NOT add or remove any HTML elements or tags
- Do NOT wrap output in markdown code fences or add any explanation
- Return ONLY the improved HTML, nothing else';

$requestData = [
    'model'    => 'llama-3.3-70b-versatile',
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user',   'content' => $html],
    ],
    'temperature' => 0.35,
    'max_tokens'  => 8000,
];

$result = call_groq_api($requestData);

if ($result['curlError'] || $result['httpCode'] !== 200) {
    http_response_code(500);
    $detail = $result['curlError'] ?: ('HTTP ' . $result['httpCode']);
    echo json_encode(['error' => 'AI service error', 'detail' => $detail]);
    exit;
}

$responseData    = json_decode($result['response'], true);
$improvedHtml    = $responseData['choices'][0]['message']['content'] ?? null;

if (!$improvedHtml) {
    http_response_code(500);
    echo json_encode(['error' => 'Empty response from AI']);
    exit;
}

// Strip markdown code fences AI sometimes wraps output in
$improvedHtml = preg_replace('/^```html\s*/i', '', trim($improvedHtml));
$improvedHtml = preg_replace('/^```\s*/i',     '', $improvedHtml);
$improvedHtml = preg_replace('/\s*```$/i',     '', $improvedHtml);

echo json_encode(['ok' => true, 'improved_html' => trim($improvedHtml)]);
