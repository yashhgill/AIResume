<?php
/**
 * Subjects endpoint
 *
 * GET  ?course_id=N              — list all subjects for a course
 * GET  ?id=N                     — single subject
 * POST                           — add subject(s) to a course
 * POST ?action=ai_suggest        — ask AI to suggest subjects for a new/unknown course
 * PUT  ?id=N  (admin)            — update / verify / edit CLOs
 * DELETE ?id=N (admin)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = trim($_GET['action'] ?? '');
$pdo    = getPDO();

// ---- GET ----
if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM subjects WHERE subject_id = ?');
        $stmt->execute([(int)$_GET['id']]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC) ?: null);
        exit;
    }

    $courseId = (int)($_GET['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error' => 'course_id required']); exit; }

    $stmt = $pdo->prepare(
        'SELECT subject_id, code, name, semester, learning_outcomes, skills_inferred, verified
         FROM subjects WHERE course_id = ? ORDER BY semester ASC, name ASC'
    );
    $stmt->execute([$courseId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON columns
    foreach ($rows as &$r) {
        $r['learning_outcomes'] = $r['learning_outcomes'] ? json_decode($r['learning_outcomes'], true) : [];
        $r['skills_inferred']   = $r['skills_inferred']   ? json_decode($r['skills_inferred'],   true) : [];
    }
    echo json_encode($rows);
    exit;
}

// ---- POST: AI suggest subjects for a brand-new course ----
if ($method === 'POST' && $action === 'ai_suggest') {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    $courseName = trim($data['course_name'] ?? '');
    $level      = trim($data['level'] ?? 'degree');
    $instName   = trim($data['institution_name'] ?? '');

    if (!$courseName) { http_response_code(400); echo json_encode(['error' => 'course_name required']); exit; }

    $prompt = "You are an expert academic curriculum designer. A student is studying: \"{$courseName}\" ({$level} level)" .
              ($instName ? " at {$instName}" : '') . ".\n\n" .
              "Generate a realistic list of 10-14 typical subjects/modules for this course.\n\n" .
              "Return ONLY a valid JSON array (no markdown, no explanation) in this exact format:\n" .
              '[\n  {\n    "code": "SUBJECT001",\n    "name": "Subject Name",\n    "semester": 1,\n' .
              '    "learning_outcomes": ["Outcome 1","Outcome 2","Outcome 3"],\n' .
              '    "skills_inferred": ["Skill1","Skill2","Skill3"]\n  }\n]';

    $result = call_groq_api([
        'model'       => 'llama-3.3-70b-versatile',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.4,
        'max_tokens'  => 3000,
    ]);

    if ($result['curlError'] || $result['httpCode'] !== 200) {
        http_response_code(500); echo json_encode(['error' => 'AI error']); exit;
    }

    $content = $result['response'];
    $parsed  = json_decode($content, true);
    $aiText  = $parsed['choices'][0]['message']['content'] ?? '';

    // Strip markdown fences
    $aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
    $aiText = preg_replace('/^```\s*/i', '', $aiText);
    $aiText = preg_replace('/\s*```$/i', '', $aiText);

    $subjects = json_decode($aiText, true);
    if (!is_array($subjects)) {
        http_response_code(500); echo json_encode(['error' => 'AI returned unexpected format', 'raw' => $aiText]); exit;
    }

    echo json_encode(['ok' => true, 'subjects' => $subjects]);
    exit;
}

// ---- POST: save subjects (bulk or single) ----
if ($method === 'POST') {
    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $courseId = (int)($data['course_id'] ?? 0);
    if (!$courseId) { http_response_code(400); echo json_encode(['error' => 'course_id required']); exit; }

    $userId = null;
    $token  = get_bearer_token();
    if ($token) { $p = verify_token($token); if ($p) $userId = $p['user_id']; }

    // Support both single subject and bulk array
    $items = isset($data['subjects']) ? $data['subjects'] : [$data];
    $saved = [];

    $stmt = $pdo->prepare(
        'INSERT INTO subjects (course_id, code, name, semester, learning_outcomes, skills_inferred, verified, created_by)
         VALUES (?,?,?,?,?,?,0,?)
         ON CONFLICT (course_id, name) DO NOTHING'
    );
    foreach ($items as $s) {
        $name = trim($s['name'] ?? '');
        if (!$name) continue;
        $lo  = isset($s['learning_outcomes']) ? json_encode($s['learning_outcomes']) : null;
        $ski = isset($s['skills_inferred'])   ? json_encode($s['skills_inferred'])   : null;
        $stmt->execute([
            $courseId,
            trim($s['code'] ?? '') ?: null,
            $name,
            isset($s['semester']) ? (int)$s['semester'] : null,
            $lo,
            $ski,
            $userId,
        ]);
        $saved[] = (int)$pdo->lastInsertId('subjects_subject_id_seq');
    }

    // Fetch and return saved subjects
    $placeholders = implode(',', array_fill(0, count($saved), '?'));
    if ($saved) {
        $rows = $pdo->prepare("SELECT subject_id, code, name, semester, learning_outcomes, skills_inferred FROM subjects WHERE subject_id IN ({$placeholders})");
        $rows->execute($saved);
        $subjects = $rows->fetchAll(PDO::FETCH_ASSOC);
        foreach ($subjects as &$r) {
            $r['learning_outcomes'] = $r['learning_outcomes'] ? json_decode($r['learning_outcomes'], true) : [];
            $r['skills_inferred']   = $r['skills_inferred']   ? json_decode($r['skills_inferred'],   true) : [];
        }
        echo json_encode(['ok' => true, 'saved' => count($saved), 'subjects' => $subjects]);
    } else {
        echo json_encode(['ok' => true, 'saved' => 0, 'subjects' => []]);
    }
    exit;
}

// ---- PUT (admin) ----
if ($method === 'PUT') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id=?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $lo   = isset($data['learning_outcomes']) ? json_encode($data['learning_outcomes']) : null;
    $ski  = isset($data['skills_inferred'])   ? json_encode($data['skills_inferred'])   : null;
    $pdo->prepare(
        'UPDATE subjects SET name=COALESCE(?,name), code=COALESCE(?,code), semester=COALESCE(?,semester),
         learning_outcomes=COALESCE(?,learning_outcomes), skills_inferred=COALESCE(?,skills_inferred),
         verified=COALESCE(?,verified) WHERE subject_id=?'
    )->execute([$data['name'] ?? null, $data['code'] ?? null, $data['semester'] ?? null, $lo, $ski,
                isset($data['verified']) ? (int)$data['verified'] : null, $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ---- DELETE (admin) ----
if ($method === 'DELETE') {
    $token = get_bearer_token();
    $payload = $token ? verify_token($token) : null;
    if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }
    $stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id=?');
    $stmt->execute([$payload['user_id']]);
    if (!$stmt->fetchColumn()) { http_response_code(403); echo json_encode(['error' => 'Forbidden']); exit; }

    $pdo->prepare('DELETE FROM subjects WHERE subject_id=?')->execute([(int)($_GET['id'] ?? 0)]);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
