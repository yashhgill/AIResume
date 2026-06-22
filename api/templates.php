<?php
/**
 * Templates API Endpoint
 * Returns list of available resume templates
 * Usage: GET /api/templates.php
 */

require_once __DIR__ . '/config.php';
send_cors_headers();
header('Content-Type: application/json; charset=utf-8');

// Define available templates
$templates = [
    [
        'id' => 'classic',
        'label' => 'Classic',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/classic-preview.svg',
        'description' => 'Traditional, conservative layout with clear section headers'
    ],
    [
        'id' => 'modern',
        'label' => 'Modern',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/modern-preview.svg',
        'description' => 'Contemporary, sleek layout with modern typography'
    ],
    [
        'id' => 'profile',
        'label' => 'Profile',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/profile-preview.svg',
        'description' => 'Prominent profile section with strong personal branding'
    ],
    [
        'id' => 'professional',
        'label' => 'Professional',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/professional-preview.svg',
        'description' => 'Corporate-standard layout with strong emphasis on qualifications'
    ],
    [
        'id' => 'clean',
        'label' => 'Clean',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/clean-preview.svg',
        'description' => 'Minimalist design with maximum readability'
    ],
    [
        'id' => 'creative',
        'label' => 'Creative',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/creative-preview.svg',
        'description' => 'Unique, eye-catching layout showcasing creativity'
    ],
    [
        'id' => 'simple',
        'label' => 'Simple',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/simple-preview.svg',
        'description' => 'Straightforward, no-frills layout with essential information only'
    ],
    [
        'id' => 'two-column',
        'label' => 'Two-column',
        'type' => 'HTML | PDF',
        'preview' => '/resume_generator/assets/templates/two-column-preview.svg',
        'description' => 'Two-column layout maximizing space efficiency'
    ]
];

// Return templates as JSON
echo json_encode([
    'ok' => true,
    'templates' => $templates,
    'count' => count($templates)
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

?>
