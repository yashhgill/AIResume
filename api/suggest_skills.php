<?php
/**
 * AI Skill Suggestion endpoint
 *
 * Takes the user's selected subjects (with their learning outcomes) and asks
 * Groq to infer a realistic set of technical + professional skills.
 *
 * POST  { subject_ids: [1,2,3] }
 *   OR
 * POST  { subjects: [ {name, learning_outcomes:[], skills_inferred:[] }, ... ] }
 *
 * Returns: { ok: true, skills: [ {skill_name, category, proficiency, source:'ai'}, ... ] }
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$token   = get_bearer_token();
$payload = $token ? verify_token($token) : null;
if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }

$pdo  = getPDO();
$data = json_decode(file_get_contents('php://input'), true) ?? [];

$subjects = [];

// Option A: frontend sends subject_ids → we fetch from DB
if (!empty($data['subject_ids'])) {
    $ids  = array_map('intval', $data['subject_ids']);
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT name, learning_outcomes, skills_inferred FROM subjects WHERE subject_id IN ({$ph})");
    $stmt->execute($ids);
    $subjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($subjects as &$s) {
        $s['learning_outcomes'] = $s['learning_outcomes'] ? json_decode($s['learning_outcomes'], true) : [];
        $s['skills_inferred']   = $s['skills_inferred']   ? json_decode($s['skills_inferred'],   true) : [];
    }
}

// Option B: frontend already has the subject objects
if (!empty($data['subjects'])) {
    $subjects = $data['subjects'];
}

if (empty($subjects)) {
    http_response_code(400);
    echo json_encode(['error' => 'No subjects provided']);
    exit;
}

// Build a compact summary of what the student studied
$subjectSummary = '';
foreach ($subjects as $s) {
    $subjectSummary .= '- ' . $s['name'];
    $los = $s['learning_outcomes'] ?? [];
    if ($los) {
        $subjectSummary .= ' (CLOs: ' . implode('; ', array_slice($los, 0, 3)) . ')';
    }
    $preInferred = $s['skills_inferred'] ?? [];
    if ($preInferred) {
        $subjectSummary .= ' [pre-mapped: ' . implode(', ', $preInferred) . ']';
    }
    $subjectSummary .= "\n";
}

$courseContext = trim($data['course_name'] ?? '');
$contextLine = $courseContext ? "The student is studying {$courseContext}.\n\n" : '';

$prompt = $contextLine .
    "Based on the following academic subjects and their learning outcomes, list the realistic technical and professional skills the student would have acquired:\n\n" .
    $subjectSummary . "\n" .
    "Rules:\n" .
    "- Be specific (e.g. 'Python' not 'Programming')\n" .
    "- Include frameworks/libraries where likely (e.g. 'React', 'TensorFlow')\n" .
    "- Include soft skills if clearly evidenced by CLOs\n" .
    "- Do NOT include the same skill twice\n" .
    "- Proficiency: beginner if only introductory exposure, intermediate if 2+ subjects use it, advanced if core to curriculum\n" .
    "- Return ONLY valid JSON — no markdown, no explanation:\n" .
    '[\n  {"skill_name":"Python","category":"Programming Language","proficiency":"intermediate"},\n' .
    '  {"skill_name":"Machine Learning","category":"AI/ML","proficiency":"beginner"}\n]';

$result = call_groq_api([
    'model'       => 'llama-3.3-70b-versatile',
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.3,
    'max_tokens'  => 2000,
]);

if ($result['curlError'] || $result['httpCode'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'AI service error']);
    exit;
}

$parsed  = json_decode($result['response'], true);
$aiText  = $parsed['choices'][0]['message']['content'] ?? '';

// Strip fences
$aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
$aiText = preg_replace('/^```\s*/i',     '', $aiText);
$aiText = preg_replace('/\s*```$/i',     '', $aiText);

$skills = json_decode($aiText, true);
if (!is_array($skills)) {
    http_response_code(500);
    echo json_encode(['error' => 'AI returned unexpected format', 'raw' => substr($aiText, 0, 300)]);
    exit;
}

// Normalise + tag as AI source
$allowed_cats = ['Programming Language','Framework/Library','Database','DevOps/Tools',
                 'AI/ML','Web Development','Mobile Development','Networking',
                 'Soft Skill','Domain Knowledge','Other'];
$result_skills = [];
foreach ($skills as $s) {
    $name = trim($s['skill_name'] ?? '');
    if (!$name) continue;
    $cat = trim($s['category'] ?? 'Other');
    if (!in_array($cat, $allowed_cats)) $cat = 'Other';
    $prof = in_array($s['proficiency'] ?? '', ['beginner','intermediate','advanced','expert'])
        ? $s['proficiency'] : 'intermediate';
    $result_skills[] = [
        'skill_name'  => $name,
        'category'    => $cat,
        'proficiency' => $prof,
        'source'      => 'ai',
        'show_in_resume' => 1,
    ];
}

echo json_encode(['ok' => true, 'skills' => $result_skills]);
