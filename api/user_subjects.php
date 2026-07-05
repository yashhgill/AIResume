<?php
/**
 * User Subjects endpoint
 * GET  ?course_id=N      — get all subjects for a course, annotated with user's selection
 * GET                    — get only the user's selected subjects
 * POST                   — select/deselect subjects (bulk upsert)
 * PUT  ?id=N             — update show_in_resume or grade for one entry
 * DELETE ?subject_id=N   — remove a subject from user's list
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$method  = $_SERVER['REQUEST_METHOD'];
$pdo     = getPDO();
$token   = get_bearer_token();
$payload = $token ? verify_token($token) : null;

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorised']);
    exit;
}
$userId = $payload['user_id'];

// ---- GET ----
if ($method === 'GET') {
    $courseId = (int)($_GET['course_id'] ?? 0);

    if ($courseId) {
        // Return ALL subjects for the course, flagging which ones the user selected
        $stmt = $pdo->prepare(
            'SELECT s.subject_id, s.code, s.name, s.semester, s.learning_outcomes, s.skills_inferred, s.verified,
                    us.id        AS selection_id,
                    us.show_in_resume,
                    us.grade,
                    CASE WHEN us.id IS NOT NULL THEN 1 ELSE 0 END AS selected
             FROM subjects s
             LEFT JOIN user_subjects us ON us.subject_id = s.subject_id AND us.user_id = ?
             WHERE s.course_id = ?
             ORDER BY s.semester ASC, s.name ASC'
        );
        $stmt->execute([$userId, $courseId]);
    } else {
        // Only the user's selected subjects
        $stmt = $pdo->prepare(
            'SELECT s.subject_id, s.code, s.name, s.semester, s.learning_outcomes, s.skills_inferred,
                    us.id AS selection_id, us.show_in_resume, us.grade
             FROM user_subjects us
             JOIN subjects s ON s.subject_id = us.subject_id
             WHERE us.user_id = ?
             ORDER BY s.semester ASC, s.name ASC'
        );
        $stmt->execute([$userId]);
    }

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $r['learning_outcomes'] = $r['learning_outcomes'] ? json_decode($r['learning_outcomes'], true) : [];
        $r['skills_inferred']   = $r['skills_inferred']   ? json_decode($r['skills_inferred'],   true) : [];
    }
    echo json_encode($rows);
    exit;
}

// ---- POST: bulk upsert selected subjects ----
if ($method === 'POST') {
    $data       = json_decode(file_get_contents('php://input'), true) ?? [];
    // Accepts: { subject_ids: [1,2,3], show_in_resume: true }
    // OR: { subjects: [ {subject_id:1, show_in_resume:true, grade:'A'}, ... ] }
    $subjects = [];
    if (isset($data['subjects'])) {
        $subjects = $data['subjects'];
    } elseif (isset($data['subject_ids'])) {
        foreach ($data['subject_ids'] as $sid) {
            $subjects[] = ['subject_id' => (int)$sid, 'show_in_resume' => 1];
        }
    }

    if (empty($subjects)) { http_response_code(400); echo json_encode(['error' => 'No subjects provided']); exit; }

    $upsert = $pdo->prepare(
        'INSERT INTO user_subjects (user_id, subject_id, show_in_resume, grade)
         VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE show_in_resume=VALUES(show_in_resume), grade=COALESCE(VALUES(grade), grade)'
    );
    $count = 0;
    foreach ($subjects as $s) {
        $sid  = (int)($s['subject_id'] ?? 0);
        if (!$sid) continue;
        $show = isset($s['show_in_resume']) ? (int)$s['show_in_resume'] : 1;
        $grade = trim($s['grade'] ?? '') ?: null;
        $upsert->execute([$userId, $sid, $show, $grade]);
        $count++;
    }
    echo json_encode(['ok' => true, 'upserted' => $count]);
    exit;
}

// ---- PUT: update single entry ----
if ($method === 'PUT') {
    $id   = (int)($_GET['id'] ?? 0);
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $stmt = $pdo->prepare('SELECT id FROM user_subjects WHERE id=? AND user_id=?');
    $stmt->execute([$id, $userId]);
    if (!$stmt->fetch()) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }

    $fields = []; $params = [];
    if (array_key_exists('show_in_resume', $data)) { $fields[] = 'show_in_resume=?'; $params[] = (int)$data['show_in_resume']; }
    if (array_key_exists('grade', $data))           { $fields[] = 'grade=?';           $params[] = $data['grade'] ?: null; }
    if ($fields) {
        $params[] = $id;
        $pdo->prepare('UPDATE user_subjects SET ' . implode(',', $fields) . ' WHERE id=?')->execute($params);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---- DELETE ----
if ($method === 'DELETE') {
    $subjectId = (int)($_GET['subject_id'] ?? 0);
    $id        = (int)($_GET['id']         ?? 0);

    if ($subjectId) {
        $pdo->prepare('DELETE FROM user_subjects WHERE user_id=? AND subject_id=?')->execute([$userId, $subjectId]);
    } elseif ($id) {
        $pdo->prepare('DELETE FROM user_subjects WHERE id=? AND user_id=?')->execute([$id, $userId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
