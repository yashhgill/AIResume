<?php
/**
 * Career Insights — Job Title Suggestions + PLO Coverage
 *
 * POST {
 *   course_name:  "Software Engineering",
 *   field:        "Software Engineer",
 *   skills:       [ {skill_name, category, proficiency}, ... ],
 *   subject_ids:  [1,2,3]
 * }
 *
 * Returns {
 *   ok: true,
 *   overall_score: 72,
 *   jobs: [ { title, match_pct, matching_skills, missing_skills }, ... ],
 *   certifications: [ { name, reason, url }, ... ],
 *   plo_coverage: [           // only present if PLOs are configured
 *     { course_id, course_name, total_plos, covered_plos, coverage_pct,
 *       plos: [ { plo_id, code, description, covered }, ... ] }
 *   ]
 * }
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

$courseName  = trim($data['course_name'] ?? '');
$targetField = trim($data['field']       ?? '');
$skills      = $data['skills']           ?? [];
$subjectIds  = array_map('intval', $data['subject_ids'] ?? []);

// If subject_ids provided, pull subject names + CLOs for richer context
$subjectContext = '';
if (!empty($subjectIds)) {
    try {
        $ph   = implode(',', array_fill(0, count($subjectIds), '?'));
        $stmt = $pdo->prepare("SELECT name, learning_outcomes FROM subjects WHERE subject_id IN ({$ph})");
        $stmt->execute($subjectIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if ($rows) {
            $subjectContext = "\nSubjects studied:\n";
            foreach ($rows as $r) {
                $subjectContext .= '- ' . $r['name'];
                $los = $r['learning_outcomes'] ? json_decode($r['learning_outcomes'], true) : [];
                if ($los) $subjectContext .= ': ' . implode('; ', array_slice($los, 0, 2));
                $subjectContext .= "\n";
            }
        }
    } catch (\Exception $e) { /* subjects table may not exist */ }
}

// Build skills summary
$skillLines = '';
foreach ($skills as $s) {
    $skillLines .= '- ' . ($s['skill_name'] ?? '') . ' (' . ($s['category'] ?? '') . ', ' . ($s['proficiency'] ?? 'intermediate') . ")\n";
}

if (empty($skillLines)) {
    try {
        $stmt = $pdo->prepare(
            'SELECT skill_name, category, proficiency FROM user_skills
             WHERE user_id = ? AND show_in_resume = 1 ORDER BY source DESC LIMIT 40'
        );
        $stmt->execute([$payload['user_id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $skillLines .= '- ' . $r['skill_name'] . ' (' . ($r['category'] ?? '') . ', ' . ($r['proficiency'] ?? '') . ")\n";
            $skills[] = $r;
        }
    } catch (\Exception $e) {}
}

if (empty($skillLines)) {
    echo json_encode(['ok' => true, 'overall_score' => 0, 'jobs' => [], 'certifications' => [],
                      'note' => 'No skills found. Complete the Skills step first.']);
    exit;
}

// ── PLO Coverage (computed from DB, independent of AI) ──────────────────────
$ploCoverage = null;
try {
    $stmt = $pdo->prepare(
        "SELECT s.plo_mapping, s.course_id
         FROM user_subjects us
         JOIN subjects s ON s.subject_id = us.subject_id
         WHERE us.user_id = ? AND us.status = 'approved' AND s.plo_mapping IS NOT NULL"
    );
    $stmt->execute([$payload['user_id']]);
    $subjRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($subjRows) {
        // Group mappings by course_id
        $byCourse = [];
        foreach ($subjRows as $row) {
            $cid = $row['course_id'];
            if (!isset($byCourse[$cid])) $byCourse[$cid] = [];
            $mapping = json_decode($row['plo_mapping'], true);
            if (is_array($mapping)) $byCourse[$cid][] = $mapping;
        }

        $coverageData = [];
        foreach ($byCourse as $courseId => $mappings) {
            $ploStmt = $pdo->prepare(
                'SELECT plo_id, code, description FROM programme_outcomes
                 WHERE course_id = ? ORDER BY sort_order ASC, plo_id ASC'
            );
            $ploStmt->execute([$courseId]);
            $allPlos = $ploStmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$allPlos) continue;

            $allPloIds = array_column($allPlos, 'plo_id');
            $totalPlos = count($allPloIds);

            $coveredIds = [];
            foreach ($mappings as $subjectMappings) {
                foreach ($subjectMappings as $entry) {
                    if (isset($entry['plo_ids'])) {
                        foreach ($entry['plo_ids'] as $pid) $coveredIds[(int)$pid] = true;
                    }
                }
            }

            $coveredCount = count(array_intersect(array_keys($coveredIds), array_map('intval', $allPloIds)));
            $coveragePct  = $totalPlos > 0 ? round($coveredCount / $totalPlos * 100) : 0;

            $cnStmt = $pdo->prepare('SELECT name FROM courses WHERE course_id = ?');
            $cnStmt->execute([$courseId]);
            $cName = $cnStmt->fetchColumn() ?: 'Your course';

            $coverageData[] = [
                'course_id'    => $courseId,
                'course_name'  => $cName,
                'total_plos'   => $totalPlos,
                'covered_plos' => $coveredCount,
                'coverage_pct' => $coveragePct,
                'plos'         => array_map(function ($p) use ($coveredIds) {
                    return [
                        'plo_id'      => (int)$p['plo_id'],
                        'code'        => $p['code'],
                        'description' => $p['description'],
                        'covered'     => isset($coveredIds[(int)$p['plo_id']]),
                    ];
                }, $allPlos),
            ];
        }

        if (!empty($coverageData)) $ploCoverage = $coverageData;
    }
} catch (\Exception $e) { /* programme_outcomes table may not exist yet */ }
// ────────────────────────────────────────────────────────────────────────────

$contextParts = [];
if ($courseName)  $contextParts[] = "Course: {$courseName}";
if ($targetField) $contextParts[] = "Target field: {$targetField}";
$contextLine = $contextParts ? implode(' | ', $contextParts) . "\n\n" : '';

$prompt = $contextLine .
    "Skills and proficiency:\n" . $skillLines . $subjectContext .
    "\nTask: Based on this student's skills and academic background, provide a career analysis.\n\n" .
    "Return ONLY valid JSON (no markdown, no explanation) in this exact structure:\n" .
    '{"overall_score":72,' .
    '"jobs":[' .
      '{"title":"Software Engineer","match_pct":85,"matching_skills":["Python","MySQL"],"missing_skills":["Docker","Kubernetes"]},' .
      '{"title":"Data Analyst","match_pct":70,"matching_skills":["Python","SQL"],"missing_skills":["Tableau","Power BI"]}' .
    '],' .
    '"certifications":[' .
      '{"name":"AWS Certified Developer – Associate","reason":"Covers cloud deployment gap seen across top job matches","url":"https://aws.amazon.com/certification/certified-developer-associate/"},' .
      '{"name":"Google Data Analytics Certificate","reason":"Bridges the data visualisation skill gap","url":"https://grow.google/certificates/data-analytics/"}' .
    ']}' .
    "\n\nRules:\n" .
    "- Return 4–6 job titles most relevant to the skills and course\n" .
    "- match_pct = realistic estimate (0–100) of how well the skill set matches that role\n" .
    "- overall_score = weighted average match across top 3 jobs\n" .
    "- missing_skills: max 4, specific (e.g. 'Kubernetes' not 'DevOps')\n" .
    "- certifications: 2–4 specific, real certifications with real URLs\n" .
    "- Be honest — do not inflate scores";

$result = call_groq_api([
    'model'       => 'llama-3.3-70b-versatile',
    'messages'    => [['role' => 'user', 'content' => $prompt]],
    'temperature' => 0.3,
    'max_tokens'  => 1500,
]);

if ($result['curlError'] || $result['httpCode'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'AI service error', 'detail' => $result['curlError'] ?: $result['httpCode']]);
    exit;
}

$parsed = json_decode($result['response'], true);
$aiText = $parsed['choices'][0]['message']['content'] ?? '';

// Strip markdown fences
$aiText = preg_replace('/^```json\s*/i', '', trim($aiText));
$aiText = preg_replace('/^```\s*/i',     '', $aiText);
$aiText = preg_replace('/\s*```$/i',     '', $aiText);

$career = json_decode($aiText, true);
if (!is_array($career)) {
    http_response_code(500);
    echo json_encode(['error' => 'AI returned unexpected format', 'raw' => substr($aiText, 0, 400)]);
    exit;
}

$response = array_merge(['ok' => true], $career);
if ($ploCoverage !== null) $response['plo_coverage'] = $ploCoverage;
echo json_encode($response);
