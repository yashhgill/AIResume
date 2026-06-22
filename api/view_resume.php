<?php
/**
 * View/Download Resume HTML
 * Usage: /api/view_resume.php?id=RESUME_ID
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = getPDO();

if (!isset($_GET['id'])) {
    http_response_code(400);
    die('Missing resume ID');
}

$resumeId = $_GET['id'];
$isPreview = isset($_GET['preview']) && $_GET['preview'] == '1';
$autoDownload = isset($_GET['autodownload']) && $_GET['autodownload'] == '1';
$autoEdit = isset($_GET['autoedit']) && $_GET['autoedit'] == '1';

// Where Save/Cancel send the browser back to. A relative path only works
// when frontend+backend share one origin (local dev) - once the frontend
// is deployed on a different domain (e.g. Cloudflare Pages), this page
// (served by the backend) needs the frontend's full URL instead, or the
// browser tries to load that path from the BACKEND's own domain and 404s.
$myResumesUrl = (FRONTEND_BASE_URL !== '')
    ? rtrim(FRONTEND_BASE_URL, '/') . '/resume_generator/frontendreact/my-resumes.html'
    : '/resume_generator/frontendreact/my-resumes.html';
$stmt = $pdo->prepare('SELECT * FROM resumes WHERE resume_id = ?');
$stmt->execute([$resumeId]);
$resume = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$resume) {
    http_response_code(404);
    die('Resume not found');
}

// Get template
$template = $resume['template'] ?? 'classic';
$content = $resume['ai_result_resume'] ?? '';

// Check if content is already complete HTML (from the AI HTML+CSS generation)
$isCompleteHtml = !empty($content) && (
    strpos($content, '<!DOCTYPE html') !== false || 
    strpos($content, '<html') !== false ||
    strpos($content, '<style') !== false
);

// Generate HTML if not already saved
$htmlPath = __DIR__ . '/../assets/generated_designs/' . $resumeId . '.html';
$htmlContent = '';

// Priority 1: Check if HTML file exists (MOST RELIABLE)
if (file_exists($htmlPath)) {
    $fileContent = file_get_contents($htmlPath);
    
    // Check if HTML is complete (has closing tags)
    $hasClosingTags = strpos($fileContent, '</body>') !== false || strpos($fileContent, '</html>') !== false;
    $isValidLength = strlen(trim($fileContent)) > 500; // Minimum reasonable HTML size
    
    // If file exists and has content, use it
    if (!empty($fileContent) && $isValidLength) {
        // If HTML is incomplete (missing closing tags), try to fix it
        if (!$hasClosingTags) {
            // Check if we have a <body> tag - if so, add closing tags
            if (strpos($fileContent, '<body>') !== false || strpos($fileContent, '<body ') !== false) {
                // Find the last </style> or </head> to determine where body content starts
                $bodyStart = strpos($fileContent, '<body');
                if ($bodyStart !== false) {
                    // Extract content up to body tag end
                    $bodyTagEnd = strpos($fileContent, '>', $bodyStart);
                    if ($bodyTagEnd !== false) {
                        $bodyContent = substr($fileContent, $bodyTagEnd + 1);
                        // Reconstruct complete HTML
                        $htmlHead = substr($fileContent, 0, $bodyTagEnd + 1);
                        $fileContent = $htmlHead . $bodyContent . '</body></html>';
                    }
                }
            } else {
                // No body tag - this might be just CSS or incomplete
                // Check if it's just CSS that needs wrapping
                if (strpos($fileContent, '<style>') !== false || strpos($fileContent, '<style ') !== false) {
                    // Try to complete it
                    if (strpos($fileContent, '<!DOCTYPE') === false) {
                        $fileContent = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $fileContent . '</head><body><div class="resume-container"><h1>Resume</h1><p>Content loading...</p></div></body></html>';
                    } else {
                        $fileContent = $fileContent . '</body></html>';
                    }
                }
            }
        }
        
        $htmlContent = $fileContent;
        
        // Only clean up if we detect edit mode artifacts (to avoid breaking valid HTML)
        // Check if this looks like it has edit controls already
        if (strpos($htmlContent, 'editModeEnabled') === false && strpos($htmlContent, 'btnEdit') === false) {
            // This is clean HTML from the AI generator - use as is, no cleaning needed
            // We'll add edit functionality below
        } else {
            // Has edit controls - clean up artifacts
            
            // Remove all scripts from saved content to prevent duplication and auto-execution
            $htmlContent = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $htmlContent);
            
            // Remove resumeControls container (fixes duplicate buttons/styles)
            $htmlContent = preg_replace('/<div[^>]*id=["\']resumeControls["\'][^>]*>.*?<\/div>/is', "", $htmlContent);
            
            // Remove other UI containers (assuming no nested divs or simple structure)
            $htmlContent = preg_replace('/<div[^>]*id=["\']selectionBox["\'][^>]*>.*?<\/div>/is', "", $htmlContent);
            $htmlContent = preg_replace('/<div[^>]*id=["\']pageOverflowWarning["\'][^>]*>.*?<\/div>/is', "", $htmlContent);
            
            // Clean up attributes
            $htmlContent = preg_replace('/\s+contenteditable=["\']true["\']/i', '', $htmlContent);
            $htmlContent = preg_replace('/\s+contenteditable=["\']false["\']/i', '', $htmlContent);
            $htmlContent = preg_replace('/\s+class=["\'][^"\']*no-edit[^"\']*["\']/i', '', $htmlContent);
            $htmlContent = preg_replace('/\s+style="[^"]*outline[^"]*"/i', '', $htmlContent);
        }
    } else {
        // File exists but is empty or corrupted
        $htmlContent = '';
    }
}

// Priority 2: If file doesn't exist or is empty, use database content
if (empty($htmlContent) && $isCompleteHtml) {
    // Content is already complete HTML from the AI generator - use it directly
    $htmlContent = $content;
    
    // Ensure content is not empty
    if (!empty($htmlContent) && strlen($htmlContent) > 100) {
    // Save it for future use
    if (!is_dir(dirname($htmlPath))) {
        mkdir(dirname($htmlPath), 0755, true);
    }
    file_put_contents($htmlPath, $htmlContent);
    }
}

// Priority 3: If still empty, try legacy generation
if (empty($htmlContent) && !empty($content)) {
    // Legacy: Generate HTML from text content using template
    $htmlResume = render_resume_html($content, $template, $resume['field']);
    $fileInfo = generate_resume_image($htmlResume, $resumeId, $template);
    $htmlContent = $fileInfo['html_content'];
    
    // Save the generated HTML
    if (!is_dir(dirname($htmlPath))) {
        mkdir(dirname($htmlPath), 0755, true);
    }
    file_put_contents($htmlPath, $htmlContent);
}

// Priority 4: Last fallback
if (empty($htmlContent)) {
    // Generate basic HTML on the fly
    $htmlContent = render_resume_html($content, $template, $resume['field']);
    
    // If still empty, show error
    if (empty($htmlContent)) {
        $htmlContent = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Resume Not Found</title></head><body><h1>Resume Content Not Available</h1><p>Sorry, the resume content could not be loaded. Please try generating the resume again.</p></body></html>';
    }
}

// Ensure a DOCTYPE is present - AI-generated/legacy-saved resumes sometimes
// start directly with <html> with no DOCTYPE at all, which puts the whole
// page into the browser's legacy Quirks Mode (different, inconsistent box
// model behavior - this can subtly skew layout measurements used by the
// one-page-fit and PDF/print sizing logic below, not just a cosmetic
// warning). Only the empty-fallback branches above already added one.
if (stripos(ltrim($htmlContent), '<!DOCTYPE') !== 0) {
    $htmlContent = "<!DOCTYPE html>\n" . $htmlContent;
}

// Add edit mode, download functionality (PDF and Image) to HTML
// Only add if not already present and not in preview mode
$editAndDownloadScripts = '';
if (strpos($htmlContent, 'editModeEnabled') === false && !$isPreview) {
    $editAndDownloadScripts = '
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        // Edit Mode Variables
        let editModeEnabled = false;
        let originalHTML = null;
        const resumeId = \'' . $resumeId . '\';
        
        // Enter Edit Mode
        function enterEditMode() {
            if (editModeEnabled) return;
            editModeEnabled = true;
            originalHTML = document.body.innerHTML;

            // Edit at full natural size - undo any one-page auto-shrink first
            const fitContainer = getResumeContainer();
            if (fitContainer) resetFit(fitContainer);

            // Enable dragging for images and shapes
            enableDragging();
            
            // Add drag borders to images and shapes
            document.querySelectorAll(\'.draggable-image, .draggable-shape\').forEach(el => {
                el.style.cursor = \'move\';
                if (el.classList.contains(\'draggable-image\')) {
                    el.style.border = \'2px dashed #007bff\';
                } else {
                    el.style.border = \'2px solid #007bff\';
                }
            });
            
            // Make all text editable - BUT exclude control panels and buttons
            document.body.contentEditable = \'false\';
            const editableElements = document.querySelectorAll(\'h1, h2, h3, h4, h5, h6, p, li, td, span, div:not(.no-edit)\');
            editableElements.forEach(el => {
                // Skip if inside control panels
                if (el.closest(\'#colorEditorPanel\') || el.closest(\'#resumeControls\')) {
                    el.contentEditable = \'false\';
                    return;
                }
                // Skip if has class no-edit or is a button/input
                if (el.closest(\'.no-edit\') || el.tagName === \'BUTTON\' || el.tagName === \'INPUT\' || el.tagName === \'LABEL\') {
                    el.contentEditable = \'false\';
                    return;
                }
                // Make editable
                el.contentEditable = \'true\';
                el.style.outline = \'1px dashed #007bff\';
                el.style.minHeight = \'20px\';
            });
            
            // Explicitly set control panels to not editable
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            const controls = document.getElementById(\'resumeControls\');
            if (colorPanel) {
                colorPanel.contentEditable = \'false\';
                colorPanel.style.outline = \'none\';
                // Make all children non-editable
                colorPanel.querySelectorAll(\'*\').forEach(child => {
                    child.contentEditable = \'false\';
                    child.style.outline = \'none\';
                });
            }
            if (controls) {
                controls.contentEditable = \'false\';
                controls.style.outline = \'none\';
                // Make all children non-editable
                controls.querySelectorAll(\'*\').forEach(child => {
                    child.contentEditable = \'false\';
                    child.style.outline = \'none\';
                });
            }
            
            // Create the color and image/shape panels (hidden) - the user
            // opts in to open them via the toolbar toggle buttons, instead
            // of them auto-opening and covering the resume on both sides.
            addColorPickerControls();
            updateColorPickers();
            addImageShapePanel();

            // Show the toggle buttons for both panels now that we are editing
            const btnColors = document.getElementById(\'btnColors\');
            if (btnColors) btnColors.style.display = \'inline-block\';
            const btnImagesShapes = document.getElementById(\'btnImagesShapes\');
            if (btnImagesShapes) {
                btnImagesShapes.style.display = \'inline-block\';
                btnImagesShapes.style.visibility = \'visible\';
            }

            // Update buttons
            const btnEdit = document.getElementById(\'btnEdit\');
            const btnSave = document.getElementById(\'btnSave\');
            const btnCancel = document.getElementById(\'btnCancel\');
            const btnDownloadPDF = document.getElementById(\'btnDownloadPDF\');
            
            if (btnEdit) {
                btnEdit.style.display = \'none\';
                btnEdit.disabled = false;
            }
            if (btnSave) {
                btnSave.style.display = \'inline-block\';
                btnSave.disabled = false;
                btnSave.style.pointerEvents = \'auto\';
                btnSave.style.cursor = \'pointer\';
                btnSave.style.opacity = \'1\';
            }
            if (btnCancel) {
                btnCancel.style.display = \'inline-block\';
                btnCancel.disabled = false;
            }
            if (btnDownloadPDF) {
                btnDownloadPDF.style.display = \'none\';
            }
            
            // Add section management functionality
            addSectionDeleteButtons();
            showAddSectionButton();
        }
        
        // Add delete buttons to each resume section
        function addSectionDeleteButtons() {
            // Remove existing delete buttons first
            document.querySelectorAll(\'.section-delete-btn\').forEach(btn => btn.remove());
            
            // Find all resume sections by looking for h2 elements (section headers)
            const h2Elements = document.querySelectorAll(\'body h2\');
            const processedSections = new Set();
            
            h2Elements.forEach(h2 => {
                // Skip h2 elements that are inside control panels or editor UI
                if (h2.closest(\'#colorEditorPanel\') || h2.closest(\'#resumeControls\') || 
                    h2.closest(\'#imageShapePanel\') || h2.closest(\'#addSectionMenu\')) {
                    return;
                }
                
                // Find the parent section div
                // First, try to find a parent with class resume-section
                let section = h2.closest(\'.resume-section\');
                
                // If not found, check if parent div is a section (contains h2 as first child)
                if (!section) {
                    let parent = h2.parentElement;
                    // Check if parent is a div that looks like a section container
                    if (parent && parent.tagName === \'DIV\') {
                        // If this h2 is the first or second child (after potential wrapper), it\'s likely a section
                        const children = Array.from(parent.children);
                        const h2Index = children.indexOf(h2);
                        if (h2Index <= 1) { // First or second element
                            section = parent;
                        } else {
                            // Check if parent\'s parent is a section-like container
                            const grandParent = parent.parentElement;
                            if (grandParent && grandParent.tagName === \'DIV\') {
                                const grandChildren = Array.from(grandParent.children);
                                const parentIndex = grandChildren.indexOf(parent);
                                if (parentIndex >= 0 && grandParent.classList.contains(\'resume-section\')) {
                                    section = grandParent;
                                } else if (parentIndex <= 1) {
                                    section = grandParent;
                                }
                            }
                        }
                    }
                }
                
                // Skip if we can\'t identify a valid section
                if (!section || section.tagName === \'BODY\' || section.tagName === \'HTML\') {
                    return;
                }
                
                // Skip if already processed (same section element)
                if (processedSections.has(section)) {
                    return;
                }
                
                processedSections.add(section);
                
                // Create delete button
                const deleteBtn = document.createElement(\'button\');
                deleteBtn.className = \'section-delete-btn no-edit\';
                deleteBtn.setAttribute(\'contenteditable\', \'false\');
                deleteBtn.innerHTML = \'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>\';
                deleteBtn.title = \'Remove this section\';
                deleteBtn.style.cssText = \'position: absolute; top: 5px; right: 5px; background: #dc3545; color: white; border: none; border-radius: 50%; width: 30px; height: 30px; cursor: pointer; font-size: 14px; z-index: 1000; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2);\';
                
                // Make section position relative if not already
                const currentPosition = window.getComputedStyle(section).position;
                if (currentPosition === \'static\' || currentPosition === \'\') {
                    section.style.position = \'relative\';
                }
                
                // Add delete button to section
                section.appendChild(deleteBtn);
                
                // Add click handler
                deleteBtn.addEventListener(\'click\', function(e) {
                    e.stopPropagation();
                    e.preventDefault();
                    if (confirm(\'Remove this section? This action cannot be undone.\')) {
                        section.remove();
                        // Refresh delete buttons in case section count changed
                        setTimeout(() => addSectionDeleteButtons(), 100);
                    }
                });
            });
        }
        
        // Show Add Section button
        function showAddSectionButton() {
            // Remove existing button if any
            const existingBtn = document.getElementById(\'btnAddSection\');
            if (existingBtn) {
                existingBtn.style.display = \'inline-block\';
                return;
            }
            
            // Add button to controls
            const controls = document.getElementById(\'resumeControls\');
            if (!controls) return;
            
            const addBtn = document.createElement(\'button\');
            addBtn.id = \'btnAddSection\';
            addBtn.className = \'no-edit\';
            addBtn.setAttribute(\'contenteditable\', \'false\');
            addBtn.innerHTML = \'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>Add Section\';
            addBtn.style.cssText = \'padding: 10px 20px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;\';
            addBtn.onclick = showAddSectionMenu;
            
            // Insert before Download PDF button
            const downloadBtn = document.getElementById(\'btnDownloadPDF\');
            if (downloadBtn) {
                controls.insertBefore(addBtn, downloadBtn);
            } else {
                controls.appendChild(addBtn);
            }
        }
        
        // Show menu to select section type
        function showAddSectionMenu() {
            // Remove existing menu if any
            const existingMenu = document.getElementById(\'addSectionMenu\');
            if (existingMenu) {
                existingMenu.remove();
                return;
            }
            
            const menu = document.createElement(\'div\');
            menu.id = \'addSectionMenu\';
            menu.className = \'no-edit\';
            menu.setAttribute(\'contenteditable\', \'false\');
            menu.style.cssText = \'position: fixed; top: 80px; right: 20px; background: white; border: 2px solid #007bff; border-radius: 8px; padding: 15px; z-index: 10001; box-shadow: 0 4px 12px rgba(0,0,0,0.15); min-width: 200px;\';
            
            const sectionTypes = [
                { name: \'Professional Summary\', content: \'<p>Brief overview of your professional background and key strengths.</p>\' },
                { name: \'Skills\', content: \'<ul><li>Skill 1</li><li>Skill 2</li><li>Skill 3</li></ul>\' },
                { name: \'Work Experience\', content: \'<p><strong>Job Title</strong><br>Company Name | Date Range<br>Description of responsibilities and achievements.</p>\' },
                { name: \'Education\', content: \'<p><strong>Degree</strong><br>Institution Name | Graduation Year</p>\' },
                { name: \'Certifications\', content: \'<ul><li>Certification 1</li><li>Certification 2</li></ul>\' },
                { name: \'Languages\', content: \'<ul><li>Language 1 - Proficiency level</li><li>Language 2 - Proficiency level</li></ul>\' },
                { name: \'Projects\', content: \'<p><strong>Project Name</strong><br>Description of the project and your role.</p>\' },
                { name: \'References\', content: \'<p><strong>Name</strong><br>Title, Company<br>Contact Information</p>\' },
                { name: \'Awards\', content: \'<ul><li>Award 1</li><li>Award 2</li></ul>\' },
                { name: \'Custom Section\', content: \'<p>Add your content here.</p>\' }
            ];
            
            menu.innerHTML = \'<div style="font-weight: 600; margin-bottom: 10px; color: #333;">Select Section Type:</div>\';
            
            sectionTypes.forEach(section => {
                const btn = document.createElement(\'button\');
                btn.textContent = section.name;
                btn.style.cssText = \'display: block; width: 100%; padding: 8px 12px; margin-bottom: 5px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 4px; cursor: pointer; text-align: left; font-size: 14px;\';
                btn.onmouseover = function() { this.style.background = \'#e9ecef\'; };
                btn.onmouseout = function() { this.style.background = \'#f8f9fa\'; };
                btn.onclick = function() {
                    addNewSection(section.name, section.content);
                    menu.remove();
                };
                menu.appendChild(btn);
            });
            
            // Close button
            const closeBtn = document.createElement(\'button\');
            closeBtn.textContent = \'Close\';
            closeBtn.style.cssText = \'display: block; width: 100%; padding: 8px 12px; margin-top: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px;\';
            closeBtn.onclick = function() { menu.remove(); };
            menu.appendChild(closeBtn);
            
            document.body.appendChild(menu);
            
            // Close menu when clicking outside
            setTimeout(() => {
                document.addEventListener(\'click\', function closeMenu(e) {
                    if (!menu.contains(e.target) && e.target.id !== \'btnAddSection\') {
                        menu.remove();
                        document.removeEventListener(\'click\', closeMenu);
                    }
                });
            }, 100);
        }
        
        // Add a new section to the resume
        function addNewSection(sectionName, sectionContent) {
            // Find the resume container - try multiple approaches
            let resumeContainer = null;
            
            // Approach 1: Find by looking for existing sections first (most reliable)
            const existingSections = document.querySelectorAll(\'.resume-section\');
            if (existingSections.length > 0) {
                resumeContainer = existingSections[0].parentElement;
            }
            
            // Approach 2: Try common container class names
            if (!resumeContainer) {
                const containerSelectors = [
                    \'.resume-container\',
                    \'.resume-classic\',
                    \'.resume-modern\',
                    \'.resume-professional\',
                    \'.resume-creative\',
                    \'.resume-clean\',
                    \'.resume-profile\',
                    \'.resume-simple\',
                    \'.resume-two-column\'
                ];
                
                for (let i = 0; i < containerSelectors.length; i++) {
                    resumeContainer = document.querySelector(containerSelectors[i]);
                    if (resumeContainer) break;
                }
            }
            
            // Approach 3: Find container by looking for h2 elements
            if (!resumeContainer) {
                const allH2 = document.querySelectorAll(\'body h2\');
                for (let i = 0; i < allH2.length; i++) {
                    const h2 = allH2[i];
                    if (h2.closest(\'#colorEditorPanel\') || h2.closest(\'#resumeControls\') || 
                        h2.closest(\'#imageShapePanel\') || h2.closest(\'#addSectionMenu\')) {
                        continue;
                    }
                    // Found a resume h2, get its parent container
                    let parent = h2.parentElement;
                    // Walk up to find the resume container div
                    while (parent && parent !== document.body && parent.tagName !== \'HTML\') {
                        // Check if this parent contains multiple h2s or looks like a container
                        if (parent.querySelectorAll(\'h2\').length > 1 || 
                            parent.classList.contains(\'resume\') ||
                            parent.querySelector(\'h1\')) {
                            resumeContainer = parent;
                            break;
                        }
                        parent = parent.parentElement;
                    }
                    if (resumeContainer) break;
                    // If no container found, use the direct parent
                    if (!resumeContainer && h2.parentElement && h2.parentElement !== document.body) {
                        resumeContainer = h2.parentElement;
                        break;
                    }
                }
            }
            
            // Approach 4: Look for h1 (name) and find its container
            if (!resumeContainer) {
                const h1 = document.querySelector(\'body h1\');
                if (h1) {
                    let parent = h1.parentElement;
                    // Find the div that contains the resume
                    while (parent && parent !== document.body && parent.tagName !== \'HTML\') {
                        // Skip control panels
                        if (parent.id === \'resumeControls\' || parent.id === \'colorEditorPanel\') {
                            parent = parent.parentElement;
                            continue;
                        }
                        // If this div looks like a container, use it
                        if (parent.tagName === \'DIV\') {
                            resumeContainer = parent;
                            break;
                        }
                        parent = parent.parentElement;
                    }
                }
            }
            
            // Final fallback: Use body
            if (!resumeContainer) {
                resumeContainer = document.body;
            }
            
            // Ensure we are not adding to a control panel
            if (resumeContainer.id === \'resumeControls\' || 
                resumeContainer.id === \'colorEditorPanel\' || 
                resumeContainer.id === \'imageShapePanel\' ||
                resumeContainer.closest(\'#resumeControls\') ||
                resumeContainer.closest(\'#colorEditorPanel\')) {
                // If we ended up with a control panel, try to find first div child of body instead
                const firstBodyDiv = document.body.querySelector(\'> div:not(#resumeControls):not(#colorEditorPanel):not(#imageShapePanel)\');
                if (firstBodyDiv) {
                    resumeContainer = firstBodyDiv;
                } else {
                    resumeContainer = document.body;
                }
            }
            
            // Create new section div
            const newSection = document.createElement(\'div\');
            newSection.className = \'resume-section\';
            newSection.style.position = \'relative\';
            
            // Match existing section styling by checking first existing section
            const firstExistingSection = resumeContainer.querySelector(\'.resume-section\');
            if (firstExistingSection) {
                const existingMarginBottom = window.getComputedStyle(firstExistingSection).marginBottom;
                if (existingMarginBottom && existingMarginBottom !== \'0px\') {
                    newSection.style.marginBottom = existingMarginBottom;
                } else {
                    newSection.style.marginBottom = \'25px\';
                }
            } else {
                newSection.style.marginBottom = \'25px\';
            }
            
            // Create h2 header
            const h2 = document.createElement(\'h2\');
            h2.textContent = sectionName;
            h2.contentEditable = \'true\';
            h2.style.outline = \'1px dashed #007bff\';
            h2.style.minHeight = \'20px\';
            
            // Match existing h2 styling
            const firstH2 = resumeContainer.querySelector(\'h2\');
            if (firstH2 && firstH2 !== h2) {
                const computedStyle = window.getComputedStyle(firstH2);
                // Copy relevant styles (but not outline which is for editing)
                if (computedStyle.color) h2.style.color = computedStyle.color;
                if (computedStyle.fontSize) h2.style.fontSize = computedStyle.fontSize;
                if (computedStyle.fontWeight) h2.style.fontWeight = computedStyle.fontWeight;
                if (computedStyle.marginTop) h2.style.marginTop = computedStyle.marginTop;
                if (computedStyle.marginBottom) h2.style.marginBottom = computedStyle.marginBottom;
                if (computedStyle.paddingBottom) h2.style.paddingBottom = computedStyle.paddingBottom;
                if (computedStyle.borderBottom) h2.style.borderBottom = computedStyle.borderBottom;
            }
            
            // Create content container
            const contentContainer = document.createElement(\'div\');
            contentContainer.innerHTML = sectionContent;
            
            // Make content editable
            contentContainer.contentEditable = \'true\';
            contentContainer.style.outline = \'1px dashed #007bff\';
            contentContainer.style.minHeight = \'20px\';
            
            // Make all children editable
            contentContainer.querySelectorAll(\'p, ul, li, strong, div, span\').forEach(el => {
                // Skip elements that shouldn\'t be editable
                if (el.classList.contains(\'no-edit\')) return;
                el.contentEditable = \'true\';
                el.style.outline = \'1px dashed #007bff\';
                el.style.minHeight = \'20px\';
            });
            
            // Append to section
            newSection.appendChild(h2);
            newSection.appendChild(contentContainer);
            
            // Insert at the end of resume container
            resumeContainer.appendChild(newSection);
            
            // Scroll to new section
            newSection.scrollIntoView({ behavior: \'smooth\', block: \'nearest\' });
            
            // Add delete button to new section
            setTimeout(() => {
                addSectionDeleteButtons();
            }, 100);
        }
        
        // Exit Edit Mode
        function exitEditMode() {
            if (!editModeEnabled) return;
            editModeEnabled = false;
            
            // Deselect any selected element
            deselectElement();
            
            // Disable dragging for images and shapes
            disableDragging();
            
            // Remove drag borders from images and shapes
            document.querySelectorAll(\'.draggable-image, .draggable-shape\').forEach(el => {
                el.style.cursor = \'default\';
                el.style.border = \'none\';
            });
            
            // Make all text non-editable
            document.body.contentEditable = \'false\';
            const editableElements = document.querySelectorAll(\'[contenteditable="true"]\');
            editableElements.forEach(el => {
                // Skip control panels
                if (el.closest(\'#colorEditorPanel\') || el.closest(\'#resumeControls\')) {
                    return;
                }
                el.contentEditable = \'false\';
                el.style.outline = \'none\';
                el.style.minHeight = \'auto\';
            });
            
            // Update buttons
            const btnEdit = document.getElementById(\'btnEdit\');
            const btnSave = document.getElementById(\'btnSave\');
            const btnCancel = document.getElementById(\'btnCancel\');
            const btnDownloadPDF = document.getElementById(\'btnDownloadPDF\');
            
            if (btnEdit) {
                btnEdit.style.display = \'inline-block\';
                btnEdit.disabled = false;
                btnEdit.textContent = \'Edit\';
            }
            const btnImagesShapes = document.getElementById(\'btnImagesShapes\');
            if (btnImagesShapes) btnImagesShapes.style.display = \'none\';
            const btnColors = document.getElementById(\'btnColors\');
            if (btnColors) btnColors.style.display = \'none\';
            if (btnSave) btnSave.style.display = \'none\';
            if (btnCancel) btnCancel.style.display = \'none\';
            if (btnDownloadPDF) btnDownloadPDF.style.display = \'inline-block\';

            // Hide color editor panel and image/shape panel, and undo any
            // layout push that was made room for them
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            if (colorPanel) colorPanel.style.display = \'none\';
            const imagePanel = document.getElementById(\'imageShapePanel\');
            if (imagePanel) imagePanel.style.display = \'none\';
            updateResumeOffsetForPanels();

            // Remove section delete buttons
            document.querySelectorAll(\'.section-delete-btn\').forEach(btn => btn.remove());
            
            // Hide Add Section button
            const addSectionBtn = document.getElementById(\'btnAddSection\');
            if (addSectionBtn) addSectionBtn.style.display = \'none\';
            
            // Remove add section menu if open
            const sectionMenu = document.getElementById(\'addSectionMenu\');
            if (sectionMenu) sectionMenu.remove();

            // Re-compact back to one page now that editing has stopped
            autoFitToOnePage();
        }
        
        // Initialize - ensure edit mode is OFF by default
        function initializePage() {
            // Make sure edit mode is disabled
            editModeEnabled = false;
            
            // Initialize image/shape panel and overflow monitoring
            addImageShapePanel();
            startOverflowMonitoring();
            
            // Ensure all content is NOT editable by default
            document.body.contentEditable = \'false\';
            const allElements = document.querySelectorAll(\'*\');
            allElements.forEach(el => {
                // Only allow control panels to be explicitly non-editable
                if (!el.closest(\'#colorEditorPanel\') && !el.closest(\'#resumeControls\') && !el.closest(\'#imageShapePanel\')) {
                    // Remove any contentEditable that might have been set
                    if (el.getAttribute(\'contenteditable\') === \'true\') {
                        el.contentEditable = \'false\';
                    }
                }
            });
            
            // Hide color panel and image/shape panel
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            if (colorPanel) colorPanel.style.display = \'none\';
            const imagePanel = document.getElementById(\'imageShapePanel\');
            if (imagePanel) imagePanel.style.display = \'none\';
            
            // Ensure dragging is disabled on page load (not in edit mode)
            disableDragging();
            deselectElement(); // Deselect any selected element
            document.querySelectorAll(\'.draggable-image, .draggable-shape\').forEach(el => {
                el.style.cursor = \'default\';
                el.style.border = \'none\';
            });
            
            // Add click handlers to existing images/shapes for selection
            document.querySelectorAll(\'.draggable-image, .draggable-shape\').forEach(el => {
                el.addEventListener(\'click\', function(e) {
                    e.stopPropagation();
                    if (editModeEnabled) {
                        selectElement(el);
                    }
                });
            });
            
            // Add click handler to deselect when clicking outside
            document.addEventListener(\'click\', function(e) {
                if (!editModeEnabled) return;
                if (!e.target.closest(\'.draggable-image\') && !e.target.closest(\'.draggable-shape\') && 
                    !e.target.closest(\'#selectionBox\') && !e.target.closest(\'#shapeStylingPanel\')) {
                    deselectElement();
                }
            });
            
            // Update selection box on scroll/resize
            window.addEventListener(\'scroll\', function() {
                if (selectedElement) {
                    updateSelectionBox(selectedElement);
                }
            });
            window.addEventListener(\'resize\', function() {
                if (selectedElement) {
                    updateSelectionBox(selectedElement);
                }
            });
            
            // Ensure buttons are in correct state
            const btnEdit = document.getElementById(\'btnEdit\');
            const btnSave = document.getElementById(\'btnSave\');
            const btnCancel = document.getElementById(\'btnCancel\');
            const btnDownloadPDF = document.getElementById(\'btnDownloadPDF\');
            
            if (btnEdit) {
                btnEdit.style.display = \'inline-block\';
                btnEdit.disabled = false;
                btnEdit.textContent = \'Edit\';
            }
            const btnImagesShapes = document.getElementById(\'btnImagesShapes\');
            if (btnImagesShapes) btnImagesShapes.style.display = \'none\';
            if (btnSave) btnSave.style.display = \'none\';
            if (btnCancel) btnCancel.style.display = \'none\';
            if (btnDownloadPDF) btnDownloadPDF.style.display = \'inline-block\';
        }
        
        // Run initialization when page loads
        if (document.readyState === \'loading\') {
            document.addEventListener(\'DOMContentLoaded\', initializePage);
        } else {
            initializePage();
        }
        
        // Cancel Edit (restore original)
        function cancelEdit() {
            if (confirm(\'Discard all changes? Unsaved changes will be lost.\')) {
                window.location.href = \'' . $myResumesUrl . '\';
            }
        }
        
        // Save changes
        async function saveResume() {
            if (!editModeEnabled) {
                console.log(\'Edit mode not enabled, ignoring save\');
                return;
            }
            
            const saveBtn = document.getElementById(\'btnSave\');
            if (!saveBtn) {
                console.error(\'Save button not found\');
                return;
            }
            
            saveBtn.disabled = true;
            saveBtn.textContent = \'Saving...\';
            
            // Deselect any active elements first to remove selection boxes
            deselectElement();
            
            try {
                // Clone the entire document to manipulate it without affecting the current view
                const docClone = document.documentElement.cloneNode(true);
                
                // Remove editor-specific UI elements from the clone
                // These are elements that should NEVER be in the saved file
                const elementsToRemove = [
                    \'#resumeControls\', 
                    \'#colorEditorPanel\', 
                    \'#imageShapePanel\', 
                    \'#selectionBox\', 
                    \'#deleteSelectedElement\', 
                    \'#shapeStylingPanel\', 
                    \'#imageStylingPanel\', 
                    \'#cropModal\',
                    \'#pageOverflowWarning\',
                    \'.resize-handle\',
                    \'.section-delete-btn\',
                    \'#addSectionMenu\',
                    \'#btnAddSection\'
                ];
                
                elementsToRemove.forEach(selector => {
                    const els = docClone.querySelectorAll(selector);
                    els.forEach(el => el.remove());
                });
                
                // Remove all scripts from the clone
                // The editor re-injects necessary scripts on load, so saving them causes duplication
                const scripts = docClone.querySelectorAll(\'script\');
                scripts.forEach(script => script.remove());

                // Clean up contenteditable attributes
                const editables = docClone.querySelectorAll(\'[contenteditable]\');
                editables.forEach(el => {
                    el.removeAttribute(\'contenteditable\');
                    el.classList.remove(\'editing\');
                    el.style.outline = \'\';
                    // Remove empty style attributes if any
                    if (el.getAttribute(\'style\') === \'\') {
                        el.removeAttribute(\'style\');
                    }
                });
                
                // Remove position: relative from sections if it was only added for delete buttons
                docClone.querySelectorAll(\'.resume-section\').forEach(section => {
                    const style = section.getAttribute(\'style\');
                    if (style && style.includes(\'position: relative\')) {
                        // Check if position relative was only added for delete button
                        // If no other styles, we can remove the style attribute entirely
                        const cleanedStyle = style.replace(/position\s*:\s*relative\s*;?/gi, \'\').trim();
                        if (cleanedStyle) {
                            section.setAttribute(\'style\', cleanedStyle);
                        } else {
                            section.removeAttribute(\'style\');
                        }
                    }
                });
                
                // Remove \'no-edit\' classes as they are for the editor
                const noEdits = docClone.querySelectorAll(\'.no-edit\');
                noEdits.forEach(el => el.remove()); 
                
                // Reset cursor and borders for draggable items in the clone
                docClone.querySelectorAll(\'.draggable-image, .draggable-shape\').forEach(el => {
                    el.style.cursor = \'default\';
                    // Remove data attributes used for state
                    el.removeAttribute(\'data-selected\');
                });
                
                // Get the clean HTML
                const updatedHTML = docClone.outerHTML;
                
                // Save to file first
                const response = await fetch(\'/resume_generator/api/resumes.php?id=\' + resumeId, {
                    method: \'PUT\',
                    headers: {
                        \'Content-Type\': \'application/json\'
                    },
                    body: JSON.stringify({
                        ai_result_resume: updatedHTML
                    })
                });
                
                const result = await response.json();
                
                if (result.ok || response.ok) {
                    // Also save to file
                    await fetch(\'/resume_generator/api/save_resume_file.php?id=\' + resumeId, {
                        method: \'POST\',
                        headers: {
                            \'Content-Type\': \'application/json\'
                        },
                        body: JSON.stringify({
                            html: updatedHTML
                        })
                    });
                    
                    // Exit edit mode AFTER saving - this will reset everything in the LIVE view
                    exitEditMode();

                    // Back to My Resumes
                    window.location.href = \'' . $myResumesUrl . '\';
                } else {
                    alert(\'Save failed: \' + (result.message || \'Unknown error\'));

                    // Reset button even on error
                    saveBtn.disabled = false;
                    saveBtn.textContent = \'Save\';
                }
            } catch (error) {
                console.error(\'Save error:\', error);
                alert(\'Failed to save resume. Please try again.\');

                // Reset button on error
                saveBtn.disabled = false;
                saveBtn.textContent = \'Save\';
            }
        }
        
        // Add Color Picker Controls
        function addColorPickerControls() {
            // Remove existing panel if any and recreate
            const existing = document.getElementById(\'colorEditorPanel\');
            if (existing) {
                existing.remove();
            }
            
            const panel = document.createElement(\'div\');
            panel.id = \'colorEditorPanel\';
            panel.contentEditable = \'false\'; // Prevent editing - CRITICAL
            panel.setAttribute(\'contenteditable\', \'false\'); // Force non-editable
            panel.className = \'no-edit\'; // Mark as non-editable
            // Panel will FLOAT as OVERLAY on LEFT side - position:fixed ensures it stays in place above everything
            panel.style.cssText = \'position: fixed !important; top: 80px !important; left: 20px !important; background: white !important; padding: 20px !important; border-radius: 8px !important; box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important; z-index: 999999 !important; min-width: 260px !important; max-width: 300px !important; display: none !important; overflow-y: auto !important; max-height: calc(100vh - 100px) !important; pointer-events: auto !important; border: 2px solid #007bff !important;\';
            panel.innerHTML = `
                <div style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); padding: 15px; margin: -20px -20px 20px -20px; border-radius: 6px 6px 0 0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                    <h3 style="margin: 0; font-size: 18px; color: white; font-weight: 600;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px;"><circle cx="13.5" cy="6.5" r=".5"></circle><circle cx="17.5" cy="10.5" r=".5"></circle><circle cx="8.5" cy="7.5" r=".5"></circle><circle cx="6.5" cy="12.5" r=".5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996C20.516 16.394 22 14.78 22 12.39 22 6.69 17.5 2 12 2z"></path></svg>Edit Colors</h3>
                    <button onclick="toggleColorPanel()" title="Close" style="background: rgba(255,255,255,0.15); border: none; color: white; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Name/Header:</label>
                    <input type="color" id="colorName" value="#2c3e50" style="width: 100%; height: 45px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; display: block;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Section Headers:</label>
                    <input type="color" id="colorHeaders" value="#34495e" style="width: 100%; height: 45px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; display: block;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Text:</label>
                    <input type="color" id="colorText" value="#2c3e50" style="width: 100%; height: 45px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; display: block;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Accent Color:</label>
                    <input type="color" id="colorAccent" value="#3498db" style="width: 100%; height: 45px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; display: block;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Background:</label>
                    <input type="color" id="colorBg" value="#ffffff" style="width: 100%; height: 45px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer; display: block;">
                </div>
                <button onclick="applyColors()" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600; margin-top: 10px; box-shadow: 0 2px 8px rgba(40,167,69,0.3); transition: all 0.3s;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Apply Colors</button>
                <p style="margin: 15px 0 0 0; font-size: 11px; color: #999; text-align: center; font-style: italic;">Tip: click an element to edit its text</p>
            `;
            document.body.appendChild(panel);
            
            // CRITICAL: Ensure panel and ALL children are non-editable
            panel.setAttribute(\'contenteditable\', \'false\');
            panel.contentEditable = \'false\';
            panel.querySelectorAll(\'*\').forEach(child => {
                child.setAttribute(\'contenteditable\', \'false\');
                child.contentEditable = \'false\';
                // Prevent text selection on labels and text
                if (child.tagName === \'LABEL\' || child.tagName === \'P\' || child.tagName === \'H3\') {
                    child.style.userSelect = \'none\';
                    child.style.webkitUserSelect = \'none\';
                    child.style.mozUserSelect = \'none\';
                }
            });
            
            // Initialize color values from current styles
            updateColorPickers();
        }
        
        // Update color pickers from current styles
        function updateColorPickers() {
            setTimeout(() => {
                const colorNameEl = document.getElementById(\'colorName\');
                const colorHeadersEl = document.getElementById(\'colorHeaders\');
                const colorTextEl = document.getElementById(\'colorText\');
                const colorAccentEl = document.getElementById(\'colorAccent\');
                const colorBgEl = document.getElementById(\'colorBg\');
                
                if (!colorNameEl) return;
                
                const h1 = document.querySelector(\'h1\');
                if (h1 && colorNameEl) {
                    const color = window.getComputedStyle(h1).color;
                    colorNameEl.value = rgbToHex(color);
                }
                const h2 = document.querySelector(\'h2\');
                if (h2 && colorHeadersEl) {
                    const color = window.getComputedStyle(h2).color;
                    colorHeadersEl.value = rgbToHex(color);
                }
                const p = document.querySelector(\'p\');
                if (p && colorTextEl) {
                    const color = window.getComputedStyle(p).color;
                    colorTextEl.value = rgbToHex(color);
                }
                // Set accent color (look for blue or primary color)
                if (colorAccentEl) {
                    const accent = document.querySelector(\'[style*="#3498db"], [style*="#007bff"], a, .accent\');
                    if (accent) {
                        const color = window.getComputedStyle(accent).color || window.getComputedStyle(accent).borderColor;
                        colorAccentEl.value = rgbToHex(color) || \'#3498db\';
                    } else {
                        colorAccentEl.value = \'#3498db\';
                    }
                }
                // Set background
                if (colorBgEl) {
                    const bg = document.querySelector(\'.resume-container, .resume-classic, .resume-modern, body\');
                    if (bg) {
                        const color = window.getComputedStyle(bg).backgroundColor;
                        colorBgEl.value = rgbToHex(color) || \'#ffffff\';
                    } else {
                        colorBgEl.value = \'#ffffff\';
                    }
                }
            }, 300);
        }
        
        // Apply colors
        function applyColors() {
            const nameColor = document.getElementById(\'colorName\').value;
            const headerColor = document.getElementById(\'colorHeaders\').value;
            const textColor = document.getElementById(\'colorText\').value;
            const accentColor = document.getElementById(\'colorAccent\').value;
            const bgColor = document.getElementById(\'colorBg\').value;
            
            // Apply to elements
            document.querySelectorAll(\'h1\').forEach(el => el.style.color = nameColor);
            document.querySelectorAll(\'h2, h3, h4, h5, h6\').forEach(el => el.style.color = headerColor);
            document.querySelectorAll(\'p, li, td, span, div\').forEach(el => {
                if (!el.matches(\'h1, h2, h3, h4, h5, h6\')) {
                    el.style.color = textColor;
                }
            });
            document.querySelectorAll(\'a, .accent, [style*="color: #3498db"]\').forEach(el => {
                el.style.color = accentColor;
            });
            document.body.style.backgroundColor = bgColor;
            document.querySelectorAll(\'.resume-container, .resume-classic, .resume-modern, .resume-professional, .resume-creative, .resume-clean, .resume-profile, .resume-simple, .resume-two-column\').forEach(el => {
                el.style.backgroundColor = bgColor;
            });
            
            // Update borders with accent color
            document.querySelectorAll(\'[style*="border"]\').forEach(el => {
                const border = window.getComputedStyle(el).border;
                if (border && border.includes(\'solid\')) {
                    el.style.borderColor = accentColor;
                }
            });
        }
        
        // RGB to Hex converter
        function rgbToHex(rgb) {
            if (rgb.startsWith(\'#\')) return rgb;
            const match = rgb.match(/\\d+/g);
            if (!match || match.length < 3) return \'#000000\';
            return \'#\' + match.map(x => {
                const hex = parseInt(x).toString(16);
                return hex.length === 1 ? \'0\' + hex : hex;
            }).join(\'\');
        }
        
        // ========== IMAGE & SHAPE INSERTION FUNCTIONS ==========
        
        // Selection state management
        let selectedElement = null;
        
        // Select element (show white bounding box with resize handles)
        function selectElement(element) {
            // Deselect previous element
            deselectElement();
            
            if (!editModeEnabled) return;
            
            selectedElement = element;
            element.setAttribute(\'data-selected\', \'true\');
            
            // Create selection box
            createSelectionBox(element);
            
            // Show delete button
            showDeleteButton(element);
            
            // If it\'s a shape, show styling panel
            if (element.classList.contains(\'draggable-shape\')) {
                showShapeStylingPanel(element);
            } else if (element.tagName === \'IMG\' && element.classList.contains(\'draggable-image\')) {
                showImageStylingPanel(element);
            }
        }
        
        // Deselect element
        function deselectElement() {
            if (selectedElement) {
                selectedElement.removeAttribute(\'data-selected\');
                removeSelectionBox();
                hideDeleteButton();
                hideShapeStylingPanel();
                hideImageStylingPanel();
            }
            selectedElement = null;
        }
        
        // Create selection box with resize handles
        function createSelectionBox(element) {
            removeSelectionBox(); // Remove existing box if any
            
            const box = document.createElement(\'div\');
            box.id = \'selectionBox\';
            box.className = \'no-edit\';
            box.style.cssText = \'position: absolute; border: 2px dashed #007bff; pointer-events: none; z-index: 10001; box-shadow: 0 0 10px rgba(0,123,255,0.2); transition: none;\';
            
            document.body.appendChild(box);
            updateSelectionBox(element);
            
            // Create resize handles
            const handles = [\'nw\', \'ne\', \'sw\', \'se\', \'n\', \'s\', \'e\', \'w\'];
            handles.forEach(handle => {
                const handleEl = document.createElement(\'div\');
                handleEl.className = \'resize-handle resize-handle-\' + handle;
                
                // Enhanced visual style for handles
                handleEl.style.cssText = \'position: absolute; width: 12px; height: 12px; background: #fff; border: 2px solid #007bff; border-radius: 50%; cursor: \' + handle + \'-resize; pointer-events: auto; z-index: 10002; box-shadow: 0 2px 4px rgba(0,0,0,0.1); transform: translate(-50%, -50%);\';
                
                // Position handles
                if (handle.includes(\'n\')) handleEl.style.top = \'0%\';
                if (handle.includes(\'s\')) handleEl.style.top = \'100%\';
                if (handle.includes(\'w\')) handleEl.style.left = \'0%\';
                if (handle.includes(\'e\')) handleEl.style.left = \'100%\';
                
                // Center middles
                if (handle === \'n\' || handle === \'s\') handleEl.style.left = \'50%\';
                if (handle === \'e\' || handle === \'w\') handleEl.style.top = \'50%\';
                
                box.appendChild(handleEl);
                
                // Make handle draggable for resizing
                makeHandleResizable(handleEl, element, handle);
            });
        }
        
        // Update selection box position and size
        function updateSelectionBox(element) {
            const box = document.getElementById(\'selectionBox\');
            if (!box || !element) return;
            
            const rect = element.getBoundingClientRect();
            const container = element.parentElement.getBoundingClientRect();
            
            box.style.left = (rect.left - container.left) + \'px\';
            box.style.top = (rect.top - container.top) + \'px\';
            box.style.width = rect.width + \'px\';
            box.style.height = rect.height + \'px\';
        }
        
        // Remove selection box
        function removeSelectionBox() {
            const box = document.getElementById(\'selectionBox\');
            if (box) box.remove();
        }
        
        // Make resize handle functional
        function makeHandleResizable(handle, element, direction) {
            let isResizing = false;
            let startX, startY, startWidth, startHeight, startLeft, startTop;
            
            handle.addEventListener(\'mousedown\', function(e) {
                e.stopPropagation();
                isResizing = true;
                
                const rect = element.getBoundingClientRect();
                const container = element.parentElement.getBoundingClientRect();
                
                startX = e.clientX;
                startY = e.clientY;
                startWidth = rect.width;
                startHeight = rect.height;
                startLeft = rect.left - container.left;
                startTop = rect.top - container.top;
                
                document.addEventListener(\'mousemove\', resizeHandler);
                document.addEventListener(\'mouseup\', stopResize);
                
                e.preventDefault();
            });
            
            function resizeHandler(e) {
                if (!isResizing) return;
                
                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;
                
                let newWidth = startWidth;
                let newHeight = startHeight;
                let newLeft = startLeft;
                let newTop = startTop;
                
                // Handle different resize directions
                if (direction.includes(\'e\')) newWidth = startWidth + deltaX;
                if (direction.includes(\'w\')) {
                    newWidth = startWidth - deltaX;
                    newLeft = startLeft + deltaX;
                }
                if (direction.includes(\'s\')) newHeight = startHeight + deltaY;
                if (direction.includes(\'n\')) {
                    newHeight = startHeight - deltaY;
                    newTop = startTop + deltaY;
                }
                
                // Apply constraints (min size)
                newWidth = Math.max(20, newWidth);
                newHeight = Math.max(20, newHeight);
                
                element.style.width = newWidth + \'px\';
                if (!element.classList.contains(\'draggable-shape\') || element.getAttribute(\'data-shape\') !== \'triangle\') {
                    element.style.height = newHeight + \'px\';
                }
                element.style.left = newLeft + \'px\';
                element.style.top = newTop + \'px\';
                
                updateSelectionBox(element);
                checkPageOverflow();
            }
            
            function stopResize() {
                isResizing = false;
                document.removeEventListener(\'mousemove\', resizeHandler);
                document.removeEventListener(\'mouseup\', stopResize);
            }
        }
        
        // Show delete button
        function showDeleteButton(element) {
            hideDeleteButton(); // Remove existing button
            
            const button = document.createElement(\'button\');
            button.id = \'deleteSelectedElement\';
            button.className = \'no-edit\';
            button.innerHTML = \'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>\';
            button.title = \'Delete\';
            button.style.cssText = \'position: absolute; top: -30px; right: -5px; width: 28px; height: 28px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; z-index: 10003; box-shadow: 0 2px 4px rgba(0,0,0,0.2);\';
            button.onclick = function(e) {
                e.stopPropagation();
                if (confirm(\'Delete this element?\')) {
                    if (selectedElement) {
                        selectedElement.remove();
                        deselectElement();
                        checkPageOverflow();
                    }
                }
            };
            
            const box = document.getElementById(\'selectionBox\');
            if (box) {
                box.appendChild(button);
            }
        }
        
        // Hide delete button
        function hideDeleteButton() {
            const button = document.getElementById(\'deleteSelectedElement\');
            if (button) button.remove();
        }
        
        // Show shape styling panel
        function showShapeStylingPanel(element) {
            hideShapeStylingPanel();
            
            const panel = document.createElement(\'div\');
            panel.id = \'shapeStylingPanel\';
            panel.className = \'no-edit\';
            panel.style.cssText = \'position: fixed; top: 250px; right: 20px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 999997; min-width: 280px; border: 2px solid #6f42c1;\';
            
            // Get current styles
            const computedStyle = window.getComputedStyle(element);
            const bgColor = computedStyle.backgroundColor;
            const opacity = computedStyle.opacity || \'1\';
            const borderRadius = parseInt(computedStyle.borderRadius) || 0;
            const shapeType = element.getAttribute(\'data-shape\');
            
            // Determine if border radius is relevant
            const showRadius = [\'rectangle\', \'square\', \'rounded-rect\'].includes(shapeType);
            
            let radiusHtml = \'\';
            if (showRadius) {
                radiusHtml = `
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Roundness: <span id="radiusValue">${borderRadius}px</span></label>
                    <input type="range" id="shapeRadiusSlider" min="0" max="100" value="${borderRadius}" style="width: 100%;">
                </div>`;
            }

            panel.innerHTML = `
                <div style="background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%); padding: 15px; margin: -20px -20px 20px -20px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px; color: white; font-weight: 600;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px;"><circle cx="13.5" cy="6.5" r=".5"></circle><circle cx="17.5" cy="10.5" r=".5"></circle><circle cx="8.5" cy="7.5" r=".5"></circle><circle cx="6.5" cy="12.5" r=".5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996C20.516 16.394 22 14.78 22 12.39 22 6.69 17.5 2 12 2z"></path></svg>Shape Styling</h3>
                    <button onclick="hideShapeStylingPanel()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Fill Color:</label>
                    <div style="display: flex; gap: 10px;">
                        <input type="color" id="shapeColorPicker" value="#6c757d" style="flex: 1; height: 40px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer;">
                    </div>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Opacity: <span id="opacityValue">100%</span></label>
                    <input type="range" id="shapeOpacitySlider" min="0" max="100" value="100" style="width: 100%;">
                </div>
                
                ${radiusHtml}
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Stacking:</label>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="changeZIndex(\\\'up\\\')" style="flex: 1; padding: 8px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;">Bring Forward</button>
                        <button onclick="changeZIndex(\\\'down\\\')" style="flex: 1; padding: 8px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;">Send Backward</button>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button onclick="applyShapeStyle()" style="flex: 2; padding: 10px; background: #6f42c1; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">Apply Changes</button>
                    <button onclick="deleteSelectedShape()" style="flex: 1; padding: 10px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">Delete</button>
                </div>
            `;
            
            document.body.appendChild(panel);
            
            // Initialize color picker
            const colorInput = document.getElementById(\'shapeColorPicker\');
            if (bgColor && bgColor !== \'rgba(0, 0, 0, 0)\' && bgColor !== \'transparent\') {
                if (bgColor.startsWith(\'#\')) {
                    colorInput.value = bgColor;
                } else {
                    const rgb = bgColor.match(/\\d+/g);
                    if (rgb && rgb.length >= 3) {
                        const hex = \'#\' + rgb.slice(0, 3).map(x => {
                            const hex = parseInt(x).toString(16);
                            return hex.length === 1 ? \'0\' + hex : hex;
                        }).join(\'\');
                        colorInput.value = hex;
                    }
                }
            }
            
            // Initialize opacity slider
            const opacityInput = document.getElementById(\'shapeOpacitySlider\');
            opacityInput.value = Math.round(parseFloat(opacity) * 100);
            document.getElementById(\'opacityValue\').textContent = opacityInput.value + \'%\';
            opacityInput.addEventListener(\'input\', function() {
                document.getElementById(\'opacityValue\').textContent = this.value + \'%\';
            });
            
            // Initialize radius slider
            if (showRadius) {
                const radiusInput = document.getElementById(\'shapeRadiusSlider\');
                radiusInput.addEventListener(\'input\', function() {
                    document.getElementById(\'radiusValue\').textContent = this.value + \'px\';
                    if (selectedElement) {
                        selectedElement.style.borderRadius = this.value + \'px\';
                    }
                });
            }
            
            // Live update for color/opacity
             colorInput.addEventListener(\'input\', applyShapeStyle);
             opacityInput.addEventListener(\'input\', applyShapeStyle);
        }
        
        // Hide shape styling panel
        function hideShapeStylingPanel() {
            const panel = document.getElementById(\'shapeStylingPanel\');
            if (panel) panel.remove();
        }
        
        // Change Z-Index
        function changeZIndex(direction) {
            if (!selectedElement) return;
            let currentZ = parseInt(window.getComputedStyle(selectedElement).zIndex) || 1000;
            if (direction === \'up\') currentZ += 1;
            else currentZ -= 1;
            selectedElement.style.zIndex = currentZ;
        }
        
        // Delete selected shape
        function deleteSelectedShape() {
             if (selectedElement) {
                 if(confirm(\'Delete this shape?\')) {
                     selectedElement.remove();
                     deselectElement();
                 }
             }
        }
        
        // Apply shape style
        function applyShapeStyle() {
            if (!selectedElement || !selectedElement.classList.contains(\'draggable-shape\')) return;
            
            const color = document.getElementById(\'shapeColorPicker\').value;
            const opacity = document.getElementById(\'shapeOpacitySlider\').value / 100;
            
            const shapeType = selectedElement.getAttribute(\'data-shape\');
            
            // Convert hex to rgba
            const r = parseInt(color.slice(1, 3), 16);
            const g = parseInt(color.slice(3, 5), 16);
            const b = parseInt(color.slice(5, 7), 16);
            const rgba = \'rgba(\' + r + \', \' + g + \', \' + b + \', \' + opacity + \')\';
            
            // Apply based on shape type
            if (shapeType === \'triangle\') {
                selectedElement.style.borderBottomColor = rgba;
            } else if (shapeType === \'star\' || shapeType === \'arrow\') {
                selectedElement.style.color = color;
                selectedElement.style.opacity = opacity;
            } else if (shapeType === \'line\') {
                selectedElement.style.background = color;
                selectedElement.style.borderTopColor = color;
                selectedElement.style.opacity = opacity;
            } else {
                selectedElement.style.backgroundColor = rgba;
            }
        }
        
        // Show image styling panel
        function showImageStylingPanel(element) {
            hideImageStylingPanel();
            
            const panel = document.createElement(\'div\');
            panel.id = \'imageStylingPanel\';
            panel.className = \'no-edit\';
            panel.style.cssText = \'position: fixed; top: 250px; right: 20px; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); z-index: 999997; min-width: 280px; border: 2px solid #007bff;\';
            
            panel.innerHTML = `
                <div style="background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); padding: 15px; margin: -20px -20px 20px -20px; border-radius: 6px 6px 0 0; display: flex; justify-content: space-between; align-items: center;">
                    <h3 style="margin: 0; font-size: 18px; color: white; font-weight: 600;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px;"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>Image Styling</h3>
                    <button onclick="hideImageStylingPanel()" style="background: none; border: none; color: white; font-size: 20px; cursor: pointer;">&times;</button>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <button onclick="openCropModal()" style="width: 100%; padding: 10px; background: #17a2b8; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; margin-bottom: 10px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M6.13 1L6 16a2 2 0 0 0 2 2h15"></path><path d="M1 6.13L16 6a2 2 0 0 1 2 2v15"></path></svg>Crop Image</button>
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Stacking:</label>
                    <div style="display: flex; gap: 10px;">
                        <button onclick="changeZIndex(\\\'up\\\')" style="flex: 1; padding: 8px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;">Bring Forward</button>
                        <button onclick="changeZIndex(\\\'down\\\')" style="flex: 1; padding: 8px; background: #e9ecef; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;">Send Backward</button>
                    </div>
                </div>

                <div style="display: flex; gap: 10px;">
                    <button onclick="deleteSelectedShape()" style="flex: 1; padding: 10px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">Delete</button>
                </div>
            `;
            
            document.body.appendChild(panel);
        }
        
        function hideImageStylingPanel() {
            const panel = document.getElementById(\'imageStylingPanel\');
            if (panel) panel.remove();
        }
        
        // Crop Modal
        function openCropModal() {
            if (!selectedElement || selectedElement.tagName !== \'IMG\') return;
            
            // Create modal
            const modal = document.createElement(\'div\');
            modal.id = \'cropModal\';
            modal.style.cssText = \'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.8); z-index: 100000; display: flex; flex-direction: column; align-items: center; justify-content: center;\';
            
            // Canvas container
            const container = document.createElement(\'div\');
            container.style.cssText = \'position: relative; max-width: 90%; max-height: 80%; background: #333; overflow: hidden; box-shadow: 0 0 20px rgba(0,0,0,0.5);\';
            
            const canvas = document.createElement(\'canvas\');
            canvas.id = \'cropCanvas\';
            
            // Load image into canvas
            const img = new Image();
            img.crossOrigin = "Anonymous";
            img.onload = function() {
                // Resize logic to fit screen
                let width = img.width;
                let height = img.height;
                const maxWidth = window.innerWidth * 0.8;
                const maxHeight = window.innerHeight * 0.7;
                
                if (width > maxWidth) {
                    const ratio = maxWidth / width;
                    width = maxWidth;
                    height = height * ratio;
                }
                
                if (height > maxHeight) {
                    const ratio = maxHeight / height;
                    height = maxHeight;
                    width = width * ratio;
                }
                
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext(\'2d\');
                ctx.drawImage(img, 0, 0, width, height);
                
                // Initialize selection
                initCropSelection(canvas, img, width, height);
            };
            img.src = selectedElement.src;
            
            container.appendChild(canvas);
            
            // Controls
            const controls = document.createElement(\'div\');
            controls.style.cssText = \'margin-top: 20px; display: flex; gap: 15px;\';
            
            controls.innerHTML = `
                <button onclick="performCrop()" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>Apply Crop</button>
                <button onclick="closeCropModal()" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>Cancel</button>
            `;
            
            modal.appendChild(container);
            modal.appendChild(controls);
            document.body.appendChild(modal);
        }
        
        let cropSelection = { x: 0, y: 0, w: 0, h: 0 };
        
        function initCropSelection(canvas, originalImg, displayWidth, displayHeight) {
            const ctx = canvas.getContext(\'2d\');
            let isDragging = false;
            let startX, startY;
            
            // Default selection: center 80%
            cropSelection = {
                x: displayWidth * 0.1,
                y: displayHeight * 0.1,
                w: displayWidth * 0.8,
                h: displayHeight * 0.8
            };
            
            drawCropOverlay();
            
            canvas.onmousedown = function(e) {
                const rect = canvas.getBoundingClientRect();
                startX = e.clientX - rect.left;
                startY = e.clientY - rect.top;
                isDragging = true;
                
                // Reset selection
                cropSelection.x = startX;
                cropSelection.y = startY;
                cropSelection.w = 0;
                cropSelection.h = 0;
            };
            
            canvas.onmousemove = function(e) {
                if (!isDragging) return;
                const rect = canvas.getBoundingClientRect();
                const mouseX = e.clientX - rect.left;
                const mouseY = e.clientY - rect.top;
                
                cropSelection.w = mouseX - startX;
                cropSelection.h = mouseY - startY;
                
                drawCropOverlay();
            };
            
            canvas.onmouseup = function() {
                isDragging = false;
                // Normalize negative width/height
                if (cropSelection.w < 0) {
                    cropSelection.x += cropSelection.w;
                    cropSelection.w = Math.abs(cropSelection.w);
                }
                if (cropSelection.h < 0) {
                    cropSelection.y += cropSelection.h;
                    cropSelection.h = Math.abs(cropSelection.h);
                }
            };
            
            function drawCropOverlay() {
                // Redraw image
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(originalImg, 0, 0, displayWidth, displayHeight);
                
                // Draw semi-transparent overlay
                ctx.fillStyle = \'rgba(0, 0, 0, 0.5)\';
                ctx.fillRect(0, 0, canvas.width, canvas.height);
                
                // Clear selection area
                ctx.clearRect(cropSelection.x, cropSelection.y, cropSelection.w, cropSelection.h);
                ctx.drawImage(originalImg, 
                    cropSelection.x * (originalImg.width / displayWidth), 
                    cropSelection.y * (originalImg.height / displayHeight), 
                    cropSelection.w * (originalImg.width / displayWidth), 
                    cropSelection.h * (originalImg.height / displayHeight), 
                    cropSelection.x, cropSelection.y, cropSelection.w, cropSelection.h
                );
                
                // Draw border
                ctx.strokeStyle = \'white\';
                ctx.lineWidth = 2;
                ctx.setLineDash([5, 5]);
                ctx.strokeRect(cropSelection.x, cropSelection.y, cropSelection.w, cropSelection.h);
                ctx.setLineDash([]);
            }
        }
        
        function performCrop() {
            if (!selectedElement) return;
            
            const canvas = document.getElementById(\'cropCanvas\');
            const tempCanvas = document.createElement(\'canvas\');
            
            tempCanvas.width = cropSelection.w;
            tempCanvas.height = cropSelection.h;
            
            // Get data from the displayed canvas
            const ctx = canvas.getContext(\'2d\');
            const data = ctx.getImageData(cropSelection.x, cropSelection.y, cropSelection.w, cropSelection.h);
            
            tempCanvas.getContext(\'2d\').putImageData(data, 0, 0);
            
            // Update image
            selectedElement.src = tempCanvas.toDataURL();
            
            closeCropModal();
        }
        
        function closeCropModal() {
            const modal = document.getElementById(\'cropModal\');
            if (modal) modal.remove();
        }
        
        // Toggle Image/Shape Panel
        function toggleImageShapePanel() {
            let panel = document.getElementById(\'imageShapePanel\');
            if (!panel) {
                addImageShapePanel();
                panel = document.getElementById(\'imageShapePanel\');
            }
            if (panel) {
                applyPanelLayout(panel, \'right\');
                const currentDisplay = window.getComputedStyle(panel).display;
                if (currentDisplay === \'none\') {
                    panel.style.setProperty(\'display\', \'block\', \'important\');
                } else {
                    panel.style.setProperty(\'display\', \'none\', \'important\');
                }
            }
            updateResumeOffsetForPanels();
        }
        
        // Add Image/Shape Tools Panel
        function addImageShapePanel() {
            const existing = document.getElementById(\'imageShapePanel\');
            if (existing) existing.remove();
            
            const panel = document.createElement(\'div\');
            panel.id = \'imageShapePanel\';
            panel.contentEditable = \'false\';
            panel.className = \'no-edit\';
            panel.style.cssText = \'position: fixed !important; top: 80px !important; right: 20px !important; background: white !important; padding: 20px !important; border-radius: 8px !important; box-shadow: 0 4px 20px rgba(0,0,0,0.3) !important; z-index: 999998 !important; min-width: 280px !important; max-width: 320px !important; overflow-y: auto !important; max-height: calc(100vh - 100px) !important; pointer-events: auto !important; border: 2px solid #28a745 !important;\';
            panel.style.display = \'none\'; // Set display separately so it can be overridden
            panel.innerHTML = `
                <div style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); padding: 15px; margin: -20px -20px 20px -20px; border-radius: 6px 6px 0 0; display: flex; align-items: center; justify-content: space-between; gap: 10px;">
                    <h3 style="margin: 0; font-size: 18px; color: white; font-weight: 600;"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:6px;"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>Images & Shapes</h3>
                    <button onclick="toggleImageShapePanel()" title="Close" style="background: rgba(255,255,255,0.15); border: none; color: white; width: 26px; height: 26px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></button>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Upload Image:</label>
                    <input type="file" id="imageUploadInput" accept="image/*" style="width: 100%; padding: 8px; border: 2px solid #ddd; border-radius: 6px; cursor: pointer;">
                    <button onclick="handleImageUpload()" style="width: 100%; padding: 10px; background: #28a745; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; margin-top: 8px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>Upload & Insert</button>
                </div>
                <div style="margin-bottom: 15px; border-top: 1px solid #ddd; padding-top: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-size: 14px; color: #333; font-weight: 500;">Insert Shape:</label>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
                        <button onclick="insertShape(\\\'rectangle\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Rectangle"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><rect x="3" y="6" width="18" height="12"></rect></svg></button>
                        <button onclick="insertShape(\\\'square\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Square"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><rect x="4" y="4" width="16" height="16"></rect></svg></button>
                        <button onclick="insertShape(\\\'rounded-rect\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Rounded Rectangle"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><rect x="3" y="6" width="18" height="12" rx="4"></rect></svg></button>
                        <button onclick="insertShape(\\\'circle\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Circle"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><circle cx="12" cy="12" r="9"></circle></svg></button>
                        <button onclick="insertShape(\\\'line\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Line"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"></line></svg></button>
                        <button onclick="insertShape(\\\'triangle\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Triangle"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><path d="M12 4 L20 20 L4 20 Z"></path></svg></button>
                        <button onclick="insertShape(\\\'star\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Star"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><path d="M12 2 L15 9 L22 9.5 L16.5 14 L18.5 21 L12 17 L5.5 21 L7.5 14 L2 9.5 L9 9 Z"></path></svg></button>
                        <button onclick="insertShape(\\\'arrow\\\')" style="padding: 8px; background: #f8f9fa; border: 1px solid #ced4da; border-radius: 4px; cursor: pointer;" title="Arrow"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#495057" stroke-width="2"><line x1="4" y1="12" x2="20" y2="12"></line><polyline points="14 6 20 12 14 18"></polyline></svg></button>
                    </div>
                </div>
                <p style="margin: 15px 0 0 0; font-size: 11px; color: #999; text-align: center; font-style: italic;">Tip: drag elements to reposition</p>
            `;
            document.body.appendChild(panel);
            
            // Make panel non-editable
            panel.querySelectorAll(\'*\').forEach(child => {
                child.setAttribute(\'contenteditable\', \'false\');
                child.contentEditable = \'false\';
            });
        }
        
        // Handle Image Upload
        async function handleImageUpload() {
            const input = document.getElementById(\'imageUploadInput\');
            if (!input || !input.files || !input.files[0]) {
                alert(\'Please select an image file first.\');
                return;
            }
            
            const formData = new FormData();
            formData.append(\'image\', input.files[0]);
            
            try {
                const response = await fetch(\'/resume_generator/api/upload_image.php\', {
                    method: \'POST\',
                    body: formData
                });
                
                const result = await response.json();
                if (result.success && result.url) {
                    insertImage(result.url);
                    input.value = \'\'; // Clear input
                } else {
                    alert(\'Upload failed: \' + (result.error || \'Unknown error\'));
                }
            } catch (error) {
                console.error(\'Upload error:\', error);
                alert(\'Failed to upload image. Please try again.\');
            }
        }
        
        // Insert Image
        function insertImage(imageUrl) {
            const resumeContainer = document.querySelector(\'.resume-container\') || document.body;
            const img = document.createElement(\'img\');
            img.src = imageUrl;
            img.style.cssText = \'position: absolute; width: 150px; height: auto; z-index: 1000;\';
            img.className = \'draggable-image\';
            img.setAttribute(\'draggable\', \'true\');
            if (editModeEnabled) {
                img.style.cursor = \'move\';
                img.style.border = \'2px dashed #007bff\';
            } else {
                img.style.cursor = \'default\';
                img.style.border = \'none\';
            }
            img.style.left = \'50px\';
            img.style.top = \'50px\';
            
            // Make image draggable (only if in edit mode)
            if (editModeEnabled) {
                makeElementDraggable(img);
            }
            
            // Add click handler for selection
            img.addEventListener(\'click\', function(e) {
                e.stopPropagation();
                if (editModeEnabled) {
                    selectElement(img);
                }
            });
            
            resumeContainer.style.position = \'relative\';
            resumeContainer.appendChild(img);
            
            // Check page overflow after insertion
            checkPageOverflow();
        }
        
        // Insert Shape
        function insertShape(shapeType) {
            const resumeContainer = document.querySelector(\'.resume-container\') || document.body;
            const shape = document.createElement(\'div\');
            shape.className = \'draggable-shape\';
            shape.style.cssText = \'position: absolute; z-index: 1000; box-sizing: border-box;\';
            shape.setAttribute(\'data-shape\', shapeType);
            
            // Default styling
            let defaultWidth = 100;
            let defaultHeight = 100;
            let defaultColor = \'#6c757d\'; // Professional grey
            
            if (editModeEnabled) {
                shape.style.cursor = \'move\';
                // shape.style.border = \'2px solid #007bff\'; // Removed default border
            } else {
                shape.style.cursor = \'default\';
            }
            
            switch(shapeType) {
                case \'rectangle\':
                    shape.style.width = \'150px\';
                    shape.style.height = \'100px\';
                    shape.style.backgroundColor = defaultColor;
                    defaultWidth = 150;
                    defaultHeight = 100;
                    break;
                case \'square\':
                    shape.style.width = \'100px\';
                    shape.style.height = \'100px\';
                    shape.style.backgroundColor = defaultColor;
                    defaultWidth = 100;
                    defaultHeight = 100;
                    break;
                case \'rounded-rect\':
                    shape.style.width = \'150px\';
                    shape.style.height = \'100px\';
                    shape.style.backgroundColor = defaultColor;
                    shape.style.borderRadius = \'15px\';
                    defaultWidth = 150;
                    defaultHeight = 100;
                    break;
                case \'circle\':
                    shape.style.width = \'100px\';
                    shape.style.height = \'100px\';
                    shape.style.borderRadius = \'50%\';
                    shape.style.backgroundColor = defaultColor;
                    defaultWidth = 100;
                    defaultHeight = 100;
                    break;
                case \'triangle\':
                    shape.style.width = \'0\';
                    shape.style.height = \'0\';
                    shape.style.borderLeft = \'50px solid transparent\';
                    shape.style.borderRight = \'50px solid transparent\';
                    shape.style.borderBottom = \'86px solid \' + defaultColor;
                    shape.style.backgroundColor = \'transparent\';
                    defaultWidth = 100;
                    defaultHeight = 86;
                    break;
                case \'star\':
                    shape.innerHTML = \'★\';
                    shape.style.fontSize = \'80px\';
                    shape.style.width = \'80px\';
                    shape.style.height = \'80px\';
                    shape.style.textAlign = \'center\';
                    shape.style.lineHeight = \'80px\';
                    shape.style.color = defaultColor;
                    shape.style.backgroundColor = \'transparent\';
                    defaultWidth = 80;
                    defaultHeight = 80;
                    break;
                case \'arrow\':
                    shape.innerHTML = \'→\';
                    shape.style.fontSize = \'80px\';
                    shape.style.width = \'80px\';
                    shape.style.height = \'80px\';
                    shape.style.textAlign = \'center\';
                    shape.style.lineHeight = \'80px\';
                    shape.style.color = defaultColor;
                    shape.style.backgroundColor = \'transparent\';
                    defaultWidth = 80;
                    defaultHeight = 80;
                    break;
                case \'line\':
                    shape.style.width = \'200px\';
                    shape.style.height = \'4px\';
                    shape.style.backgroundColor = defaultColor;
                    defaultWidth = 200;
                    defaultHeight = 4;
                    break;
            }
            
            // Calculate center position relative to viewport
            const rect = resumeContainer.getBoundingClientRect();
            let left = (window.innerWidth / 2) - rect.left - (defaultWidth / 2);
            let top = (window.innerHeight / 2) - rect.top - (defaultHeight / 2);

            shape.style.left = left + \'px\';
            shape.style.top = top + \'px\';
            
            shape.setAttribute(\'draggable\', \'true\');
            
            // Make shape draggable (only if in edit mode)
            if (editModeEnabled) {
                makeElementDraggable(shape);
                setTimeout(() => selectElement(shape), 50);
            }
            
            // Add click handler for selection
            shape.addEventListener(\'click\', function(e) {
                e.stopPropagation();
                if (editModeEnabled) {
                    selectElement(shape);
                }
            });
            
            resumeContainer.style.position = \'relative\';
            resumeContainer.appendChild(shape);
            
            // Check page overflow after insertion
            checkPageOverflow();
        }
        
        // Make Element Draggable
        function makeElementDraggable(element) {
            // Only make draggable if in edit mode
            if (!editModeEnabled) return;
            
            // Mark element as draggable
            element.setAttribute(\'data-draggable\', \'true\');
            
            let isDragging = false;
            let startX, startY, initialLeft, initialTop;
            let dragStartHandler, dragHandler, dragEndHandler;
            
            dragStartHandler = function(e) {
                // Only allow dragging in edit mode
                if (!editModeEnabled) return;
                // Don\'t drag if clicking on resize handle or delete button
                if (e.target.closest(\'.resize-handle\') || e.target.closest(\'#deleteSelectedElement\')) return;
                
                // Select element on click
                selectElement(element);
                
                // Only drag if clicking directly on element (not on handles)
                if (e.target !== element && !element.contains(e.target)) return;
                if (e.target.closest(\'.resize-handle\') || e.target.closest(\'#deleteSelectedElement\')) return;
                
                isDragging = true;
                element.style.cursor = \'grabbing\';
                element.style.opacity = \'0.8\';
                
                const rect = element.getBoundingClientRect();
                const container = element.parentElement.getBoundingClientRect();
                
                startX = e.clientX;
                startY = e.clientY;
                
                // Get current position
                initialLeft = rect.left - container.left;
                initialTop = rect.top - container.top;
                
                // Ensure element has position absolute
                if (getComputedStyle(element).position !== \'absolute\') {
                    element.style.position = \'absolute\';
                }
                
                element.style.left = initialLeft + \'px\';
                element.style.top = initialTop + \'px\';
                
                document.addEventListener(\'mousemove\', dragHandler);
                document.addEventListener(\'mouseup\', dragEndHandler);
                
                e.preventDefault();
            };
            
            dragHandler = function(e) {
                if (!isDragging || !editModeEnabled) return;
                e.preventDefault();
                
                const container = element.parentElement.getBoundingClientRect();
                const deltaX = e.clientX - startX;
                const deltaY = e.clientY - startY;
                
                const newLeft = initialLeft + deltaX;
                const newTop = initialTop + deltaY;
                
                element.style.left = newLeft + \'px\';
                element.style.top = newTop + \'px\';
                
                // Update selection box if element is selected
                if (selectedElement === element) {
                    updateSelectionBox(element);
                }
            };
            
            dragEndHandler = function(e) {
                if (!isDragging) return;
                
                isDragging = false;
                element.style.cursor = editModeEnabled ? \'move\' : \'default\';
                element.style.opacity = \'1\';
                
                document.removeEventListener(\'mousemove\', dragHandler);
                document.removeEventListener(\'mouseup\', dragEndHandler);
                
                // Check page overflow after dragging
                checkPageOverflow();
                
                // Update selection box if element is selected
                if (selectedElement === element) {
                    updateSelectionBox(element);
                }
            };
            
            // Add event listener
            element.addEventListener(\'mousedown\', dragStartHandler);
            
            // Store handlers for later removal
            element._dragStartHandler = dragStartHandler;
            element._dragHandler = dragHandler;
            element._dragEndHandler = dragEndHandler;
        }
        
        // Disable dragging for all draggable elements
        function disableDragging() {
            document.querySelectorAll(\'[data-draggable="true"]\').forEach(element => {
                if (element._dragStartHandler) {
                    element.removeEventListener(\'mousedown\', element._dragStartHandler);
                }
                element.style.cursor = \'default\';
                element.style.border = element.style.border.replace(\'2px dashed #007bff\', \'none\').replace(\'2px solid #007bff\', \'none\');
            });
        }
        
        // Enable dragging for all draggable elements (only in edit mode)
        function enableDragging() {
            if (!editModeEnabled) return;
            document.querySelectorAll(\'[data-draggable="true"]\').forEach(element => {
                // Remove old handler if exists
                if (element._dragStartHandler) {
                    element.removeEventListener(\'mousedown\', element._dragStartHandler);
                }
                // Re-enable dragging
                makeElementDraggable(element);
                element.style.cursor = \'move\';
            });
        }
        
        // ========== PAGE OVERFLOW DETECTION ==========
        
        // A4 Page dimensions in pixels (at 96 DPI)
        const A4_WIDTH_PX = 794; // 210mm
        const A4_HEIGHT_PX = 1123; // 297mm
        
        function getResumeContainer() {
            // initializePage() runs once immediately on script execution,
            // before the page body has even been parsed yet - as a defensive
            // belt-and-suspenders pattern, so document.body can briefly be
            // null. Bail out quietly; the later DOMContentLoaded/load/
            // setTimeout calls will succeed once body actually exists.
            if (!document.body) return null;
            const byClass = document.querySelector(\'.resume-container\') ||
                   document.querySelector(\'.resume-classic\') ||
                   document.querySelector(\'.resume-modern\') ||
                   document.querySelector(\'.resume-professional\') ||
                   document.querySelector(\'.resume-creative\') ||
                   document.querySelector(\'.resume-clean\') ||
                   document.querySelector(\'.resume-profile\') ||
                   document.querySelector(\'.resume-simple\') ||
                   document.querySelector(\'.resume-two-column\');
            if (byClass) return byClass;

            // Most AI-generated resumes don\'t use any of the class names
            // above, so fall back to the first real content element directly
            // inside the page body that isn\'t one of the editor\'s own
            // overlay panels. Without this, every resume that doesn\'t happen
            // to match a known class name above silently resolves to the
            // page body element itself, which breaks panel-avoidance,
            // one-page-fit, and centering. (Note: deliberately not writing
            // the literal body tag here in angle brackets - this whole file
            // is a big string with simple text-replace HTML injection, so
            // that exact text would get HTML spliced into the middle of
            // this comment and break the page.)
            const skipIds = [\'resumeControls\', \'colorEditorPanel\', \'imageShapePanel\', \'shapeStylingPanel\', \'imageStylingPanel\', \'cropModal\', \'pageOverflowWarning\', \'selectionBox\', \'deleteSelectedElement\', \'addSectionMenu\'];
            const candidate = Array.from(document.body.children).find(el => {
                if (skipIds.indexOf(el.id) !== -1) return false;
                if (el.classList && el.classList.contains(\'no-edit\')) return false;
                if (el.tagName === \'SCRIPT\' || el.tagName === \'STYLE\') return false;
                return true;
            });
            return candidate || document.body;
        }

        // Shrink the resume content down so it genuinely fits within one A4
        // page, instead of just clipping or letting it spill onto extra
        // pages. Uses CSS "zoom" where available since it actually reflows
        // layout (so print pagination and PDF capture both see the smaller
        // size); falls back to a transform+width trick otherwise. Never
        // shrinks below 55% so text stays legible.
        const MIN_FIT_SCALE = 0.55;
        const SUPPORTS_ZOOM = (function(){ try { return \'zoom\' in document.body.style; } catch (e) { return false; } })();
        function resetFit(resumeContainer) {
            resumeContainer.style.zoom = \'\';
            resumeContainer.style.transform = \'\';
            resumeContainer.style.width = \'\';
        }

        // ========== RESPONSIVE EDIT PANELS (avoid blocking the resume) ==========
        // The color/image panels used to auto-open on both sides of the
        // resume and stay open the whole time you were editing, covering the
        // content. They now stay closed until toggled, can be closed with an
        // X, and the resume shifts out of their way on wide screens (or the
        // panels become a bottom sheet on narrow screens so they never
        // overlap the resume horizontally).
        const PANEL_BREAKPOINT = 1100;
        let panelOffsetLeft = \'\';
        let panelOffsetRight = \'\';

        function isNarrowViewport() {
            return window.innerWidth < PANEL_BREAKPOINT;
        }

        function applyResumeMargins(container) {
            const leftReserved = parseFloat(panelOffsetLeft) || 0;
            const rightReserved = parseFloat(panelOffsetRight) || 0;

            if (!leftReserved && !rightReserved) {
                container.style.marginLeft = \'auto\';
                container.style.marginRight = \'auto\';
                return;
            }

            // Center the resume in whatever space is left between the open
            // panel(s), instead of just shoving it off to one side - that
            // way there is always clear room to actually see the edit.
            container.style.marginRight = \'auto\';
            const naturalWidth = container.getBoundingClientRect().width;
            const viewportWidth = window.innerWidth;
            const availableCenter = leftReserved + (viewportWidth - leftReserved - rightReserved) / 2;
            let desiredLeft = availableCenter - naturalWidth / 2;
            if (desiredLeft < leftReserved) desiredLeft = leftReserved; // never sit under the open panel
            container.style.marginLeft = desiredLeft + \'px\';
            container.style.marginRight = \'auto\';
        }

        function applyPanelLayout(panel, side) {
            if (!panel) return;
            if (isNarrowViewport()) {
                panel.style.setProperty(\'top\', \'auto\', \'important\');
                panel.style.setProperty(\'bottom\', \'0px\', \'important\');
                panel.style.setProperty(\'left\', \'0px\', \'important\');
                panel.style.setProperty(\'right\', \'0px\', \'important\');
                panel.style.setProperty(\'width\', \'100%\', \'important\');
                panel.style.setProperty(\'min-width\', \'0\', \'important\');
                panel.style.setProperty(\'max-width\', \'100%\', \'important\');
                panel.style.setProperty(\'max-height\', \'45vh\', \'important\');
                panel.style.setProperty(\'border-radius\', \'14px 14px 0 0\', \'important\');
                panel.style.setProperty(\'box-shadow\', \'0 -8px 30px rgba(0,0,0,0.25)\', \'important\');
            } else {
                panel.style.setProperty(\'bottom\', \'auto\', \'important\');
                panel.style.setProperty(\'top\', \'80px\', \'important\');
                panel.style.setProperty(\'width\', \'auto\', \'important\');
                panel.style.setProperty(\'left\', side === \'left\' ? \'20px\' : \'auto\', \'important\');
                panel.style.setProperty(\'right\', side === \'right\' ? \'20px\' : \'auto\', \'important\');
                panel.style.setProperty(\'min-width\', side === \'left\' ? \'260px\' : \'280px\', \'important\');
                panel.style.setProperty(\'max-width\', side === \'left\' ? \'300px\' : \'320px\', \'important\');
                panel.style.setProperty(\'max-height\', \'calc(100vh - 100px)\', \'important\');
                panel.style.setProperty(\'border-radius\', \'8px\', \'important\');
                panel.style.setProperty(\'box-shadow\', \'0 4px 20px rgba(0,0,0,0.3)\', \'important\');
            }
        }

        function updateResumeOffsetForPanels() {
            const resumeContainer = getResumeContainer();
            if (!resumeContainer || resumeContainer === document.body) return;
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            const imagePanel = document.getElementById(\'imageShapePanel\');
            const colorOpen = !!colorPanel && window.getComputedStyle(colorPanel).display !== \'none\';
            const imageOpen = !!imagePanel && window.getComputedStyle(imagePanel).display !== \'none\';

            if (isNarrowViewport()) {
                // Panels become a bottom sheet on narrow screens - no horizontal push needed
                panelOffsetLeft = \'\';
                panelOffsetRight = \'\';
            } else {
                panelOffsetLeft = colorOpen ? \'320px\' : \'\';
                panelOffsetRight = imageOpen ? \'340px\' : \'\';
            }
            applyResumeMargins(resumeContainer);
        }

        function toggleColorPanel() {
            let panel = document.getElementById(\'colorEditorPanel\');
            if (!panel) {
                addColorPickerControls();
                panel = document.getElementById(\'colorEditorPanel\');
            }
            if (!panel) return;
            applyPanelLayout(panel, \'left\');
            const currentDisplay = window.getComputedStyle(panel).display;
            if (currentDisplay === \'none\') {
                panel.style.setProperty(\'display\', \'block\', \'important\');
                updateColorPickers();
            } else {
                panel.style.setProperty(\'display\', \'none\', \'important\');
            }
            updateResumeOffsetForPanels();
        }

        window.addEventListener(\'resize\', () => {
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            const imagePanel = document.getElementById(\'imageShapePanel\');
            if (colorPanel && window.getComputedStyle(colorPanel).display !== \'none\') applyPanelLayout(colorPanel, \'left\');
            if (imagePanel && window.getComputedStyle(imagePanel).display !== \'none\') applyPanelLayout(imagePanel, \'right\');
            updateResumeOffsetForPanels();
        });

        function autoFitToOnePage() {
            if (editModeEnabled) return; // never resize while actively editing
            const resumeContainer = getResumeContainer();
            if (!resumeContainer) return;

            resetFit(resumeContainer);
            resumeContainer.style.transformOrigin = \'top center\';
            applyResumeMargins(resumeContainer);

            // Small safety margin so rounding/sub-pixel differences between
            // screen rendering and the actual print engine never tip a
            // just-barely-one-page resume into a second page
            const targetHeight = A4_HEIGHT_PX * 0.97;
            const naturalHeight = resumeContainer.scrollHeight;
            if (naturalHeight > targetHeight) {
                const scale = Math.max(targetHeight / naturalHeight, MIN_FIT_SCALE);
                if (SUPPORTS_ZOOM) {
                    resumeContainer.style.zoom = String(scale);
                } else {
                    resumeContainer.style.transform = \'scale(\' + scale + \')\';
                    resumeContainer.style.width = (100 / scale) + \'%\';
                }
            }
        }

        function checkPageOverflow() {
            autoFitToOnePage();
        }

        // Monitor content changes and keep the resume auto-fitted to one page
        function startOverflowMonitoring() {
            // Check on load
            setTimeout(autoFitToOnePage, 500);

            // Monitor for content changes
            const observer = new MutationObserver(() => {
                autoFitToOnePage();
            });

            const resumeContainer = getResumeContainer();
            if (resumeContainer) {
                // Note: only watch class changes, not style - autoFitToOnePage()
                // itself sets style.zoom/transform on this same element, and
                // watching "style" here would re-trigger itself in a loop.
                observer.observe(resumeContainer, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: [\'class\']
                });
            }

            // Also check on window resize
            window.addEventListener(\'resize\', autoFitToOnePage);
        }
        
        // ========== PRINT CONSTRAINT ==========
        
        function printResume() {
            // Earlier attempts kept this as a live-DOM print: shrink with
            // CSS zoom/transform, then set a custom @page size around the
            // live content. That kept breaking in two different ways: (1)
            // Chrome\'s print engine doesn\'t reliably paginate against a
            // transformed/zoomed box\'s visual size, and (2) shrinking the
            // @page WIDTH actually changes the print-time viewport itself,
            // which can reflow the live content differently than what was
            // measured on screen (wrong page size computed from stale
            // measurements). Both are eliminated by reusing the exact same
            // approach as the "Download PDF" button: rasterize the resume
            // with html2canvas first (fixed pixel dimensions, no further
            // reflow possible), then print THAT image into a print-only
            // iframe whose @page size is computed directly from the
            // image\'s own aspect ratio - guaranteed to hug the content on
            // both axes with zero blank space, capped at one page.
            const wasInEditModeForPrint = editModeEnabled;
            if (editModeEnabled) {
                exitEditMode();
            }

            deselectElement();

            const controls = document.getElementById(\'resumeControls\');
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            const imagePanel = document.getElementById(\'imageShapePanel\');
            const controlsDisplay = controls ? controls.style.display : \'flex\';
            const panelDisplay = colorPanel ? colorPanel.style.display : \'none\';
            if (controls) controls.style.display = \'none\';
            if (colorPanel) colorPanel.style.display = \'none\';
            if (imagePanel) imagePanel.style.display = \'none\';

            const printEl = getResumeContainer();
            if (!printEl || printEl === document.body) {
                // No identifiable container - fall back to a plain print.
                window.print();
                if (controls) controls.style.display = controlsDisplay;
                if (colorPanel) colorPanel.style.display = panelDisplay;
                if (wasInEditModeForPrint) enterEditMode();
                return;
            }

            const origShadow = printEl.style.boxShadow;
            const origRadius = printEl.style.borderRadius;
            printEl.style.setProperty(\'box-shadow\', \'none\', \'important\');
            printEl.style.setProperty(\'border-radius\', \'0\', \'important\');
            resetFit(printEl);

            const restoreEditor = () => {
                printEl.style.boxShadow = origShadow;
                printEl.style.borderRadius = origRadius;
                if (controls) controls.style.display = controlsDisplay;
                if (colorPanel) colorPanel.style.display = panelDisplay;
                if (wasInEditModeForPrint) {
                    enterEditMode();
                } else {
                    autoFitToOnePage();
                }
            };

            setTimeout(() => {
                html2canvas(printEl, {
                    scale: 2.5,
                    useCORS: true,
                    logging: false,
                    backgroundColor: \'#ffffff\',
                    width: printEl.scrollWidth,
                    height: printEl.scrollHeight,
                    windowWidth: printEl.scrollWidth,
                    windowHeight: printEl.scrollHeight,
                    allowTaint: false,
                    removeContainer: false,
                    ignoreElements: (el) => {
                        return el.id === \'resumeControls\' ||
                               el.id === \'colorEditorPanel\' ||
                               el.classList.contains(\'no-edit\');
                    },
                    onclone: (clonedDoc) => {
                        clonedDoc.body.style.backgroundColor = \'#ffffff\';
                        // Same fix as downloadAsPDF(): zero out the page
                        // chrome\'s fixed-px !important body padding inside
                        // the capture-only clone, or the centered container
                        // computes narrower inside html2canvas\'s constrained
                        // capture window than it visually is on screen,
                        // baking a blank strip into the captured image.
                        clonedDoc.body.style.setProperty(\'padding\', \'0\', \'important\');
                        clonedDoc.body.style.setProperty(\'margin\', \'0\', \'important\');
                        const clonedControls = clonedDoc.getElementById(\'resumeControls\');
                        const clonedPanel = clonedDoc.getElementById(\'colorEditorPanel\');
                        if (clonedControls) clonedControls.remove();
                        if (clonedPanel) clonedPanel.remove();
                    }
                }).then(function (canvas) {
                    const imgData = canvas.toDataURL(\'image/png\', 1.0);
                    const ratio = canvas.width / canvas.height;

                    // Same fit-to-one-page math as downloadAsPDF(): fill the
                    // full standard width first; only if that would be
                    // taller than one page, shrink to fit the height
                    // instead (and the page narrows to match, so there are
                    // never blank gutters on any side).
                    let pageWidthMM = 210;
                    let pageHeightMM = 210 / ratio;
                    if (pageHeightMM > 297) {
                        pageHeightMM = 297;
                        pageWidthMM = 297 * ratio;
                    }

                    const printFrame = document.createElement(\'iframe\');
                    printFrame.id = \'resumePrintFrame\';
                    printFrame.style.position = \'fixed\';
                    printFrame.style.right = \'0\';
                    printFrame.style.bottom = \'0\';
                    printFrame.style.width = \'0\';
                    printFrame.style.height = \'0\';
                    printFrame.style.border = \'0\';
                    document.body.appendChild(printFrame);

                    // Deliberately never write the literal opening/closing
                    // body tag (or a closing head tag) as contiguous text
                    // here - this whole file is one big PHP string with
                    // naive text-splice HTML injection elsewhere, and that
                    // exact text would get spliced into the middle of this
                    // script and corrupt it (this has happened twice
                    // before). HTML5 auto-creates the body element as soon
                    // as it hits the non-head <img> tag, and auto-closes
                    // head at the same point, so neither tag needs to be
                    // written explicitly at all.
                    const frameDoc = printFrame.contentWindow.document;
                    frameDoc.open();
                    frameDoc.write(
                        \'<!DOCTYPE html><html><head><meta charset="UTF-8">\' +
                        \'<style>@page { size: \' + pageWidthMM.toFixed(1) + \'mm \' + pageHeightMM.toFixed(1) + \'mm; margin: 0; } \' +
                        \'html, img { margin: 0; padding: 0; } \' +
                        \'img { display: block; width: 100%; height: 100%; }</style>\' +
                        \'<img src="\' + imgData + \'">\' +
                        \'</html>\'
                    );
                    frameDoc.close();

                    let printTriggered = false;
                    const triggerPrint = () => {
                        if (printTriggered) return;
                        printTriggered = true;
                        printFrame.contentWindow.focus();
                        printFrame.contentWindow.print();
                        setTimeout(() => {
                            if (printFrame.parentNode) printFrame.parentNode.removeChild(printFrame);
                            restoreEditor();
                        }, 500);
                    };
                    // The image needs a moment to decode inside the iframe
                    // before printing, even though doc.write() already
                    // returned - wait for the image\'s own load event, with
                    // a timeout fallback in case it already loaded.
                    const frameImg = frameDoc.querySelector(\'img\');
                    if (frameImg) {
                        frameImg.onload = triggerPrint;
                        if (frameImg.complete) triggerPrint();
                    }
                    setTimeout(triggerPrint, 600);
                }).catch(function (error) {
                    console.error(\'Error generating print image:\', error);
                    alert(\'Failed to prepare the print preview. Please try Download PDF instead.\');
                    restoreEditor();
                });
            }, 200);
        }
        
        // Download PDF
        function downloadAsPDF() {
            // Exit edit mode if active
            const wasInEditMode = editModeEnabled;
            if (editModeEnabled) {
                exitEditMode();
            }
            
            // Hide edit controls before generating PDF
            const controls = document.getElementById(\'resumeControls\');
            const colorPanel = document.getElementById(\'colorEditorPanel\');
            const controlsDisplay = controls ? controls.style.display : \'flex\';
            const panelDisplay = colorPanel ? colorPanel.style.display : \'none\';
            
            if (controls) controls.style.display = \'none\';
            if (colorPanel) colorPanel.style.display = \'none\';
            
            // Also hide any editable outlines
            document.querySelectorAll(\'[contenteditable="true"]\').forEach(el => {
                el.style.outline = \'none\';
            });
            
            // Find resume container - target the actual resume content
            let element = getResumeContainer();

            // IMPORTANT: capture the content at its TRUE natural size, not
            // shrunk. autoFitToOnePage() used to be called here, but it
            // applies a CSS zoom to shrink the container for on-screen
            // viewing BEFORE the capture - and html2canvas is then told to
            // use the container\'s (now zoomed-down) scrollWidth/scrollHeight
            // as its own internal rendering viewport size. That squeezes
            // the real content into an artificially narrow column, which
            // makes it reflow/wrap far more than normal and produces a
            // badly distorted, abnormally tall-and-narrow capture - the
            // resulting PDF page (sized from that capture\'s aspect ratio)
            // ends up a tall, narrow sliver instead of a normal page. The
            // PDF page is already sized to fit one page on its own further
            // below, so the source capture should never be pre-shrunk -
            // reset any zoom/transform first so the true, unzoomed layout
            // is what gets captured.
            if (element) {
                resetFit(element);
            }

            // The floating-card shadow/rounded corners are page chrome for the
            // editor view only - strip them before capturing so the exported
            // PDF/image is just the clean document, not the editor framing
            const origBoxShadow = element.style.boxShadow;
            const origBorderRadius = element.style.borderRadius;
            element.style.setProperty(\'box-shadow\', \'none\', \'important\');
            element.style.setProperty(\'border-radius\', \'0\', \'important\');

            // Small delay to ensure rendering
            setTimeout(() => {
                html2canvas(element, {
                    scale: 2.5, // Good balance between quality and performance
                useCORS: true,
                logging: false,
                    backgroundColor: \'#ffffff\',
                    width: element.scrollWidth,
                    height: element.scrollHeight,
                    windowWidth: element.scrollWidth,
                    windowHeight: element.scrollHeight,
                    allowTaint: false,
                    removeContainer: false,
                    ignoreElements: (el) => {
                        return el.id === \'resumeControls\' || 
                               el.id === \'colorEditorPanel\' ||
                               el.classList.contains(\'no-edit\');
                    },
                    onclone: (clonedDoc) => {
                        // Ensure cloned document is clean for capture
                        const clonedBody = clonedDoc.body;
                        clonedBody.style.backgroundColor = \'#ffffff\';
                        // html2canvas re-renders the WHOLE page inside its own
                        // hidden iframe sized to windowWidth/windowHeight
                        // (element.scrollWidth/Height here), not just the
                        // target element. The page chrome gives the page
                        // body a fixed-px !important padding (110px/24px/60px) for
                        // the editor\'s on-screen background - on the real,
                        // wide viewport that\'s negligible, but inside this
                        // much narrower capture-only window it eats into the
                        // available width, so the centered container
                        // computes a few percent NARROWER inside the capture
                        // than it visually is on screen - leaving a thin
                        // blank strip baked into the right edge of the
                        // captured image itself. Zero it out for the clone
                        // only (must use setProperty(...,\'important\') since
                        // the page rule is itself !important).
                        clonedBody.style.setProperty(\'padding\', \'0\', \'important\');
                        clonedBody.style.setProperty(\'margin\', \'0\', \'important\');
                        // Remove any controls from cloned doc
                        const clonedControls = clonedDoc.getElementById(\'resumeControls\');
                        const clonedPanel = clonedDoc.getElementById(\'colorEditorPanel\');
                        if (clonedControls) clonedControls.remove();
                        if (clonedPanel) clonedPanel.remove();
                    }
            }).then(function(canvas) {
                    const imgData = canvas.toDataURL(\'image/png\', 1.0);

                    // A4 width in mm - always use the full standard width.
                    // The page HEIGHT, however, is fit to the content
                    // (custom page size) instead of always being a fixed
                    // 297mm tall - a short resume doesn\'t need a full-height
                    // page, and forcing one just leaves blank space below
                    // the content. Only cap at one standard A4 height for
                    // resumes too long to fit on a single page that way.
                    const pdfWidth = 210; // A4 width in mm
                    const maxPdfHeight = 297; // one A4 page, max

                    const canvasWidth = canvas.width;
                    const canvasHeight = canvas.height;
                    const canvasAspectRatio = canvasWidth / canvasHeight;

                    let finalWidth = pdfWidth;
                    let finalHeight = pdfWidth / canvasAspectRatio;
                    let pageWidth = pdfWidth;
                    let pageHeight = finalHeight;
                    if (finalHeight > maxPdfHeight) {
                        // Too long even filling the full width - shrink to
                        // fit one page height instead. The PAGE itself also
                        // shrinks to match the now-narrower content (not
                        // just the image inside a still-210mm-wide page) -
                        // otherwise the content ends up centered inside
                        // blank vertical gutters on both sides instead.
                        finalHeight = maxPdfHeight;
                        finalWidth = maxPdfHeight * canvasAspectRatio;
                        pageWidth = finalWidth;
                        pageHeight = maxPdfHeight;
                    }

                    // Create PDF with a page exactly the size of the content
                    // on both axes - image always fills it edge to edge.
                    const pdf = new jspdf.jsPDF({
                        orientation: \'portrait\',
                        unit: \'mm\',
                        format: [pageWidth, pageHeight],
                        compress: true
                    });

                    pdf.addImage(imgData, \'PNG\', 0, 0, finalWidth, finalHeight);

                    pdf.save(\'resume-' . $resumeId . '.pdf\');

                    // Restore the floating-card framing used by the editor
                    element.style.boxShadow = origBoxShadow;
                    element.style.borderRadius = origBorderRadius;

                    // Show controls again
                    if (controls) controls.style.display = controlsDisplay;
                    if (colorPanel && wasInEditMode) colorPanel.style.display = panelDisplay;
                    if (wasInEditMode) {
                        enterEditMode();
                    }
            }).catch(function(error) {
                    console.error(\'Error generating PDF:\', error);
                    alert(\'Failed to generate PDF. Please try Print/Save as PDF instead.\');
                    element.style.boxShadow = origBoxShadow;
                    element.style.borderRadius = origBorderRadius;
                    if (controls) controls.style.display = controlsDisplay;
                    if (colorPanel && wasInEditMode) colorPanel.style.display = panelDisplay;
                });
            }, 300);
        }
        
        // Give the actual resume root element (whatever class name the AI
        // used) the floating-card look (shadow + rounded corners) - the
        // static CSS class-name selectors miss most AI-generated resumes
        // since they rarely use any of our known class names, so this is
        // applied directly to whatever getResumeContainer() finds.
        function applyFloatingCardStyle() {
            const el = getResumeContainer();
            if (!el || el === document.body) return;
            el.style.setProperty(\'box-shadow\', \'0 25px 60px rgba(31,38,135,0.18)\', \'important\');
            el.style.setProperty(\'border-radius\', \'14px\', \'important\');
        }

        // Initialize - ensure edit mode is OFF by default
        function initializePage() {
            try {
                applyFloatingCardStyle();

                // CRITICAL: Make sure edit mode is disabled FIRST
                editModeEnabled = false;
                
                // Ensure all content is NOT editable by default - AGGRESSIVE APPROACH
                document.body.contentEditable = \'false\';
                
                // Remove ALL contentEditable attributes from ALL elements
                const allElements = document.querySelectorAll(\'*\');
                allElements.forEach(el => {
                    // Skip control panels - they should stay non-editable
                    if (el.closest(\'#colorEditorPanel\') || el.closest(\'#resumeControls\')) {
                        el.setAttribute(\'contenteditable\', \'false\');
                        el.contentEditable = \'false\';
                        el.style.outline = \'none\';
                        return;
                    }
                    
                    // Skip buttons, inputs, labels
                    if (el.tagName === \'BUTTON\' || el.tagName === \'INPUT\' || el.tagName === \'LABEL\' || el.tagName === \'SCRIPT\' || el.tagName === \'STYLE\') {
                        return;
                    }
                    
                    // FORCE remove contentEditable
                    if (el.hasAttribute(\'contenteditable\')) {
                        el.removeAttribute(\'contenteditable\');
                    }
                    el.contentEditable = \'false\';
                    el.style.outline = \'none\';
                    el.style.minHeight = \'auto\';
                });
                
                // Hide color panel
                const colorPanel = document.getElementById(\'colorEditorPanel\');
                if (colorPanel) {
                    colorPanel.style.display = \'none\';
                    colorPanel.contentEditable = \'false\';
                    colorPanel.setAttribute(\'contenteditable\', \'false\');
                    // Make all children non-editable
                    colorPanel.querySelectorAll(\'*\').forEach(child => {
                        child.contentEditable = \'false\';
                        child.setAttribute(\'contenteditable\', \'false\');
                        child.style.outline = \'none\';
                    });
                }
                
                // CRITICAL: Ensure buttons are in correct state - FORCE update
                const btnEdit = document.getElementById(\'btnEdit\');
                const btnSave = document.getElementById(\'btnSave\');
                const btnCancel = document.getElementById(\'btnCancel\');
                const btnDownloadPDF = document.getElementById(\'btnDownloadPDF\');
                
                if (btnEdit) {
                    btnEdit.style.display = \'inline-block\';
                    btnEdit.disabled = false;
                    btnEdit.textContent = \'Edit\';
                    btnEdit.innerHTML = \'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit\';
                }

                if (btnSave) {
                    btnSave.style.display = \'none\';
                    btnSave.disabled = false;
                    btnSave.textContent = \'Save\';
                    btnSave.innerHTML = \'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline></svg>Save\';
                }
                
                if (btnCancel) {
                    btnCancel.style.display = \'none\';
                }
                
                if (btnDownloadPDF) {
                    btnDownloadPDF.style.display = \'inline-block\';
                }
                
                console.log(\'Page initialized - Edit mode OFF\');
            } catch (e) {
                console.error(\'Initialization error:\', e);
            }
        }
        
        // Run initialization MULTIPLE TIMES to ensure it works
        // Immediate execution
        initializePage();
        
        // Also run when DOM ready
        if (document.readyState === \'loading\') {
            document.addEventListener(\'DOMContentLoaded\', function() {
                initializePage();
            });
        } else {
            // Page already loaded
            setTimeout(initializePage, 50);
            setTimeout(initializePage, 200);
            setTimeout(initializePage, 500);
        }
        
        // Also run on window load
        window.addEventListener(\'load\', function() {
            initializePage();
        });
    </script>';
    
    // Insert before closing </head> tag (only if scripts exist)
    if (!empty($editAndDownloadScripts)) {
    if (strpos($htmlContent, '</head>') !== false) {
            // Use preg_replace with a limit of 1 (not str_replace, which would
            // replace EVERY literal occurrence of "</head>" - including any
            // that might appear inside a comment/string within the scripts
            // we are about to inject, corrupting the page).
            $htmlContent = preg_replace('/<\/head>/', addcslashes($editAndDownloadScripts, '\\$') . '</head>', $htmlContent, 1);
    } else {
        // If no </head> tag, add before </body> or at end
            $htmlContent = preg_replace('/<\/body>/', addcslashes($editAndDownloadScripts, '\\$') . '</body>', $htmlContent, 1);
        }
    }
    
    // Add control buttons (Edit, Save, Cancel, Download PDF) - only if not in preview mode
    $controlButtons = '';
    if (!$isPreview) {
        $controlButtons = '
        <div id="resumeControls" class="no-edit" contenteditable="false" style="position: fixed; top: 20px; right: 20px; z-index: 10000; display: flex; gap: 10px; flex-wrap: wrap;">
            <button id="btnEdit" onclick="enterEditMode()" style="padding: 10px 20px; background: #ffc107; color: #000; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>Edit</button>
            <button id="btnColors" onclick="toggleColorPanel()" style="display: none; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><circle cx="13.5" cy="6.5" r=".5"></circle><circle cx="17.5" cy="10.5" r=".5"></circle><circle cx="8.5" cy="7.5" r=".5"></circle><circle cx="6.5" cy="12.5" r=".5"></circle><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996C20.516 16.394 22 14.78 22 12.39 22 6.69 17.5 2 12 2z"></path></svg>Colors</button>
            <button id="btnImagesShapes" onclick="toggleImageShapePanel()" style="display: none; padding: 10px 20px; background: #6f42c1; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>Images & Shapes</button>
            <button id="btnSave" onclick="saveResume()" style="display: none; padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline></svg>Save</button>
            <button id="btnCancel" onclick="cancelEdit()" style="display: none; padding: 10px 20px; background: #6c757d; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>Cancel</button>
            <button id="btnDownloadPDF" onclick="downloadAsPDF()" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px; font-weight: 600;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>Download PDF</button>
            <button onclick="printResume()" style="padding: 10px 20px; background: #17a2b8; color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 14px;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-2px;margin-right:4px;"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>Print</button>
    </div>';
    }
    
    // Insert right after the opening body tag - matches "<body>" AND
    // "<body style=...>" / any attributes, since saved/edited resumes often
    // pick up inline attributes on <body> (e.g. from contenteditable
    // cleanup). preg_replace with limit 1 so this can never match a stray
    // "<body...>" mentioned in a JS/CSS comment string elsewhere in the page
    // (str_replace would replace every occurrence).
    if (preg_match('/<body[^>]*>/i', $htmlContent)) {
        $htmlContent = preg_replace('/<body([^>]*)>/i', '<body$1>' . addcslashes($controlButtons, '\\$'), $htmlContent, 1);
    } else {
        // If no <body> tag, prepend to content
        $htmlContent = $controlButtons . $htmlContent;
    }
    
    // Add preview mode styles if in preview mode
    if ($isPreview) {
        $previewStyles = '
        <style>
            body {
                margin: 0 !important;
                padding: 0 !important;
                overflow: hidden !important;
            }
            .resume-container {
                margin: 0 !important;
                padding: 10px !important;
                box-shadow: none !important;
                border: none !important;
            }
        </style>';
        $htmlContent = preg_replace('/<\/head>/', addcslashes($previewStyles, '\\$') . '</head>', $htmlContent, 1);
    }

    // Ensure a mobile viewport tag exists - AI-generated resumes don't always include one
    if (stripos($htmlContent, 'name="viewport"') === false && stripos($htmlContent, "name='viewport'") === false) {
        $htmlContent = preg_replace('/<\/head>/', '<meta name="viewport" content="width=device-width, initial-scale=1"></head>', $htmlContent, 1);
    }

    // Match the editor's backdrop to the rest of the site (colorful animated
    // gradient) instead of a plain white page, and let the resume float on
    // top of it like a document on a canvas - never touches the resume's
    // own background/colors, just the page chrome around it.
    if (!$isPreview) {
        $pageChromeStyles = '
        <style>
            body {
                background: linear-gradient(120deg, #eef2ff 0%, #fdf2ff 35%, #eafcff 70%, #f5f3ff 100%) !important;
                background-size: 200% 200% !important;
                animation: ar-gradient-pan 18s ease infinite !important;
                min-height: 100vh !important;
                padding: 110px 24px 60px !important;
                box-sizing: border-box !important;
            }
            @keyframes ar-gradient-pan {
                0% { background-position: 0% 50%; }
                50% { background-position: 100% 50%; }
                100% { background-position: 0% 50%; }
            }
            .resume-container, .resume-classic, .resume-modern, .resume-professional,
            .resume-creative, .resume-clean, .resume-profile, .resume-simple, .resume-two-column {
                box-shadow: 0 25px 60px rgba(31,38,135,0.18) !important;
                border-radius: 14px !important;
            }
            @media print {
                body { background: #fff !important; animation: none !important; padding: 0 !important; }
                .resume-container, .resume-classic, .resume-modern, .resume-professional,
                .resume-creative, .resume-clean, .resume-profile, .resume-simple, .resume-two-column {
                    box-shadow: none !important; border-radius: 0 !important;
                }
            }
        </style>';
        $htmlContent = preg_replace('/<\/head>/', addcslashes($pageChromeStyles, '\\$') . '</head>', $htmlContent, 1);
    }

    // Add print styles - hide all controls during print/PDF
    $printStyles = '
    <style>
        @media print {
            #resumeControls, #colorEditorPanel, #imageShapePanel, #pageOverflowWarning, #selectionBox, #deleteSelectedElement, #shapeStylingPanel { display: none !important; }
            [contenteditable="true"] { outline: none !important; }
            /* NOTE: do not clamp .resume-container/body height here with a
               static rule - printResume() in JS already applies a correctly
               computed hard height + overflow:hidden directly to whichever
               element getResumeContainer() actually finds (which often isn\'t
               .resume-container at all, e.g. AI-generated resumes that use
               .main-container instead). A second static clamp on body here
               would clip content independently of that JS-computed scale,
               which is exactly what caused content to be cut off on page 1
               and spill onto a near-empty page 2. */
            /* Ensure absolute positioned images and shapes maintain their position in print */
            .draggable-image, .draggable-shape {
                position: absolute !important;
                print-color-adjust: exact !important;
                -webkit-print-color-adjust: exact !important;
            }
            /* Remove borders from images/shapes in print */
            .draggable-image, .draggable-shape {
                border: none !important;
            }
        }
        /* Panel color editor - ensure it floats as OVERLAY and is non-editable.
           Position (left/top/bottom/right) is managed by applyPanelLayout()
           in JS so it can switch between a sidebar and a bottom sheet
           responsively - not fixed here. */
        #colorEditorPanel {
            position: fixed !important;
            float: none !important;
            clear: none !important;
            z-index: 999999 !important;
            isolation: isolate !important;
        }
        /* Ensure Save button is clickable */
        #btnSave {
            pointer-events: auto !important;
            cursor: pointer !important;
            opacity: 1 !important;
            z-index: 10001 !important;
            position: relative !important;
        }
        #btnSave:disabled {
            opacity: 0.6 !important;
            cursor: not-allowed !important;
        }
        body {
            position: relative;
        }
        #colorEditorPanel * {
            contenteditable: false !important;
            user-select: none;
            -webkit-user-select: none;
            -moz-user-select: none;
        }
        #colorEditorPanel input[type="color"],
        #colorEditorPanel button {
            pointer-events: auto !important;
            user-select: none !important;
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
        }
        .no-edit {
            pointer-events: auto !important;
        }
        .no-edit * {
            pointer-events: auto !important;
        }
        /* Toolbar - keep it usable and non-overlapping on phones/tablets */
        #resumeControls {
            max-width: calc(100vw - 40px) !important;
            background: rgba(255, 255, 255, 0.96) !important;
            padding: 8px !important;
            border-radius: 10px !important;
            box-shadow: 0 4px 16px rgba(0,0,0,0.15) !important;
        }
        @media (max-width: 700px) {
            #resumeControls {
                top: 10px !important;
                right: 10px !important;
                left: 10px !important;
                justify-content: center !important;
            }
            #resumeControls button {
                padding: 8px 12px !important;
                font-size: 13px !important;
                flex: 1 1 auto !important;
            }
            body { padding-top: 64px !important; }
        }
    </style>';
    $htmlContent = preg_replace('/<\/head>/', addcslashes($printStyles, '\\$') . '</head>', $htmlContent, 1);
}

// If opened with ?autodownload=1 (from the "PDF" link on My Resumes), kick
// off the real download pipeline automatically once everything is ready -
// same downloadAsPDF() the in-editor button uses, so behavior is identical
// and the one-page-fit logic applies here too.
if ($autoDownload && !$isPreview) {
    $autoDownloadScript = '
    <script>
        window.addEventListener(\'load\', function() {
            setTimeout(function() {
                if (typeof downloadAsPDF === \'function\') {
                    downloadAsPDF();
                }
            }, 600);
        });
    </script>';
    $htmlContent = preg_replace('/<\/head>/', addcslashes($autoDownloadScript, '\\$') . '</head>', $htmlContent, 1);
}

// If opened with ?autoedit=1 (from the "Edit" link on My Resumes), go
// straight into edit mode instead of making the user click Edit again
// after the page has already loaded.
if ($autoEdit && !$isPreview) {
    $autoEditScript = '
    <script>
        window.addEventListener(\'load\', function() {
            setTimeout(function() {
                if (typeof enterEditMode === \'function\') {
                    enterEditMode();
                }
            }, 300);
        });
    </script>';
    $htmlContent = preg_replace('/<\/head>/', addcslashes($autoEditScript, '\\$') . '</head>', $htmlContent, 1);
}

// Output HTML
header('Content-Type: text/html; charset=utf-8');
echo $htmlContent;

?>
