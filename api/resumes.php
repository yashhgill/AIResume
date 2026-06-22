<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // If id provided as ?id=..., return single resume
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare('SELECT * FROM resumes WHERE resume_id = ?');
        $stmt->execute([$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'Not found']); exit; }
        echo json_encode($row);
        exit;
    }
    // If user_id provided, return resumes for that user
    if (isset($_GET['user_id'])) {
        // Defensive: the `template` column was added by a migration that may
        // not have been run yet on every install, so check before selecting it.
        $hasTemplate = false;
        try {
            // information_schema.columns is ANSI-standard - works the same
            // on MySQL (local dev) and Postgres (Render), unlike MySQL's
            // own "SHOW COLUMNS" meta-syntax which Postgres doesn't have.
            $checkStmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'resumes' AND column_name = 'template'");
            $hasTemplate = $checkStmt->rowCount() > 0;
        } catch (PDOException $e) {
            $hasTemplate = false;
        }
        $templateCol = $hasTemplate ? ', template' : '';
        $stmt = $pdo->prepare("SELECT resume_id, user_id, field, education, skills, experience, tone{$templateCol}, created_at, pdf_url, ai_result_resume, ai_result_letter FROM resumes WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_GET['user_id']]);
        echo json_encode($stmt->fetchAll());
        exit;
    }
    // list all resumes
    $stmt = $pdo->query('SELECT resume_id, user_id, field, tone, created_at FROM resumes ORDER BY created_at DESC');
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['user_id']) || !isset($data['field'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing fields: user_id, field']);
        exit;
    }

    // Support action=generate: create a resume record and populate ai_result_resume using Groq AI
    if (isset($data['action']) && $data['action'] === 'generate') {
        $resume_id = bin2hex(random_bytes(16));
        
        // Get user data
        $name = $data['name'] ?? 'Professional Candidate';
        $tone = $data['tone'] ?? 'professional';
        $template = $data['template'] ?? null;
        $useHtmlCss = $data['generate_html_css'] ?? true; // New flag for HTML+CSS generation
        
        // Generate complete HTML + CSS resume using Groq API
        if ($useHtmlCss) {
            $aiResult = generate_resume_html_css_with_groq(
                $name,
                $data['field'],
                $data['education'] ?? null,
                $data['skills'] ?? null,
                $data['experience'] ?? null,
                $tone,
                $template
            );
            
            // Check if generation was successful
            if (isset($aiResult['error'])) {
                // Log detailed error to console/CMD (for debugging)
                $errorMsg = "AI Generation Error (HTML+CSS): " . $aiResult['error'];
                error_log($errorMsg);
                fwrite(STDERR, $errorMsg . PHP_EOL);
                
                if (isset($aiResult['response'])) {
                    $details = is_string($aiResult['response']) ? $aiResult['response'] : json_encode($aiResult['response']);
                    error_log("Error Details: " . $details);
                    fwrite(STDERR, "Error Details: " . $details . PHP_EOL);
                }
                
                // For web: return simple user-friendly message
                // Detailed error is logged to error log and stderr (visible in CMD/console)
                http_response_code(500);
                echo json_encode([
                    'error' => 'AI generation failed',
                    'message' => 'Sorry, something went wrong while generating your resume. Please try again.'
                ]);
                exit;
            }

            $generatedHtml = $aiResult['html'];
            $aiPrompt = "Generate complete HTML+CSS resume for {$name} applying for {$data['field']} position" . 
                       ($tone ? " with {$tone} tone" : "") .
                       ($template ? " using {$template} template" : "");
            
            // Save HTML file
            $outputDir = __DIR__ . '/../assets/generated_designs/';
            if (!is_dir($outputDir)) {
                mkdir($outputDir, 0755, true);
            }
            
            $htmlPath = $outputDir . $resume_id . '.html';
            file_put_contents($htmlPath, $generatedHtml);
            
            $pdfUrl = backend_base_url() . '/resume_generator/assets/generated_designs/' . $resume_id . '.html';
            $generated = $generatedHtml; // Store HTML in ai_result_resume field
        } else {
            // Legacy: Generate text content only
            $aiResult = generate_resume_with_groq(
                $data['field'],
                $data['education'] ?? null,
                $data['skills'] ?? null,
                $data['experience'] ?? null,
                $tone,
                $template
            );
            
            // Check if generation was successful
            if (isset($aiResult['error'])) {
                // Log detailed error to console/CMD (for debugging)
                $errorMsg = "AI Generation Error (Text): " . $aiResult['error'];
                error_log($errorMsg);
                fwrite(STDERR, $errorMsg . PHP_EOL);
                
                if (isset($aiResult['response'])) {
                    $details = is_string($aiResult['response']) ? $aiResult['response'] : json_encode($aiResult['response']);
                    error_log("Error Details: " . $details);
                    fwrite(STDERR, "Error Details: " . $details . PHP_EOL);
                }
                
                // For web: return simple user-friendly message
                // Detailed error is logged to error log and stderr (visible in CMD/console)
                http_response_code(500);
                echo json_encode([
                    'error' => 'AI generation failed',
                    'message' => 'Sorry, something went wrong while generating your resume. Please try again.'
                ]);
                exit;
            }

            $generated = $aiResult['content'];
            $aiPrompt = "Generate professional resume for {$data['field']} position" . 
                       ($tone ? " with {$tone} tone" : "") .
                       ($template ? " using {$template} template" : "");

            // Generate HTML resume and save file
            $htmlResume = render_resume_html($generated, $template, $data['field']);
            $fileInfo = generate_resume_image($htmlResume, $resume_id, $template);
            
            // Set download URL (prefer image if available, otherwise HTML)
            $pdfUrl = $fileInfo['download_url'];
        }

        // Check if template column exists, if not, use query without it
        try {
            // information_schema.columns is ANSI-standard - works the same
            // on MySQL (local dev) and Postgres (Render), unlike MySQL's
            // own "SHOW COLUMNS" meta-syntax which Postgres doesn't have.
            $checkStmt = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'resumes' AND column_name = 'template'");
            $hasTemplate = $checkStmt->rowCount() > 0;
        } catch (PDOException $e) {
            $hasTemplate = false;
        }

        try {
            if ($hasTemplate) {
                $stmt = $pdo->prepare('INSERT INTO resumes (resume_id, user_id, field, education, skills, experience, tone, template, ai_prompt, ai_result_resume, ai_result_letter, pdf_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $resume_id,
                    $data['user_id'],
                    $data['field'],
                    $data['education'] ?? null,
                    $data['skills'] ?? null,
                    $data['experience'] ?? null,
                    $tone,
                    $template,
                    $aiPrompt,
                    $generated,
                    $data['ai_result_letter'] ?? null,
                    $pdfUrl,
                ]);
            } else {
                // Fallback if template column doesn't exist yet
                $stmt = $pdo->prepare('INSERT INTO resumes (resume_id, user_id, field, education, skills, experience, tone, ai_prompt, ai_result_resume, ai_result_letter, pdf_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    $resume_id,
                    $data['user_id'],
                    $data['field'],
                    $data['education'] ?? null,
                    $data['skills'] ?? null,
                    $data['experience'] ?? null,
                    $tone,
                    $aiPrompt,
                    $generated,
                    $data['ai_result_letter'] ?? null,
                    $pdfUrl,
                ]);
            }
            http_response_code(201);
            echo json_encode([
                'ok' => true,
                'resume_id' => $resume_id,
                'ai_result_resume' => $generated,
                'pdf_url' => $pdfUrl,
                'download_url' => $pdfUrl
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Insert failed', 'message' => $e->getMessage()]);
        }
        exit;
    }

    // Legacy direct insert (if caller already generated content)
    $resume_id = bin2hex(random_bytes(16));
    $stmt = $pdo->prepare('INSERT INTO resumes (resume_id, user_id, field, education, skills, experience, tone, ai_prompt, ai_result_resume, ai_result_letter, pdf_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    try {
        $stmt->execute([
            $resume_id,
            $data['user_id'],
            $data['field'],
            $data['education'] ?? null,
            $data['skills'] ?? null,
            $data['experience'] ?? null,
            $data['tone'] ?? null,
            $data['ai_prompt'] ?? null,
            $data['ai_result_resume'] ?? null,
            $data['ai_result_letter'] ?? null,
            $data['pdf_url'] ?? null,
        ]);
        http_response_code(201);
        echo json_encode(['resume_id' => $resume_id]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Insert failed', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'PUT') {
    // Update resume by id (PUT /resumes.php?id=...)
    if (!isset($_GET['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $id = $_GET['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) { http_response_code(400); echo json_encode(['error' => 'Missing body']); exit; }
    $fields = [];
    $values = [];
    $allowed = ['field','education','skills','experience','tone','template','ai_prompt','ai_result_resume','ai_result_letter','pdf_url'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $data)) { $fields[] = "$f = ?"; $values[] = $data[$f]; }
    }
    if (empty($fields)) { http_response_code(400); echo json_encode(['error' => 'No updatable fields']); exit; }
    $values[] = $id;
    $sql = 'UPDATE resumes SET ' . implode(', ', $fields) . ' WHERE resume_id = ?';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Update failed', 'message' => $e->getMessage()]);
    }
    exit;
}

if ($method === 'DELETE') {
    if (!isset($_GET['id'])) { http_response_code(400); echo json_encode(['error' => 'Missing id']); exit; }
    $stmt = $pdo->prepare('DELETE FROM resumes WHERE resume_id = ?');
    try {
        $stmt->execute([$_GET['id']]);
        echo json_encode(['ok' => true]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Delete failed', 'message' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
