<?php
/**
 * Download Resume as Image/PDF
 * Usage: /api/download_resume.php?id=RESUME_ID&format=png|pdf|html
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = getPDO();

if (!isset($_GET['id'])) {
    http_response_code(400);
    die('Missing resume ID');
}

$resumeId = $_GET['id'];
$format = $_GET['format'] ?? 'html'; // png, pdf, or html

$stmt = $pdo->prepare('SELECT * FROM resumes WHERE resume_id = ?');
$stmt->execute([$resumeId]);
$resume = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resume) {
    http_response_code(404);
    die('Resume not found');
}

$template = $resume['template'] ?? 'classic';
$content = $resume['ai_result_resume'] ?? '';

// Generate HTML if needed
$htmlPath = __DIR__ . '/../assets/generated_designs/' . $resumeId . '.html';
if (!file_exists($htmlPath) && !empty($content)) {
    $htmlResume = render_resume_html($content, $template, $resume['field']);
    $fileInfo = generate_resume_image($htmlResume, $resumeId, $template);
    $htmlContent = $fileInfo['html_content'];
    file_put_contents($htmlPath, $htmlContent);
} else if (file_exists($htmlPath)) {
    $htmlContent = file_get_contents($htmlPath);
} else {
    $htmlContent = render_resume_html($content, $template, $resume['field']);
}

// Handle different formats
if ($format === 'png') {
    // Try to generate PNG if available
    $imagePath = __DIR__ . '/../assets/generated_designs/' . $resumeId . '.png';
    if (file_exists($imagePath)) {
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="resume-' . $resumeId . '.png"');
        readfile($imagePath);
        exit;
    } else {
        // Fallback: redirect to HTML with instructions
        header('Location: /resume_generator/api/view_resume.php?id=' . $resumeId . '&note=image');
        exit;
    }
} else if ($format === 'pdf') {
    // Route through view_resume.php's real PDF pipeline (html2canvas + jsPDF,
    // the same one the in-editor "Download PDF" button uses) instead of the
    // old print-dialog trick here, which was a different, less reliable
    // code path that didn't share any of the one-page-fit fixes.
    header('Location: /resume_generator/api/view_resume.php?id=' . $resumeId . '&autodownload=1');
    exit;
} else {
    // Default: HTML view with Edit and Download buttons
    // For HTML format, use view_resume.php which has all edit functionality
    // This ensures consistency and all features are available
    header('Location: /resume_generator/api/view_resume.php?id=' . $resumeId);
    exit;
}

?>




