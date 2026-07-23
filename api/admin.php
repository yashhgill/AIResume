<?php
/**
 * Admin API — manage institutions, courses, subjects, users, faculties, enrolments, reports.
 * All routes require is_admin = 1 on the authenticated user.
 *
 * GET  ?resource=institutions              — list all (including unverified)
 * GET  ?resource=courses&institution_id=N  — list all courses for institution
 * GET  ?resource=subjects&course_id=N      — list all subjects for course
 * GET  ?resource=users                     — list users
 * GET  ?resource=faculties                 — list all faculties
 * GET  ?resource=enrolments[&status=X]     — list student subject enrolments
 * GET  ?resource=reports                   — PLO coverage per student per course
 * GET  ?resource=stats                     — counts overview
 *
 * POST ?resource=institution|course|subject|faculty  — create record
 *
 * PUT  ?resource=institution&id=N          — verify/edit institution
 * PUT  ?resource=course&id=N               — verify/edit course
 * PUT  ?resource=subject&id=N              — verify/edit subject + CLOs
 * PUT  ?resource=user&id=N                 — toggle is_admin
 * PUT  ?resource=faculty&id=N              — edit faculty
 * PUT  ?resource=enrolment&id=N            — approve/reject {status:"approved"|"rejected"}
 *
 * DELETE ?resource=institution|course|subject|faculty  &id=N
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

// ---- Auth check ----
$token   = get_bearer_token();
$payload = $token ? verify_token($token) : null;
if (!$payload) { http_response_code(401); echo json_encode(['error' => 'Unauthorised']); exit; }

$pdo = getPDO();
$stmt = $pdo->prepare('SELECT is_admin FROM users WHERE user_id = ?');
$stmt->execute([$payload['user_id']]);
$isAdmin = (bool)$stmt->fetchColumn();
if (!$isAdmin) { http_response_code(403); echo json_encode(['error' => 'Admin access required']); exit; }

$method   = $_SERVER['REQUEST_METHOD'];
$resource = strtolower(trim($_GET['resource'] ?? ''));
$id       = (int)($_GET['id'] ?? 0);

// ================================================================
// GET
// ================================================================
if ($method === 'GET') {
    switch ($resource) {

        case 'institutions':
            $stmt = $pdo->query(
                'SELECT i.*, COUNT(c.course_id) AS course_count
                 FROM institutions i
                 LEFT JOIN courses c ON c.institution_id = i.institution_id
                 GROUP BY i.institution_id
                 ORDER BY i.verified DESC, i.name ASC'
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'courses':
            $instId = (int)($_GET['institution_id'] ?? 0);
            $where  = $instId ? 'WHERE c.institution_id = ?' : '';
            $params = $instId ? [$instId] : [];
            $stmt = $pdo->prepare(
                "SELECT c.*, i.name AS institution_name, COUNT(s.subject_id) AS subject_count
                 FROM courses c
                 JOIN institutions i ON i.institution_id = c.institution_id
                 LEFT JOIN subjects s ON s.course_id = c.course_id
                 {$where}
                 GROUP BY c.course_id
                 ORDER BY c.verified DESC, i.name ASC, c.name ASC"
            );
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'subjects':
            $courseId = (int)($_GET['course_id'] ?? 0);
            $where  = $courseId ? 'WHERE s.course_id = ?' : '';
            $params = $courseId ? [$courseId] : [];
            $stmt = $pdo->prepare(
                "SELECT s.*, c.name AS course_name, i.name AS institution_name
                 FROM subjects s
                 JOIN courses c ON c.course_id = s.course_id
                 JOIN institutions i ON i.institution_id = c.institution_id
                 {$where}
                 ORDER BY s.verified DESC, s.semester ASC, s.name ASC"
            );
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['learning_outcomes'] = $r['learning_outcomes'] ? json_decode($r['learning_outcomes'], true) : [];
                $r['skills_inferred']   = $r['skills_inferred']   ? json_decode($r['skills_inferred'],   true) : [];
            }
            echo json_encode($rows);
            break;

        case 'users':
            $stmt = $pdo->query(
                'SELECT user_id, name, email, is_admin, created_at FROM users ORDER BY created_at DESC LIMIT 200'
            );
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'faculties':
            try {
                $stmt = $pdo->query(
                    'SELECT f.*, i.name AS institution_name,
                            COUNT(DISTINCT c.course_id) AS course_count
                     FROM faculties f
                     LEFT JOIN institutions i ON i.institution_id = f.institution_id
                     LEFT JOIN courses c ON c.faculty_id = f.faculty_id
                     GROUP BY f.faculty_id
                     ORDER BY f.name ASC'
                );
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (\Exception $e) { echo json_encode([]); }
            break;

        case 'enrolments':
            try {
                $statusFilter = $_GET['status'] ?? null;
                $where  = $statusFilter ? 'WHERE us.status = ?' : '';
                $params = $statusFilter ? [$statusFilter] : [];
                $stmt = $pdo->prepare(
                    "SELECT us.id, us.user_id, us.subject_id, us.status,
                            us.approved_by, us.approved_at, us.show_in_resume,
                            u.name AS user_name, u.email AS user_email,
                            s.name AS subject_name, s.code AS subject_code,
                            c.name AS course_name, c.course_id
                     FROM user_subjects us
                     JOIN users u ON u.user_id = us.user_id
                     JOIN subjects s ON s.subject_id = us.subject_id
                     LEFT JOIN courses c ON c.course_id = s.course_id
                     {$where}
                     ORDER BY us.id DESC LIMIT 300"
                );
                $stmt->execute($params);
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (\Exception $e) { echo json_encode([]); }
            break;

        case 'reports':
            try {
                $stmt = $pdo->query(
                    "SELECT u.user_id, u.name AS user_name, u.email,
                            c.course_id, c.name AS course_name,
                            COUNT(DISTINCT us.subject_id) AS subjects_approved,
                            STRING_AGG(DISTINCT s.plo_mapping::text, '|||') AS all_plo_mappings
                     FROM user_subjects us
                     JOIN users u ON u.user_id = us.user_id
                     JOIN subjects s ON s.subject_id = us.subject_id
                     JOIN courses c ON c.course_id = s.course_id
                     WHERE us.status = 'approved'
                     GROUP BY u.user_id, c.course_id
                     ORDER BY u.name ASC
                     LIMIT 200"
                );
                $rows   = $stmt->fetchAll(PDO::FETCH_ASSOC);
                $result = [];

                foreach ($rows as $row) {
                    $ploStmt = $pdo->prepare(
                        'SELECT plo_id, code, description FROM programme_outcomes
                         WHERE course_id = ? ORDER BY sort_order ASC, plo_id ASC'
                    );
                    $ploStmt->execute([$row['course_id']]);
                    $allPlos   = $ploStmt->fetchAll(PDO::FETCH_ASSOC);
                    $allPloIds = array_column($allPlos, 'plo_id');
                    $totalPlos = count($allPloIds);

                    $coveredIds = [];
                    if ($row['all_plo_mappings']) {
                        foreach (explode('|||', $row['all_plo_mappings']) as $chunk) {
                            if (!$chunk) continue;
                            $mapping = json_decode($chunk, true);
                            if (is_array($mapping)) {
                                foreach ($mapping as $entry) {
                                    if (isset($entry['plo_ids']) && is_array($entry['plo_ids'])) {
                                        foreach ($entry['plo_ids'] as $pid) $coveredIds[(int)$pid] = true;
                                    }
                                }
                            }
                        }
                    }

                    $coveredCount = $totalPlos > 0
                        ? count(array_intersect(array_keys($coveredIds), array_map('intval', $allPloIds)))
                        : 0;
                    $coveragePct  = $totalPlos > 0 ? round($coveredCount / $totalPlos * 100) : 0;

                    $result[] = [
                        'user_id'           => (int)$row['user_id'],
                        'user_name'         => $row['user_name'],
                        'email'             => $row['email'],
                        'course_id'         => (int)$row['course_id'],
                        'course_name'       => $row['course_name'],
                        'subjects_approved' => (int)$row['subjects_approved'],
                        'total_plos'        => $totalPlos,
                        'covered_plos'      => $coveredCount,
                        'coverage_pct'      => $coveragePct,
                        'plos'              => array_map(function ($p) use ($coveredIds) {
                            return [
                                'plo_id'      => (int)$p['plo_id'],
                                'code'        => $p['code'],
                                'description' => $p['description'],
                                'covered'     => isset($coveredIds[(int)$p['plo_id']]),
                            ];
                        }, $allPlos),
                    ];
                }
                echo json_encode($result);
            } catch (\Exception $e) { echo json_encode([]); }
            break;

        case 'stats':
            $stats = [];
            foreach ([
                'institutions'          => 'SELECT COUNT(*) FROM institutions',
                'institutions_verified' => 'SELECT COUNT(*) FROM institutions WHERE verified=1',
                'courses'               => 'SELECT COUNT(*) FROM courses',
                'subjects'              => 'SELECT COUNT(*) FROM subjects',
                'users'                 => 'SELECT COUNT(*) FROM users',
            ] as $key => $sql) {
                $stats[$key] = (int)$pdo->query($sql)->fetchColumn();
            }
            // New tables — graceful if migration not yet run
            foreach ([
                'faculties'          => 'SELECT COUNT(*) FROM faculties',
                'enrolments_pending' => "SELECT COUNT(*) FROM user_subjects WHERE status = 'pending'",
            ] as $key => $sql) {
                try { $stats[$key] = (int)$pdo->query($sql)->fetchColumn(); }
                catch (\Exception $e) { $stats[$key] = 0; }
            }
            echo json_encode($stats);
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown resource']);
    }
    exit;
}

// ================================================================
// POST  (create new records)
// ================================================================
if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($resource === 'institution') {
        if (empty($data['name'])) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }
        $pdo->prepare(
            'INSERT INTO institutions (name, short_name, country, city, type, verified) VALUES (?,?,?,?,?,?)'
        )->execute([
            trim($data['name']),
            $data['short_name'] ?? null,
            $data['country'] ?? 'Malaysia',
            $data['city'] ?? null,
            $data['type'] ?? 'University',
            (int)($data['verified'] ?? 0),
        ]);
        http_response_code(201);
        echo json_encode(['ok' => true, 'institution_id' => $pdo->lastInsertId('institutions_institution_id_seq')]);
        exit;
    }

    if ($resource === 'course') {
        if (empty($data['name'])) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }
        $pdo->prepare(
            'INSERT INTO courses (institution_id, faculty_id, name, code, level, faculty, verified) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $data['institution_id'] ?? null,
            $data['faculty_id'] ?? null,
            trim($data['name']),
            $data['code'] ?? null,
            $data['level'] ?? 'degree',
            $data['faculty'] ?? null,
            (int)($data['verified'] ?? 0),
        ]);
        http_response_code(201);
        echo json_encode(['ok' => true, 'course_id' => $pdo->lastInsertId('courses_course_id_seq')]);
        exit;
    }

    if ($resource === 'subject') {
        if (empty($data['name'])) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }
        $lo  = isset($data['learning_outcomes']) ? json_encode($data['learning_outcomes']) : null;
        $ski = isset($data['skills_inferred'])   ? json_encode($data['skills_inferred'])   : null;
        $pdo->prepare(
            'INSERT INTO subjects (course_id, name, code, semester, learning_outcomes, skills_inferred, verified) VALUES (?,?,?,?,?,?,?)'
        )->execute([
            $data['course_id'] ?? null,
            trim($data['name']),
            $data['code'] ?? null,
            $data['semester'] ?? null,
            $lo,
            $ski,
            (int)($data['verified'] ?? 0),
        ]);
        http_response_code(201);
        echo json_encode(['ok' => true, 'subject_id' => $pdo->lastInsertId('subjects_subject_id_seq')]);
        exit;
    }

    if ($resource === 'faculty') {
        if (empty($data['name'])) { http_response_code(400); echo json_encode(['error' => 'name required']); exit; }
        try {
            $pdo->prepare(
                'INSERT INTO faculties (institution_id, name, short_name, description, verified) VALUES (?,?,?,?,?)'
            )->execute([
                $data['institution_id'] ?? null,
                trim($data['name']),
                $data['short_name'] ?? null,
                $data['description'] ?? null,
                (int)($data['verified'] ?? 0),
            ]);
            http_response_code(201);
            echo json_encode(['ok' => true, 'faculty_id' => $pdo->lastInsertId('faculties_faculty_id_seq')]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Insert failed', 'message' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown resource']);
    exit;
}

// ================================================================
// PUT
// ================================================================
if ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($resource === 'institution' && $id) {
        $pdo->prepare(
            'UPDATE institutions SET name=COALESCE(?,name), short_name=COALESCE(?,short_name),
             country=COALESCE(?,country), city=COALESCE(?,city), type=COALESCE(?,type),
             verified=COALESCE(?,verified) WHERE institution_id=?'
        )->execute([
            $data['name'] ?? null, $data['short_name'] ?? null,
            $data['country'] ?? null, $data['city'] ?? null,
            $data['type'] ?? null,
            isset($data['verified']) ? (int)$data['verified'] : null,
            $id,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($resource === 'course' && $id) {
        $pdo->prepare(
            'UPDATE courses SET name=COALESCE(?,name), code=COALESCE(?,code),
             level=COALESCE(?,level), faculty=COALESCE(?,faculty),
             verified=COALESCE(?,verified) WHERE course_id=?'
        )->execute([
            $data['name'] ?? null, $data['code'] ?? null,
            $data['level'] ?? null, $data['faculty'] ?? null,
            isset($data['verified']) ? (int)$data['verified'] : null,
            $id,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($resource === 'subject' && $id) {
        $lo  = isset($data['learning_outcomes']) ? json_encode($data['learning_outcomes']) : null;
        $ski = isset($data['skills_inferred'])   ? json_encode($data['skills_inferred'])   : null;
        $pdo->prepare(
            'UPDATE subjects SET name=COALESCE(?,name), code=COALESCE(?,code),
             semester=COALESCE(?,semester),
             learning_outcomes=COALESCE(?,learning_outcomes),
             skills_inferred=COALESCE(?,skills_inferred),
             verified=COALESCE(?,verified) WHERE subject_id=?'
        )->execute([
            $data['name'] ?? null, $data['code'] ?? null, $data['semester'] ?? null,
            $lo, $ski, isset($data['verified']) ? (int)$data['verified'] : null,
            $id,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($resource === 'user' && $id) {
        if ($id === $payload['user_id']) {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot change your own admin status']);
            exit;
        }
        $pdo->prepare('UPDATE users SET is_admin=? WHERE user_id=?')
            ->execute([(int)($data['is_admin'] ?? 0), $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($resource === 'faculty' && $id) {
        try {
            $pdo->prepare(
                'UPDATE faculties SET name=COALESCE(?,name), short_name=COALESCE(?,short_name),
                 description=COALESCE(?,description), verified=COALESCE(?,verified)
                 WHERE faculty_id=?'
            )->execute([
                $data['name'] ?? null,
                $data['short_name'] ?? null,
                $data['description'] ?? null,
                isset($data['verified']) ? (int)$data['verified'] : null,
                $id,
            ]);
            echo json_encode(['ok' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    if ($resource === 'enrolment' && $id) {
        $status = $data['status'] ?? null;
        if (!in_array($status, ['approved', 'rejected'])) {
            http_response_code(400);
            echo json_encode(['error' => 'status must be "approved" or "rejected"']);
            exit;
        }
        try {
            $pdo->prepare(
                'UPDATE user_subjects SET status=?, approved_by=?, approved_at=NOW() WHERE id=?'
            )->execute([$status, $payload['user_id'], $id]);
            echo json_encode(['ok' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown resource or missing id']);
    exit;
}

// ================================================================
// DELETE
// ================================================================
if ($method === 'DELETE') {
    $tableMap = [
        'institution' => ['institutions', 'institution_id'],
        'course'      => ['courses',      'course_id'],
        'subject'     => ['subjects',     'subject_id'],
        'faculty'     => ['faculties',    'faculty_id'],
    ];
    if (isset($tableMap[$resource]) && $id) {
        [$table, $col] = $tableMap[$resource];
        try {
            $pdo->prepare("DELETE FROM {$table} WHERE {$col}=?")->execute([$id]);
            echo json_encode(['ok' => true]);
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown resource or missing id']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
