<?php
// ============================================================================
// LOCAL-ONLY CONFIGURATION
// ----------------------------------------------------------------------------
// This app is designed to run entirely on your own machine: MySQL listens on
// 127.0.0.1 (not a public host), and no resume/user data is sent anywhere
// except to the AI provider call you explicitly configure below (Groq).
// Nothing here talks to a "cloud database" — DB_HOST stays 127.0.0.1.
//
// SECRETS: real API keys / DB passwords must NOT be committed to git.
// Put them in api/config.local.php instead (gitignored). Copy
// api/config.local.php.example to api/config.local.php and fill it in.
// If config.local.php is missing, the safe-but-non-functional defaults
// below are used so the app still boots for grading/demo purposes.
// ============================================================================

// ---- Load local overrides (secrets) FIRST if the file exists. ----
// config.local.php is gitignored and should define DB_PASS, AUTH_SECRET,
// GROQ_API_KEY, GOOGLE_CLIENT_ID etc with your real values via define().
// This is the local-dev path (XAMPP etc).
$__localConfig = __DIR__ . '/config.local.php';
if (file_exists($__localConfig)) {
    require_once $__localConfig;
}

// For a containerized deployment (e.g. this app's Dockerfile, built and
// run on Render), config.local.php is deliberately gitignored and won't
// exist inside the built image at all - secrets are supplied as
// environment variables instead (set in Render's dashboard), which is the
// standard way to inject secrets into a container without baking them
// into the image/repo. env_or_default() checks getenv() first; if nothing
// from config.local.php and no env var is set either, falls back to the
// safe-but-non-functional default so the app still boots either way.
function env_or_default($name, $default) {
    $val = getenv($name);
    return ($val !== false && $val !== '') ? $val : $default;
}

// ---- Defaults for anything config.local.php didn't already define ----
// DB_HOST defaults to local-only (127.0.0.1) for local dev. For a deployed
// backend (e.g. on Render with managed Postgres), set these via Render's
// environment variables instead (or config.local.php on a non-container host).
if (!defined('DB_HOST'))     define('DB_HOST', env_or_default('DB_HOST', '127.0.0.1'));
if (!defined('DB_NAME'))     define('DB_NAME', env_or_default('DB_NAME', 'ai_resume_db'));
if (!defined('DB_USER'))     define('DB_USER', env_or_default('DB_USER', 'root'));
if (!defined('DB_PASS'))     define('DB_PASS', env_or_default('DB_PASS', ''));
if (!defined('DB_CHARSET'))  define('DB_CHARSET', env_or_default('DB_CHARSET', 'utf8mb4')); // only used for the mysql driver
// 'mysql' (local XAMPP dev, default) or 'pgsql' (e.g. Render managed Postgres)
if (!defined('DB_DRIVER'))   define('DB_DRIVER', env_or_default('DB_DRIVER', 'mysql'));
if (!defined('DB_PORT'))     define('DB_PORT', env_or_default('DB_PORT', '')); // blank = driver default (3306/5432)

if (!defined('AUTH_SECRET')) define('AUTH_SECRET', env_or_default('AUTH_SECRET', 'change_this_to_a_random_secret_please'));

// ---- Cross-domain deployment settings ----
// FRONTEND_BASE_URL: only needed when the frontend is deployed on a
// DIFFERENT domain than this backend (e.g. frontend on Cloudflare Pages,
// backend on its own host). Some pages here (view_resume.php) redirect the
// browser back to a frontend page - a relative "/resume_generator/..."
// path only works when frontend+backend share one origin. Leave this
// empty for local dev / single-host deployments (falls back to a
// same-origin relative redirect, unchanged behavior).
// Example: 'https://your-frontend.pages.dev'
if (!defined('FRONTEND_BASE_URL')) define('FRONTEND_BASE_URL', env_or_default('FRONTEND_BASE_URL', ''));

// ALLOWED_PROD_ORIGIN: the frontend's production origin, so send_cors_headers()
// below can allow it (in addition to localhost, always allowed for dev).
// Must match FRONTEND_BASE_URL above. Example: 'https://your-frontend.pages.dev'
if (!defined('ALLOWED_PROD_ORIGIN')) define('ALLOWED_PROD_ORIGIN', env_or_default('ALLOWED_PROD_ORIGIN', ''));

// Returns this backend's own public base URL (scheme + host), computed
// from the actual incoming request - works automatically on whatever
// domain the backend ends up hosted on, no manual configuration needed.
// Used to build fully-qualified asset URLs (e.g. generated resume HTML,
// uploaded images) that a frontend on a DIFFERENT domain can still load -
// a host-relative "/resume_generator/assets/..." path only resolves
// correctly when frontend+backend share one origin.
function backend_base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

// Groq API configuration (replaces Google Gemini — see api/config.local.php.example)
if (!defined('GROQ_API_KEY'))  define('GROQ_API_KEY', env_or_default('GROQ_API_KEY', ''));
if (!defined('GROQ_API_BASE')) define('GROQ_API_BASE', env_or_default('GROQ_API_BASE', 'https://api.groq.com/openai/v1/chat/completions'));

// Google Sign-In (OAuth). Only the Client ID is needed server-side for the
// simplified ID-token verification flow used in api/google_auth.php.
// Get one free at https://console.cloud.google.com/apis/credentials
if (!defined('GOOGLE_CLIENT_ID')) define('GOOGLE_CLIENT_ID', env_or_default('GOOGLE_CLIENT_ID', ''));

// Simple dev CORS settings (adjust for production)
function send_cors_headers() {
    // Prevent duplicate headers - check if already sent
    if (headers_sent()) {
        return;
    }
    
    // Allow all origins for development (change to specific origin in production)
    // Note: Cannot use '*' with credentials, so we echo the origin if provided
    if (isset($_SERVER['HTTP_ORIGIN'])) {
        $allowed_origins = [
            'http://localhost:3001',
            'http://localhost:3000',
            'http://localhost',
            'http://127.0.0.1:3001',
            'http://127.0.0.1:3000',
            'http://127.0.0.1'
        ];
        // The deployed production frontend (e.g. Cloudflare Pages domain) -
        // set via ALLOWED_PROD_ORIGIN in config.local.php once it exists.
        if (ALLOWED_PROD_ORIGIN !== '') {
            $allowed_origins[] = ALLOWED_PROD_ORIGIN;
        }
        $origin = $_SERVER['HTTP_ORIGIN'];
        // Check if origin is in allowed list OR starts with localhost/127.0.0.1
        if (in_array($origin, $allowed_origins) || strpos($origin, 'http://localhost') === 0 || strpos($origin, 'http://127.0.0.1') === 0) {
            header('Access-Control-Allow-Origin: ' . $origin, true); // true = replace if exists
        } else {
            header('Access-Control-Allow-Origin: *', true);
        }
    } else {
        header('Access-Control-Allow-Origin: *', true);
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS', true);
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Auth-Token', true);
    header('Access-Control-Max-Age: 86400', true); // 24 hours
}

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    send_cors_headers();
    http_response_code(200);
    exit(0);
}

// ---- Shared app-token helpers (used by auth.php, users.php, google_auth.php) ----
// Simple HMAC-signed token (not a real JWT library, but the same idea) used
// to identify a logged-in user across requests. Local-only secret, never
// sent anywhere except checked against itself.
function sign_token($payload) {
    $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload_enc = base64_encode(json_encode($payload));
    $sig = hash_hmac('sha256', $header . '.' . $payload_enc, AUTH_SECRET, true);
    $sig_enc = base64_encode($sig);
    return $header . '.' . $payload_enc . '.' . $sig_enc;
}

function verify_token($token) {
    if (!$token) return false;
    $parts = explode('.', $token);
    if (count($parts) !== 3) return false;
    list($h, $p, $s) = $parts;
    $sig = base64_encode(hash_hmac('sha256', $h . '.' . $p, AUTH_SECRET, true));
    if (!hash_equals($sig, $s)) return false;
    $payload = json_decode(base64_decode($p), true);
    if (!$payload) return false;
    if (isset($payload['exp']) && time() > $payload['exp']) return false;
    return $payload;
}

// Reads "Authorization: Bearer <token>" from the request, robust across SAPIs.
// Falls back to X-Auth-Token header (Apache on shared hosts like InfinityFree
// strips the Authorization header before PHP sees it; X-Auth-Token is a plain
// custom header that passes through unmodified).
function get_bearer_token() {
    $auth = null;

    // 1. Standard Authorization header
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? ($headers['authorization'] ?? null);
        // Also check X-Auth-Token (custom header, never stripped by Apache)
        if (!$auth) {
            $auth = isset($headers['X-Auth-Token']) ? 'Bearer ' . $headers['X-Auth-Token']
                  : (isset($headers['x-auth-token']) ? 'Bearer ' . $headers['x-auth-token'] : null);
        }
    }

    // 2. $_SERVER fallbacks (populated by .htaccess RewriteRule if mod_rewrite is on)
    if (!$auth) {
        if (!empty($_SERVER['HTTP_AUTHORIZATION']))          $auth = $_SERVER['HTTP_AUTHORIZATION'];
        elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        // Custom header fallback via $_SERVER
        elseif (!empty($_SERVER['HTTP_X_AUTH_TOKEN']))       $auth = 'Bearer ' . $_SERVER['HTTP_X_AUTH_TOKEN'];
    }

    if ($auth && preg_match('/Bearer\s+(.*)$/i', $auth, $m)) {
        return trim($m[1]);
    }
    return null;
}

// Helper function to call the Groq API (OpenAI-compatible chat completions)
// https://console.groq.com/docs/api-reference#chat-create
function call_groq_api($requestData, $debug = false) {
    $apiKey = GROQ_API_KEY;

    if ($debug) {
        echo "[DEBUG] Calling Groq API: " . GROQ_API_BASE . "\n";
    }

    if (!$apiKey) {
        return [
            'response' => json_encode(['error' => ['message' => 'GROQ_API_KEY is not set. Copy api/config.local.php.example to api/config.local.php and add your key from https://console.groq.com/keys']]),
            'httpCode' => 0,
            'curlError' => 'Missing GROQ_API_KEY'
        ];
    }

    $ch = curl_init(GROQ_API_BASE);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($requestData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    // curl_close() is a no-op as of PHP 8.0 (deprecated in 8.5) — handles are
    // garbage collected automatically, so it's intentionally omitted here.

    if ($debug) {
        echo "[DEBUG] HTTP Code: {$httpCode}\n";
        if ($curlError) {
            echo "[DEBUG] cURL Error: {$curlError}\n";
        }
        if ($httpCode !== 200) {
            echo "[DEBUG] Response: " . substr($response, 0, 500) . "\n";
        }
    }

    return [
        'response' => $response,
        'httpCode' => $httpCode,
        'curlError' => $curlError
    ];
}

// Function to generate complete HTML + CSS resume using the Groq API
function generate_resume_html_css_with_groq($name, $field, $education = null, $skills = null, $experience = null, $tone = 'professional', $template = null, $debug = false) {
    // Enable debug if running from CLI
    if (php_sapi_name() === 'cli') {
        $debug = true;
    }

    if ($debug) {
        echo "\n=== Groq API Debug Mode (HTML+CSS Generation) ===\n";
        echo "Name: {$name}\n";
        echo "Field: {$field}\n";
        echo "Tone: {$tone}\n";
        echo "Template: " . ($template ?? 'none') . "\n\n";
    }

    // List of Groq models to try (in order of preference).
    // See https://console.groq.com/docs/models for the current catalog —
    // this list intentionally has fallbacks since hosted model availability
    // changes over time.
    $models = [
        'openai/gpt-oss-120b',     // strong structured-output quality
        'llama-3.3-70b-versatile', // good general fallback
        'openai/gpt-oss-20b',      // smaller/faster fallback
        'llama-3.1-8b-instant',    // fastest, last resort
    ];
    
    // Build comprehensive, highly constrained prompt for single-page resume HTML + CSS generation
    $prompt = "You are a professional web design assistant specialized in generating clean, single-page resume layouts optimized for PDF conversion.\n\n";
    
    $prompt .= "**TASK:**\n";
    $prompt .= "Generate the complete, well-formatted HTML and CSS code for a single-page professional resume. The design must be modern, highly readable, and suitable for printing on a single A4 or Letter-sized page when converted to PDF.\n\n";
    
    $prompt .= "**RESUME INFORMATION:**\n";
    $prompt .= "- Name: {$name}\n";
    $prompt .= "- Position/Field: {$field}\n";
    
    if ($education) {
        $prompt .= "- Education: {$education}\n";
    }
    
    if ($skills) {
        $prompt .= "- Skills: {$skills}\n";
    }
    
    if ($experience) {
        $prompt .= "- Work Experience: {$experience}\n";
    }
    
    $prompt .= "- Tone: {$tone}\n";
    if ($template) {
        $prompt .= "- Template Style: {$template}\n";
    }
    
    $prompt .= "\n\n**CRITICAL CONSTRAINTS:**\n\n";
    
    $prompt .= "1. **Output Format:**\n";
    $prompt .= "   - Provide ONLY the raw HTML and CSS code\n";
    $prompt .= "   - The CSS MUST be embedded within <style> tags in the <head> of the HTML document\n";
    $prompt .= "   - Output must start with <!DOCTYPE html> and include complete <html>, <head>, <style>, and <body> tags\n";
    $prompt .= "   - Do NOT include any markdown code blocks (```html or ```)\n";
    $prompt .= "   - Do NOT include any explanations, comments, or additional text outside the HTML\n";
    $prompt .= "   - Use the actual name/contact data provided - ABSOLUTELY NO bracket placeholders like [Your Name], [Your Email], [City, State], etc. (Note: this does not apply to Work Experience/Education — see Content Quality rules below for what to do when those are missing.)\n\n";
    
    $prompt .= "2. **Single-Page Layout (CRITICAL):**\n";
    $prompt .= "   - The ENTIRE resume must fit onto ONE single page when printed or converted to PDF\n";
    $prompt .= "   - Use CSS to ensure content fits within A4 (210mm x 297mm) or Letter (8.5\" x 11\") dimensions\n";
    $prompt .= "   - Set appropriate font sizes (typically 10-12px for body, 14-16px for headings) to ensure single-page fit\n";
    $prompt .= "   - Use efficient spacing - avoid excessive padding/margins\n";
    $prompt .= "   - Implement CSS print styles using @media print and @page rules\n";
    $prompt .= "   - Set @page { size: A4; margin: 1cm; } to ensure proper page sizing\n";
    $prompt .= "   - Use page-break-inside: avoid for sections to prevent awkward breaks\n\n";
    
    $prompt .= "3. **Required Resume Sections (in order):**\n";
    $prompt .= "   - **Header Section**: Name (large, prominent) and current Position/Title\n";
    $prompt .= "   - **Contact Information**: Phone, Email, LinkedIn (if provided, format as a single line or compact grid)\n";
    $prompt .= "   - **Professional Summary/Objective**: 2-3 compelling sentences (concise, impactful)\n";
    $prompt .= "   - **Work Experience**: For each role include Job Title, Company Name, Dates (formatted as 'MM/YYYY - MM/YYYY' or 'MMM YYYY - Present'), and 2-3 achievement bullet points per role\n";
    $prompt .= "   - **Achievements section** (if '[Achievements & Experience Gained]' appears in the experience input): Create a dedicated 'Achievements' section. For each achievement bullet, show the achievement on one line, then a short italicised or indented description line of what was gained/learned from it (1 sentence max). This should feel like: '• Achievement title — Brief description of experience or skills gained.'\n";
    $prompt .= "   - **Education**: Degree, Institution, Graduation Year (if provided)\n";
    $prompt .= "   - **Skills**: Categorized if possible (e.g., Technical Skills, Languages, Certifications), formatted as a clean list or grid\n\n";
    
    $prompt .= "4. **No External Assets (CRITICAL):**\n";
    $prompt .= "   - Do NOT include any external fonts, images, JavaScript libraries, or links to external stylesheets\n";
    $prompt .= "   - Use ONLY web-safe fonts: Arial, Helvetica, 'Times New Roman', Georgia, 'Courier New', Verdana, sans-serif, serif, monospace\n";
    $prompt .= "   - Do NOT use Google Fonts, Adobe Fonts, or any @import statements for fonts\n";
    $prompt .= "   - Do NOT include any <img> tags, icons from icon fonts, or external images\n";
    $prompt .= "   - All styling must be self-contained within the <style> tag\n\n";
    
    $prompt .= "5. **CSS Styling Requirements:**\n";
    $prompt .= "   - Modern, clean, and highly professional appearance\n";
    $prompt .= "   - ATS-friendly (readable by applicant tracking systems) - avoid complex layouts that may confuse ATS parsers\n";
    $prompt .= "   - Ensure proper visual hierarchy with clear section headers\n";
    $prompt .= "   - Optimal line-height (1.5-1.8) and letter-spacing for readability\n";
    $prompt .= "   - Bullet points for lists should be clean and professional\n\n";
    
    // Add detailed template-specific styling instructions
    if ($template) {
        $templateLower = strtolower($template);
        $prompt .= "**TEMPLATE-SPECIFIC STYLING (MANDATORY - Follow exactly):**\n";
        $prompt .= "You MUST use the following styling specifications for the {$template} template:\n\n";
        
        if ($templateLower === 'classic') {
            $prompt .= "CLASSIC TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Georgia, Times New Roman, serif (use serif fonts)\n";
            $prompt .= "- Main Container: White background (#ffffff), max-width 800px, padding 50px, subtle border (#e0e0e0), light shadow\n";
            $prompt .= "- H1 (Name): Font-size 28-32px, color #2c3e50, bold, border-bottom 3px solid #2c3e50, padding-bottom 15px\n";
            $prompt .= "- H2 (Section Headers): Font-size 20-22px, color #34495e, font-weight 600, border-bottom 2px solid #7f8c8d, padding-bottom 8px, margin-top 30px\n";
            $prompt .= "- Resume Sections: Light gray background (#fafafa), padding 20px, border-left 4px solid #3498db, border-radius 4px, margin-bottom 25px\n";
            $prompt .= "- Text Color: #2c3e50 for body text, line-height 1.8\n";
            $prompt .= "- Layout: Single-column, traditional, conservative design\n";
            $prompt .= "- Color Scheme: Blues (#2c3e50, #3498db, #34495e) and grays (#7f8c8d)\n\n";
        } elseif ($templateLower === 'modern') {
            $prompt .= "MODERN TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Segoe UI, Arial, Helvetica, sans-serif (modern sans-serif)\n";
            $prompt .= "- Main Container: White background, max-width 850px, padding 60px, modern shadow\n";
            $prompt .= "- H1 (Name): Font-size 32-36px, color #667eea, font-weight 300, letter-spacing 2px, border-left 5px solid #667eea, padding-left 20px\n";
            $prompt .= "- H2 (Section Headers): Font-size 18-20px, color #764ba2, font-weight 600, text-transform uppercase, letter-spacing 2px, margin-top 35px\n";
            $prompt .= "- Resume Sections: Light background (#f8f9fa), padding 25px, border-radius 8px, subtle shadow, margin-bottom 30px\n";
            $prompt .= "- Text Color: #555 for body text, line-height 1.9\n";
            $prompt .= "- Layout: Contemporary, sleek, balanced whitespace, emphasis on skills\n";
            $prompt .= "- Color Scheme: Purple gradient colors (#667eea, #764ba2)\n\n";
        } elseif ($templateLower === 'professional') {
            $prompt .= "PROFESSIONAL TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Calibri, Arial, sans-serif\n";
            $prompt .= "- Main Container: White background, max-width 800px, padding 45px, border 2px solid #1a1a1a\n";
            $prompt .= "- H1 (Name): Font-size 28-30px, color #1a1a1a, text-align center, border-top and border-bottom 3px solid #1a1a1a, padding 15px 0, bold\n";
            $prompt .= "- H2 (Section Headers): Font-size 18-20px, color #1a1a1a, background #f0f0f0, padding 10px 15px, border-left 5px solid #1a1a1a, font-weight 600\n";
            $prompt .= "- Resume Sections: Padding 20px, margin-bottom 25px, no background color (clean)\n";
            $prompt .= "- Text Color: #333 for body text, line-height 1.7\n";
            $prompt .= "- Layout: Corporate-standard, centered header, strong emphasis on qualifications\n";
            $prompt .= "- Color Scheme: Black (#1a1a1a) and grays (#f0f0f0, #333)\n\n";
        } elseif ($templateLower === 'creative') {
            $prompt .= "CREATIVE TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Trebuchet MS, Arial, sans-serif (avoid Comic Sans, use Trebuchet MS instead)\n";
            $prompt .= "- Main Container: White background, max-width 850px, padding 50px, creative shadow\n";
            $prompt .= "- H1 (Name): Font-size 30-34px, color #ff6b6b, text-align center, border 3px dashed #4ecdc4, padding 20px, border-radius 15px, background #fff5f5\n";
            $prompt .= "- H2 (Section Headers): Font-size 20-22px, background gradient from #ff6b6b to #4ecdc4, color white, padding 12px 20px, border-radius 25px, display inline-block, font-weight bold\n";
            $prompt .= "- Resume Sections: Light green background (#f0fff4), padding 25px, border-radius 15px, border 2px solid #4ecdc4, margin-bottom 30px\n";
            $prompt .= "- Text Color: #2c3e50 for body text, line-height 1.8\n";
            $prompt .= "- Layout: Unique, eye-catching, emphasis on portfolio and creative achievements\n";
            $prompt .= "- Color Scheme: Red (#ff6b6b) and teal (#4ecdc4)\n\n";
        } elseif ($templateLower === 'clean') {
            $prompt .= "CLEAN TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Helvetica Neue, Arial, sans-serif\n";
            $prompt .= "- Main Container: White background, max-width 800px, padding 40px, subtle border (#e8e8e8)\n";
            $prompt .= "- H1 (Name): Font-size 26-28px, color #2c3e50, font-weight 300, letter-spacing 3px\n";
            $prompt .= "- H2 (Section Headers): Font-size 16-18px, color #7f8c8d, font-weight 400, text-transform uppercase, letter-spacing 4px, border-bottom 1px solid #ecf0f1, padding-bottom 10px\n";
            $prompt .= "- Resume Sections: Padding 20px 0, margin-bottom 30px, no background (minimalist)\n";
            $prompt .= "- Text Color: #34495e for body text, line-height 1.9\n";
            $prompt .= "- Layout: Minimalist, maximum readability, simple uncluttered, focus on content\n";
            $prompt .= "- Color Scheme: Dark grays (#2c3e50, #34495e, #7f8c8d) with minimal accents\n\n";
        } elseif ($templateLower === 'profile') {
            $prompt .= "PROFILE TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Garamond, Georgia, serif (elegant serif)\n";
            $prompt .= "- Main Container: White background, max-width 800px, padding 50px, border-top 5px solid #e74c3c, elegant shadow\n";
            $prompt .= "- H1 (Name): Font-size 28-30px, color #e74c3c, text-align center, padding 20px, background #fff5f5, border-radius 10px, font-weight bold\n";
            $prompt .= "- H2 (Section Headers): Font-size 19-21px, color #c0392b, border-left 4px solid #e74c3c, padding-left 15px, font-weight 600\n";
            $prompt .= "- Resume Sections: Light background (#fefefe), padding 25px, border-radius 8px, subtle border (#f0f0f0), margin-bottom 30px\n";
            $prompt .= "- Text Color: #2c3e50 for body text, line-height 1.8\n";
            $prompt .= "- Layout: Prominent profile/summary section at top, strong personal branding emphasis\n";
            $prompt .= "- Color Scheme: Red (#e74c3c, #c0392b) with elegant styling\n\n";
        } elseif ($templateLower === 'simple') {
            $prompt .= "SIMPLE TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Verdana, Arial, sans-serif\n";
            $prompt .= "- Main Container: White background, max-width 750px, padding 35px, border 2px solid #95a5a6\n";
            $prompt .= "- H1 (Name): Font-size 24-26px, color #34495e, border-bottom 2px solid #95a5a6, padding-bottom 12px, font-weight bold\n";
            $prompt .= "- H2 (Section Headers): Font-size 17-19px, color #34495e, background #ecf0f1, padding 8px 12px, font-weight 600\n";
            $prompt .= "- Resume Sections: Padding 15px 0, margin-bottom 20px, no background (straightforward)\n";
            $prompt .= "- Text Color: #2c3e50 for body text, line-height 1.7\n";
            $prompt .= "- Layout: Straightforward, no-frills, essential information only, clear section divisions\n";
            $prompt .= "- Color Scheme: Grays (#34495e, #95a5a6, #ecf0f1)\n\n";
        } elseif ($templateLower === 'two-column') {
            $prompt .= "TWO-COLUMN TEMPLATE SPECIFICATIONS:\n";
            $prompt .= "- Font Family: Arial, sans-serif\n";
            $prompt .= "- Main Container: White background, max-width 900px, padding 40px, CSS Grid layout: grid-template-columns: 250px 1fr, gap 30px\n";
            $prompt .= "- H1 (Name): Font-size 22-24px, color #2c3e50, grid-column: 1 / -1 (spans both columns), border-bottom 3px solid #3498db, padding-bottom 15px, font-weight bold\n";
            $prompt .= "- H2 (Section Headers): Font-size 16-18px, color #3498db, font-weight 600, border-bottom 2px solid #3498db, padding-bottom 8px\n";
            $prompt .= "- Left Column: Background #f8f9fa, padding 20px, border-radius 5px (for contact info, skills, education)\n";
            $prompt .= "- Right Column: Padding 0 20px (for work experience, summary)\n";
            $prompt .= "- Resume Sections: Margin-bottom 25px\n";
            $prompt .= "- Text Color: #2c3e50 for body text, line-height 1.7\n";
            $prompt .= "- Layout: Two-column grid, left for contact/skills/education, right for experience/summary\n";
            $prompt .= "- Color Scheme: Blues (#3498db, #2c3e50) with gray background for left column\n\n";
        } else {
            $prompt .= "CUSTOM TEMPLATE: Follow a professional, modern design with appropriate colors, typography, and layout that matches the '{$template}' template style.\n\n";
        }
    } else {
        $prompt .= "**TEMPLATE-SPECIFIC STYLING:**\n";
        $prompt .= "No specific template selected. Use a professional, clean design with blues (#2c3e50, #3498db) and grays (#34495e, #7f8c8d) as primary colors.\n\n";
    }
    
    $prompt .= "6. **Print/PDF Optimization (ESSENTIAL):**\n";
    $prompt .= "   - Include @media print styles to ensure proper rendering when printing or converting to PDF\n";
    $prompt .= "   - Set background colors to white (#ffffff) in print mode\n";
    $prompt .= "   - Remove or hide any decorative elements that don't contribute to content in print view\n";
    $prompt .= "   - Ensure text is black or dark gray for optimal readability when printed\n";
    $prompt .= "   - Use page-break-inside: avoid on sections to prevent content from splitting across pages\n";
    $prompt .= "   - Test that all content fits within page margins (1cm on all sides)\n\n";
    
    $prompt .= "7. **Content Quality (CRITICAL - NO FABRICATION):**\n";
    $prompt .= "   - Use the ACTUAL data provided above exactly as given. Do not invent facts.\n";
    $prompt .= "   - If Work Experience is missing or empty: do NOT invent a company name, job title, dates, or achievements. Instead, write a single honest placeholder line such as 'Add your work experience here — e.g. job title, company, dates, and 2-3 achievements.' Never present invented employment history as real.\n";
    $prompt .= "   - If Education is missing or empty: do NOT invent a specific school, degree, or graduation year. Use a placeholder line such as 'Add your education here.'\n";
    $prompt .= "   - If Skills is missing or empty: you may suggest a short, clearly generic list of skills commonly associated with the {$field} role (no fabricated certifications or tools you can't justify from the role alone).\n";
    $prompt .= "   - The Professional Summary may be written generically based on the job title/tone since it does not assert specific unverifiable facts (no specific employers, dates, or numbers).\n";
    $prompt .= "   - When real data IS provided, use it exactly, with action verbs and quantifiable achievements where the user's own input supports it.\n";
    $prompt .= "   - Keep each section concise but impactful to fit on one page\n\n";
    
    $prompt .= "**OUTPUT:**\n";
    $prompt .= "Generate the complete HTML resume code now. Output ONLY the raw HTML code starting with <!DOCTYPE html> - no explanations, no markdown, no code blocks, just pure HTML with embedded CSS.\n";
    if ($template) {
        $prompt .= "\n**REMINDER:** It is CRITICAL that you follow the {$template} template specifications exactly as outlined above. The CSS styling MUST match the template's color scheme, typography, layout, and visual design characteristics. Do NOT use generic styling - the resume must look like it was designed specifically for the {$template} template.\n";
    }
    
    // Prepare request data (OpenAI-compatible chat completions body, used by Groq)
    $baseRequestData = [
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.5, // Lower temperature for more consistent, structured output
        'top_p' => 0.95,
        'max_tokens' => 8192,
    ];

    // Try each model until one works
    $lastError = null;
    if ($debug) {
        echo "Trying Groq models for HTML+CSS generation...\n";
    }

    foreach ($models as $model) {
        if ($debug) {
            echo "\n[TRYING] Model: {$model}\n";
        }

        $requestData = $baseRequestData;
        $requestData['model'] = $model;

        $result = call_groq_api($requestData, $debug);

        if ($result['curlError']) {
            if ($debug) {
                echo "[ERROR] cURL Error: {$result['curlError']}\n";
            }
            $lastError = ['error' => 'API request failed: ' . $result['curlError']];
            continue;
        }

        if ($result['httpCode'] === 200) {
            $responseData = json_decode($result['response'], true);
            if ($responseData && isset($responseData['choices'][0]['message']['content'])) {
                $generatedHtml = $responseData['choices'][0]['message']['content'];

                // Clean up the response - remove markdown code blocks if present
                $generatedHtml = preg_replace('/```html\s*/i', '', $generatedHtml);
                $generatedHtml = preg_replace('/```\s*/', '', $generatedHtml);
                $generatedHtml = trim($generatedHtml);
                
                // Check if HTML is complete - validate structure
                $hasBody = stripos($generatedHtml, '<body') !== false;
                $hasClosingBody = stripos($generatedHtml, '</body>') !== false;
                $hasClosingHtml = stripos($generatedHtml, '</html>') !== false;
                
                // If HTML is incomplete (missing closing tags), try to fix it
                if ($hasBody && (!$hasClosingBody || !$hasClosingHtml)) {
                    if ($debug) {
                        echo "[WARNING] HTML appears incomplete. Attempting to fix...\n";
                    }
                    
                    // Find body tag position
                    $bodyStart = stripos($generatedHtml, '<body');
                    if ($bodyStart !== false) {
                        $bodyTagEnd = strpos($generatedHtml, '>', $bodyStart);
                        if ($bodyTagEnd !== false) {
                            // Check if we have content after body tag
                            $afterBody = substr($generatedHtml, $bodyTagEnd + 1);
                            
                            // If no closing body tag, try to add it before the end
                            if (!$hasClosingBody) {
                                // Check if there's a </style> tag (might be in wrong place)
                                $styleEnd = strripos($generatedHtml, '</style>');
                                if ($styleEnd !== false && $styleEnd > $bodyTagEnd) {
                                    // Style tag is after body, which is wrong - need to fix structure
                                    $beforeBody = substr($generatedHtml, 0, $bodyTagEnd + 1);
                                    $afterStyle = substr($generatedHtml, $styleEnd + 7);
                                    // Reconstruct: body should contain the resume content
                                    $generatedHtml = $beforeBody . '<div class="resume-container"><h1>Resume Content</h1><p>Content generation may have been incomplete. Please regenerate.</p></div>' . '</body></html>';
                                } else {
                                    // Just add closing tags
                                    $generatedHtml .= '</body></html>';
                                }
                            } else if (!$hasClosingHtml) {
                                $generatedHtml .= '</html>';
                            }
                        }
                    } else {
                        // No body tag at all - might just be CSS or incomplete
                        // Try to wrap it properly
                        if (stripos($generatedHtml, '<style>') !== false || stripos($generatedHtml, '<style ') !== false) {
                            // Has style tag but no body - reconstruct
                            $htmlStart = stripos($generatedHtml, '<!DOCTYPE');
                            if ($htmlStart === false) {
                                $generatedHtml = '<!DOCTYPE html><html><head><meta charset="UTF-8">' . $generatedHtml;
                            }
                            if (stripos($generatedHtml, '</head>') === false) {
                                $generatedHtml = str_replace('</style>', '</style></head>', $generatedHtml);
                            }
                            if (!stripos($generatedHtml, '<body')) {
                                $generatedHtml .= '<body><div class="resume-container"><h1>Resume</h1><p>Content loading...</p></div></body></html>';
                            }
                        }
                    }
                }
                
                if ($debug) {
                    echo "[SUCCESS] Model {$model} worked! Generated HTML length: " . strlen($generatedHtml) . " chars\n";
                    echo "[INFO] HTML completeness - Has Body: " . ($hasBody ? 'yes' : 'no') . ", Has Closing Body: " . ($hasClosingBody ? 'yes' : 'fixed') . ", Has Closing HTML: " . ($hasClosingHtml ? 'yes' : 'fixed') . "\n";
                }
                return ['success' => true, 'html' => $generatedHtml];
            }
        } else {
            // Parse error for better debugging
            $errorData = json_decode($result['response'], true);
            $errorMsg = "Model {$model} returned status code: {$result['httpCode']}";
            if ($errorData && isset($errorData['error']['message'])) {
                $errorMsg .= ' - ' . $errorData['error']['message'];

                // Check for quota/credit limit errors
                $errorMessage = strtolower($errorData['error']['message']);
                if (strpos($errorMessage, 'quota') !== false ||
                    strpos($errorMessage, 'rate limit') !== false ||
                    strpos($errorMessage, 'exceeded') !== false ||
                    strpos($errorMessage, 'billing') !== false) {
                    $errorMsg = 'QUOTA/RATE LIMIT REACHED: ' . $errorData['error']['message'] .
                               '. Check your usage at https://console.groq.com/settings/billing.';
                }
            }
            if ($debug) {
                echo "[FAILED] {$errorMsg}\n";
            }
            $lastError = ['error' => $errorMsg, 'response' => $result['response']];
        }
    }

    // If all models failed, return last error
    return $lastError ?: ['error' => 'All API models failed. Please check your API key and model availability.'];
}

// Legacy function for backward compatibility (generates text only)
function generate_resume_with_groq($field, $education = null, $skills = null, $experience = null, $tone = 'professional', $template = null, $debug = false) {
    // Enable debug if running from CLI
    if (php_sapi_name() === 'cli') {
        $debug = true;
    }

    if ($debug) {
        echo "\n=== Groq API Debug Mode ===\n";
        echo "Field: {$field}\n";
        echo "Tone: {$tone}\n";
        echo "Template: " . ($template ?? 'none') . "\n\n";
    }

    // List of Groq models to try (in order of preference)
    $models = [
        'openai/gpt-oss-120b',
        'llama-3.3-70b-versatile',
        'openai/gpt-oss-20b',
        'llama-3.1-8b-instant',
    ];
    
    // Template-specific instructions for the model
    $templateInstructions = '';
    if ($template) {
        $templateLower = strtolower($template);
        switch ($templateLower) {
            case 'classic':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the CLASSIC template design. The Classic template features:\n";
                $templateInstructions .= "- Traditional, conservative layout with clear section headers\n";
                $templateInstructions .= "- Single-column format with well-defined sections\n";
                $templateInstructions .= "- Professional typography and spacing\n";
                $templateInstructions .= "- Emphasis on chronological work experience\n";
                $templateInstructions .= "- Clean, formal structure suitable for traditional industries\n";
                break;
            case 'modern':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the MODERN template design. The Modern template features:\n";
                $templateInstructions .= "- Contemporary, sleek layout with modern typography\n";
                $templateInstructions .= "- Balanced use of whitespace and visual hierarchy\n";
                $templateInstructions .= "- Emphasis on skills and achievements\n";
                $templateInstructions .= "- Clean, minimalist design with subtle accents\n";
                $templateInstructions .= "- Suitable for tech, creative, and forward-thinking industries\n";
                break;
            case 'profile':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the PROFILE template design. The Profile template features:\n";
                $templateInstructions .= "- Prominent profile/summary section at the top\n";
                $templateInstructions .= "- Strong emphasis on personal branding and professional summary\n";
                $templateInstructions .= "- Well-organized sections highlighting key qualifications\n";
                $templateInstructions .= "- Professional yet personable tone\n";
                $templateInstructions .= "- Ideal for roles requiring strong interpersonal skills\n";
                break;
            case 'professional':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the PROFESSIONAL template design. The Professional template features:\n";
                $templateInstructions .= "- Corporate-standard layout with clear structure\n";
                $templateInstructions .= "- Strong emphasis on qualifications and certifications\n";
                $templateInstructions .= "- Detailed work history with achievements\n";
                $templateInstructions .= "- ATS-optimized format\n";
                $templateInstructions .= "- Perfect for corporate, finance, and consulting roles\n";
                break;
            case 'clean':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the CLEAN template design. The Clean template features:\n";
                $templateInstructions .= "- Minimalist design with maximum readability\n";
                $templateInstructions .= "- Simple, uncluttered layout\n";
                $templateInstructions .= "- Focus on content over design elements\n";
                $templateInstructions .= "- Easy to scan and read quickly\n";
                $templateInstructions .= "- Great for ATS systems and quick reviews\n";
                break;
            case 'creative':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the CREATIVE template design. The Creative template features:\n";
                $templateInstructions .= "- Unique, eye-catching layout that showcases creativity\n";
                $templateInstructions .= "- Emphasis on portfolio, projects, and creative achievements\n";
                $templateInstructions .= "- Less traditional structure, more visual storytelling\n";
                $templateInstructions .= "- Highlights innovation and artistic skills\n";
                $templateInstructions .= "- Perfect for design, marketing, and creative industries\n";
                break;
            case 'simple':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the SIMPLE template design. The Simple template features:\n";
                $templateInstructions .= "- Straightforward, no-frills layout\n";
                $templateInstructions .= "- Essential information only, easy to read\n";
                $templateInstructions .= "- Clear section divisions\n";
                $templateInstructions .= "- Quick to understand at a glance\n";
                $templateInstructions .= "- Suitable for entry-level positions and straightforward applications\n";
                break;
            case 'two-column':
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the TWO-COLUMN template design. The Two-Column template features:\n";
                $templateInstructions .= "- Two-column layout maximizing space efficiency\n";
                $templateInstructions .= "- Left column typically for contact info, skills, education\n";
                $templateInstructions .= "- Right column for work experience and detailed sections\n";
                $templateInstructions .= "- Efficient use of space for comprehensive information\n";
                $templateInstructions .= "- Great for experienced professionals with extensive backgrounds\n";
                break;
            default:
                $templateInstructions = "\n\nIMPORTANT: Generate this resume following the {$template} template design style. Structure the content to match the visual design and layout characteristics of this template.\n";
        }
    }
    
    // Build prompt for resume generation
    $prompt = "Generate a professional resume for a candidate applying for the position: {$field}\n\n";
    
    if ($education) {
        $prompt .= "Education:\n{$education}\n\n";
    }
    
    if ($skills) {
        $prompt .= "Skills:\n{$skills}\n\n";
    }
    
    if ($experience) {
        $prompt .= "Work Experience:\n{$experience}\n\n";
    }
    
    $prompt .= "Tone: {$tone}\n";
    $prompt .= $templateInstructions;
    $prompt .= "\n\nCRITICAL INSTRUCTIONS FOR VISUAL RESUME GENERATION:\n";
    $prompt .= "1. Generate resume content in a CLEAR, STRUCTURED format with these exact section headers:\n";
    $prompt .= "   - Start with: 'Professional Summary' (2-3 compelling sentences, no placeholders)\n";
    $prompt .= "   - Then: 'Skills' (list each skill on a new line or use bullet format)\n";
    $prompt .= "   - Then: 'Work Experience' (for each role: Job Title, Company, Dates, and 2-3 achievement bullets)\n";
    $prompt .= "   - Finally: 'Education' (Degree, Institution, Year if provided)\n\n";
    $prompt .= "2. FORMAT REQUIREMENTS:\n";
    $prompt .= "   - Use EXACT section headers: 'Professional Summary', 'Skills', 'Work Experience', 'Education'\n";
    $prompt .= "   - Each section should start on a new line with the header\n";
    $prompt .= "   - Use bullet points (• or -) for lists in Skills and Work Experience\n";
    $prompt .= "   - Separate each work experience entry clearly\n\n";
    $prompt .= "3. CONTENT REQUIREMENTS (CRITICAL - NO FABRICATION):\n";
    $prompt .= "   - ABSOLUTELY NO placeholder text like '[Your Name Here]', '[Your Email]', '[Your Phone]', '[City, State]'\n";
    $prompt .= "   - Use REAL information from the provided data (field, education, skills, experience) exactly as given\n";
    $prompt .= "   - If Work Experience is missing: do NOT invent a company, job title, dates, or achievements — write a single honest placeholder line like 'Add your work experience here.' instead of fabricated employment history\n";
    $prompt .= "   - If Education is missing: do NOT invent a specific school or year — write 'Add your education here.' instead\n";
    $prompt .= "   - If Skills is missing: you may suggest a short generic list of skills commonly relevant to the role, clearly as suggestions, not fabricated credentials\n";
    $prompt .= "   - When real data IS provided, make it specific and detailed, not generic\n\n";
    $prompt .= "4. VISUAL DESIGN CONSIDERATIONS:\n";
    $prompt .= "   - Write content that will look professional when displayed with visual styling\n";
    $prompt .= "   - Use action verbs and quantifiable achievements where possible\n";
    $prompt .= "   - Keep each section concise but impactful\n";
    $prompt .= "   - Ensure content flows well visually when rendered with colors, borders, and styling\n\n";
    $prompt .= "5. OUTPUT FORMAT:\n";
    $prompt .= "   - Start directly with 'Professional Summary' section (no introduction text)\n";
    $prompt .= "   - Use clear line breaks between sections\n";
    $prompt .= "   - Example format:\n";
    $prompt .= "     Professional Summary\n";
    $prompt .= "     [Your summary text here]\n\n";
    $prompt .= "     Skills\n";
    $prompt .= "     • Skill 1\n";
    $prompt .= "     • Skill 2\n\n";
    $prompt .= "     Work Experience\n";
    $prompt .= "     [Job details]\n\n";
    $prompt .= "     Education\n";
    $prompt .= "     [Education details]";
    
    // Prepare request data (OpenAI-compatible chat completions body, used by Groq)
    $baseRequestData = [
        'messages' => [
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7,
        'top_p' => 0.95,
        'max_tokens' => 2048,
    ];

    // Try each model until one works
    $lastError = null;
    if ($debug) {
        echo "Trying Groq models...\n";
    }

    foreach ($models as $model) {
        if ($debug) {
            echo "\n[TRYING] Model: {$model}\n";
        }

        $requestData = $baseRequestData;
        $requestData['model'] = $model;

        $result = call_groq_api($requestData, $debug);

        if ($result['curlError']) {
            if ($debug) {
                echo "[ERROR] cURL Error: {$result['curlError']}\n";
            }
            $lastError = ['error' => 'API request failed: ' . $result['curlError']];
            continue;
        }

        if ($result['httpCode'] === 200) {
            $responseData = json_decode($result['response'], true);
            if ($responseData && isset($responseData['choices'][0]['message']['content'])) {
                if ($debug) {
                    echo "[SUCCESS] Model {$model} worked!\n";
                }
                return ['success' => true, 'content' => $responseData['choices'][0]['message']['content']];
            }
        } else {
            // Parse error for better debugging
            $errorData = json_decode($result['response'], true);
            $errorMsg = "Model {$model} returned status code: {$result['httpCode']}";
            if ($errorData && isset($errorData['error']['message'])) {
                $errorMsg .= ' - ' . $errorData['error']['message'];

                // Check for quota/rate limit errors
                $errorMessage = strtolower($errorData['error']['message']);
                if (strpos($errorMessage, 'quota') !== false ||
                    strpos($errorMessage, 'rate limit') !== false ||
                    strpos($errorMessage, 'exceeded') !== false ||
                    strpos($errorMessage, 'billing') !== false) {
                    $errorMsg = 'QUOTA/RATE LIMIT REACHED: ' . $errorData['error']['message'] .
                               '. Check your usage at https://console.groq.com/settings/billing.';
                }
            }
            if ($debug) {
                echo "[FAILED] {$errorMsg}\n";
            }
            $lastError = ['error' => $errorMsg, 'response' => $result['response']];
        }
    }

    // If all models failed, return last error
    return $lastError ?: ['error' => 'All API models failed. Please check your API key and model availability.'];
}

// Function to render resume content as HTML with template design
function render_resume_html($content, $template = 'classic', $field = '') {
    $templateLower = strtolower($template ?? 'classic');
    
    // Clean content - remove markdown formatting and placeholders
    $content = preg_replace('/\*\*(.*?)\*\*/', '$1', $content); // Remove bold
    $content = preg_replace('/\[Your (.*?) Here\]/i', '', $content); // Remove placeholders
    $content = preg_replace('/\[Your (.*?)\]/i', '', $content); // Remove more placeholders
    $content = preg_replace('/---+/', '', $content); // Remove horizontal rules
    
    // Extract sections from content (improved parsing)
    $sections = [
        'summary' => '',
        'skills' => '',
        'experience' => '',
        'education' => ''
    ];
    
    // Try to parse content sections with multiple patterns
    if (preg_match('/(?:Professional Summary|Summary)[:\s]*\n?(.*?)(?=\n(?:###|##|Skills|Work Experience|Education|$))/is', $content, $matches)) {
        $sections['summary'] = trim($matches[1]);
    }
    if (preg_match('/(?:Skills|Technical Skills|Core Competencies)[:\s]*\n?(.*?)(?=\n(?:###|##|Work Experience|Education|Professional Summary|$))/is', $content, $matches)) {
        $sections['skills'] = trim($matches[1]);
    }
    if (preg_match('/(?:Work Experience|Experience|Employment History)[:\s]*\n?(.*?)(?=\n(?:###|##|Education|Skills|Professional Summary|$))/is', $content, $matches)) {
        $sections['experience'] = trim($matches[1]);
    }
    if (preg_match('/(?:Education|Educational Background)[:\s]*\n?(.*?)(?=\n(?:###|##|Skills|Work Experience|Professional Summary|$))/is', $content, $matches)) {
        $sections['education'] = trim($matches[1]);
    }
    
    // If parsing failed, use raw content but clean it
    if (empty($sections['summary']) && empty($sections['skills']) && empty($sections['experience']) && empty($sections['education'])) {
        $sections['summary'] = $content;
    }
    
    // Template-specific CSS
    $templateCSS = '';
    $templateClass = 'resume-' . $templateLower;
    
    switch ($templateLower) {
        case 'classic':
            $templateCSS = '
                .resume-classic { 
                    font-family: "Georgia", "Times New Roman", serif; 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 50px; 
                    background: #ffffff; 
                    box-shadow: 0 0 20px rgba(0,0,0,0.1);
                    border: 1px solid #e0e0e0;
                }
                .resume-classic h1 { 
                    font-size: 32px; 
                    margin-bottom: 15px; 
                    border-bottom: 3px solid #2c3e50; 
                    padding-bottom: 15px; 
                    color: #2c3e50;
                    font-weight: bold;
                }
                .resume-classic h2 { 
                    font-size: 22px; 
                    margin-top: 30px; 
                    margin-bottom: 15px; 
                    border-bottom: 2px solid #7f8c8d; 
                    padding-bottom: 8px; 
                    color: #34495e;
                    font-weight: 600;
                }
                .resume-classic .resume-section {
                    background: #fafafa;
                    padding: 20px;
                    margin-bottom: 25px;
                    border-left: 4px solid #3498db;
                    border-radius: 4px;
                }
                .resume-classic p, .resume-classic li { 
                    line-height: 1.8; 
                    margin-bottom: 10px; 
                    color: #2c3e50;
                }
            ';
            break;
        case 'modern':
            $templateCSS = '
                .resume-modern { 
                    font-family: "Segoe UI", "Arial", "Helvetica", sans-serif; 
                    max-width: 850px; 
                    margin: 0 auto; 
                    padding: 60px; 
                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                    background: #ffffff;
                    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
                }
                .resume-modern h1 { 
                    font-size: 36px; 
                    font-weight: 300; 
                    margin-bottom: 10px; 
                    color: #667eea;
                    letter-spacing: 2px;
                    border-left: 5px solid #667eea;
                    padding-left: 20px;
                }
                .resume-modern h2 { 
                    font-size: 20px; 
                    font-weight: 600; 
                    margin-top: 35px; 
                    margin-bottom: 20px; 
                    color: #764ba2; 
                    text-transform: uppercase; 
                    letter-spacing: 2px;
                    background: linear-gradient(90deg, #667eea, #764ba2);
                    -webkit-background-clip: text;
                    -webkit-text-fill-color: transparent;
                    background-clip: text;
                }
                .resume-modern .resume-section {
                    background: #f8f9fa;
                    padding: 25px;
                    margin-bottom: 30px;
                    border-radius: 8px;
                    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
                }
                .resume-modern p, .resume-modern li { 
                    line-height: 1.9; 
                    margin-bottom: 12px; 
                    color: #555;
                }
            ';
            break;
        case 'professional':
            $templateCSS = '
                .resume-professional { 
                    font-family: "Calibri", "Arial", sans-serif; 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 45px; 
                    background: #ffffff;
                    border: 2px solid #1a1a1a;
                    box-shadow: 0 5px 25px rgba(0,0,0,0.2);
                }
                .resume-professional h1 { 
                    font-size: 30px; 
                    margin-bottom: 20px; 
                    color: #1a1a1a;
                    text-align: center;
                    border-top: 3px solid #1a1a1a;
                    border-bottom: 3px solid #1a1a1a;
                    padding: 15px 0;
                    font-weight: bold;
                }
                .resume-professional h2 { 
                    font-size: 20px; 
                    margin-top: 30px; 
                    margin-bottom: 15px; 
                    color: #1a1a1a;
                    background: #f0f0f0;
                    padding: 10px 15px;
                    border-left: 5px solid #1a1a1a;
                    font-weight: 600;
                }
                .resume-professional .resume-section {
                    padding: 20px;
                    margin-bottom: 25px;
                }
                .resume-professional p, .resume-professional li { 
                    line-height: 1.7; 
                    margin-bottom: 10px; 
                    color: #333;
                }
            ';
            break;
        case 'creative':
            $templateCSS = '
                .resume-creative { 
                    font-family: "Comic Sans MS", "Trebuchet MS", sans-serif; 
                    max-width: 850px; 
                    margin: 0 auto; 
                    padding: 50px; 
                    background: linear-gradient(45deg, #ff6b6b, #4ecdc4);
                    background: #ffffff;
                    box-shadow: 0 0 30px rgba(255,107,107,0.3);
                }
                .resume-creative h1 { 
                    font-size: 34px; 
                    margin-bottom: 15px; 
                    color: #ff6b6b;
                    text-align: center;
                    border: 3px dashed #4ecdc4;
                    padding: 20px;
                    border-radius: 15px;
                    background: #fff5f5;
                }
                .resume-creative h2 { 
                    font-size: 22px; 
                    margin-top: 30px; 
                    margin-bottom: 15px; 
                    color: #4ecdc4;
                    background: linear-gradient(90deg, #ff6b6b, #4ecdc4);
                    color: white;
                    padding: 12px 20px;
                    border-radius: 25px;
                    display: inline-block;
                    font-weight: bold;
                }
                .resume-creative .resume-section {
                    background: #f0fff4;
                    padding: 25px;
                    margin-bottom: 30px;
                    border-radius: 15px;
                    border: 2px solid #4ecdc4;
                }
                .resume-creative p, .resume-creative li { 
                    line-height: 1.8; 
                    margin-bottom: 12px; 
                    color: #2c3e50;
                }
            ';
            break;
        case 'clean':
            $templateCSS = '
                .resume-clean { 
                    font-family: "Helvetica Neue", "Arial", sans-serif; 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 40px; 
                    background: #ffffff;
                    border: 1px solid #e8e8e8;
                }
                .resume-clean h1 { 
                    font-size: 28px; 
                    margin-bottom: 20px; 
                    color: #2c3e50;
                    font-weight: 300;
                    letter-spacing: 3px;
                }
                .resume-clean h2 { 
                    font-size: 18px; 
                    margin-top: 30px; 
                    margin-bottom: 15px; 
                    color: #7f8c8d;
                    font-weight: 400;
                    text-transform: uppercase;
                    letter-spacing: 4px;
                    border-bottom: 1px solid #ecf0f1;
                    padding-bottom: 10px;
                }
                .resume-clean .resume-section {
                    padding: 20px 0;
                    margin-bottom: 30px;
                }
                .resume-clean p, .resume-clean li { 
                    line-height: 1.9; 
                    margin-bottom: 12px; 
                    color: #34495e;
                }
            ';
            break;
        case 'profile':
            $templateCSS = '
                .resume-profile { 
                    font-family: "Garamond", "Georgia", serif; 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 50px; 
                    background: #ffffff;
                    box-shadow: 0 8px 30px rgba(0,0,0,0.12);
                    border-top: 5px solid #e74c3c;
                }
                .resume-profile h1 { 
                    font-size: 30px; 
                    margin-bottom: 25px; 
                    color: #e74c3c;
                    text-align: center;
                    padding: 20px;
                    background: #fff5f5;
                    border-radius: 10px;
                    font-weight: bold;
                }
                .resume-profile h2 { 
                    font-size: 21px; 
                    margin-top: 30px; 
                    margin-bottom: 18px; 
                    color: #c0392b;
                    border-left: 4px solid #e74c3c;
                    padding-left: 15px;
                    font-weight: 600;
                }
                .resume-profile .resume-section {
                    background: #fefefe;
                    padding: 25px;
                    margin-bottom: 30px;
                    border-radius: 8px;
                    border: 1px solid #f0f0f0;
                }
                .resume-profile p, .resume-profile li { 
                    line-height: 1.8; 
                    margin-bottom: 12px; 
                    color: #2c3e50;
                }
            ';
            break;
        case 'simple':
            $templateCSS = '
                .resume-simple { 
                    font-family: "Verdana", "Arial", sans-serif; 
                    max-width: 750px; 
                    margin: 0 auto; 
                    padding: 35px; 
                    background: #ffffff;
                    border: 2px solid #95a5a6;
                }
                .resume-simple h1 { 
                    font-size: 26px; 
                    margin-bottom: 20px; 
                    color: #34495e;
                    border-bottom: 2px solid #95a5a6;
                    padding-bottom: 12px;
                    font-weight: bold;
                }
                .resume-simple h2 { 
                    font-size: 19px; 
                    margin-top: 25px; 
                    margin-bottom: 12px; 
                    color: #34495e;
                    background: #ecf0f1;
                    padding: 8px 12px;
                    font-weight: 600;
                }
                .resume-simple .resume-section {
                    padding: 15px 0;
                    margin-bottom: 20px;
                }
                .resume-simple p, .resume-simple li { 
                    line-height: 1.7; 
                    margin-bottom: 10px; 
                    color: #2c3e50;
                }
            ';
            break;
        case 'two-column':
            $templateCSS = '
                .resume-two-column { 
                    font-family: "Arial", sans-serif; 
                    max-width: 900px; 
                    margin: 0 auto; 
                    padding: 40px; 
                    background: #ffffff;
                    box-shadow: 0 0 25px rgba(0,0,0,0.1);
                    display: grid;
                    grid-template-columns: 250px 1fr;
                    gap: 30px;
                }
                .resume-two-column h1 { 
                    font-size: 24px; 
                    margin-bottom: 20px; 
                    color: #2c3e50;
                    grid-column: 1 / -1;
                    border-bottom: 3px solid #3498db;
                    padding-bottom: 15px;
                    font-weight: bold;
                }
                .resume-two-column h2 { 
                    font-size: 18px; 
                    margin-top: 25px; 
                    margin-bottom: 15px; 
                    color: #3498db;
                    font-weight: 600;
                    border-bottom: 2px solid #3498db;
                    padding-bottom: 8px;
                }
                .resume-two-column .resume-section {
                    margin-bottom: 25px;
                }
                .resume-two-column .left-column {
                    background: #f8f9fa;
                    padding: 20px;
                    border-radius: 5px;
                }
                .resume-two-column .right-column {
                    padding: 0 20px;
                }
                .resume-two-column p, .resume-two-column li { 
                    line-height: 1.7; 
                    margin-bottom: 10px; 
                    color: #2c3e50;
                }
            ';
            break;
        default:
            $templateCSS = '
                .resume-' . $templateLower . ' { 
                    font-family: "Arial", sans-serif; 
                    max-width: 800px; 
                    margin: 0 auto; 
                    padding: 40px; 
                    background: #ffffff;
                    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
                    border-radius: 8px;
                }
                .resume-' . $templateLower . ' h1 { 
                    font-size: 28px; 
                    margin-bottom: 15px; 
                    color: #2c3e50;
                    border-bottom: 2px solid #3498db;
                    padding-bottom: 10px;
                }
                .resume-' . $templateLower . ' h2 { 
                    font-size: 20px; 
                    margin-top: 25px; 
                    margin-bottom: 15px; 
                    color: #34495e;
                    background: #ecf0f1;
                    padding: 10px 15px;
                    border-radius: 5px;
                }
                .resume-' . $templateLower . ' .resume-section {
                    padding: 20px;
                    margin-bottom: 25px;
                    background: #f8f9fa;
                    border-radius: 5px;
                }
                .resume-' . $templateLower . ' p, .resume-' . $templateLower . ' li { 
                    line-height: 1.7; 
                    margin-bottom: 10px; 
                    color: #2c3e50;
                }
            ';
    }
    
    $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume - ' . htmlspecialchars($field) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; padding: 20px; }
        ' . $templateCSS . '
        .resume-section { 
            margin-bottom: 25px; 
            transition: transform 0.2s;
        }
        .resume-section:hover {
            transform: translateX(5px);
        }
        .resume-section h2 { 
            color: #2c3e50; 
            margin-bottom: 15px;
        }
        ul { 
            list-style: none; 
            margin-left: 0; 
            margin-top: 10px; 
            padding-left: 0;
        }
        ul li { 
            margin-bottom: 10px; 
            padding-left: 25px;
            position: relative;
        }
        ul li:before {
            content: "▸";
            position: absolute;
            left: 0;
            color: #3498db;
            font-weight: bold;
        }
        p { 
            text-align: left; 
            margin-bottom: 12px;
        }
        strong {
            color: #2c3e50;
            font-weight: 600;
        }
        @media print {
            body { background: #fff; padding: 0; }
            .resume-' . $templateLower . ' { box-shadow: none; page-break-inside: avoid; }
            .resume-section { page-break-inside: avoid; }
        }
        @page { size: A4; margin: 1cm; }
        .print-btn, .download-img-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 1000; margin-right: 10px; }
        .download-img-btn { right: 150px; background: #28a745; }
        .print-btn:hover { background: #0056b3; }
        .download-img-btn:hover { background: #218838; }
        @media print {
            .print-btn, .download-img-btn { display: none; }
        }
    </style>
    <script>
        function printResume() {
            window.print();
        }
    </script>
</head>
<body>
    <button class="print-btn" onclick="printResume()">Print / Save as PDF</button>
    <div class="' . $templateClass . '">
        <h1>' . htmlspecialchars($field) . '</h1>';
    
    if (!empty($sections['summary'])) {
        $html .= '<div class="resume-section">
            <h2>Professional Summary</h2>
            <p>' . nl2br(htmlspecialchars($sections['summary'])) . '</p>
        </div>';
    }
    
    if (!empty($sections['skills'])) {
        $html .= '<div class="resume-section">
            <h2>Skills</h2>
            <p>' . nl2br(htmlspecialchars($sections['skills'])) . '</p>
        </div>';
    }
    
    if (!empty($sections['experience'])) {
        $html .= '<div class="resume-section">
            <h2>Work Experience</h2>
            <p>' . nl2br(htmlspecialchars($sections['experience'])) . '</p>
        </div>';
    }
    
    if (!empty($sections['education'])) {
        $html .= '<div class="resume-section">
            <h2>Education</h2>
            <p>' . nl2br(htmlspecialchars($sections['education'])) . '</p>
        </div>';
    }
    
    // If sections are empty, show full content
    if (empty($sections['summary']) && empty($sections['skills']) && empty($sections['experience']) && empty($sections['education'])) {
        $html .= '<div class="resume-section">
            <div>' . nl2br(htmlspecialchars($content)) . '</div>
        </div>';
    }
    
    // Special handling for two-column template - rebuild HTML structure
    if ($templateLower === 'two-column') {
        $leftSections = ['skills', 'education'];
        $rightSections = ['summary', 'experience'];
        
        $leftContent = '';
        $rightContent = '';
        
        foreach ($sections as $key => $content) {
            if (!empty($content)) {
                $sectionTitle = '';
                switch($key) {
                    case 'summary': $sectionTitle = 'Professional Summary'; break;
                    case 'skills': $sectionTitle = 'Skills'; break;
                    case 'experience': $sectionTitle = 'Work Experience'; break;
                    case 'education': $sectionTitle = 'Education'; break;
                    default: $sectionTitle = ucwords(str_replace('_', ' ', $key));
                }
                
                $sectionHtml = '<div class="resume-section">
                    <h2>' . htmlspecialchars($sectionTitle) . '</h2>
                    <p>' . nl2br(htmlspecialchars($content)) . '</p>
                </div>';
                
                if (in_array($key, $leftSections)) {
                    $leftContent .= $sectionHtml;
                } else {
                    $rightContent .= $sectionHtml;
                }
            }
        }
        
        // Rebuild HTML for two-column layout
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resume - ' . htmlspecialchars($field) . '</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f5f5; padding: 20px; }
        ' . $templateCSS . '
        .resume-section { margin-bottom: 25px; }
        ul { list-style: none; margin-left: 0; margin-top: 10px; padding-left: 0; }
        ul li { margin-bottom: 10px; padding-left: 25px; position: relative; }
        ul li:before { content: "▸"; position: absolute; left: 0; color: #3498db; font-weight: bold; }
        p { text-align: left; margin-bottom: 12px; }
        strong { color: #2c3e50; font-weight: 600; }
        @media print {
            body { background: #fff; padding: 0; }
            .resume-' . $templateLower . ' { box-shadow: none; page-break-inside: avoid; }
            .resume-section { page-break-inside: avoid; }
        }
        @page { size: A4; margin: 1cm; }
        .print-btn, .download-img-btn { position: fixed; top: 20px; right: 20px; padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 5px; cursor: pointer; z-index: 1000; margin-right: 10px; }
        .download-img-btn { right: 150px; background: #28a745; }
        .print-btn:hover { background: #0056b3; }
        .download-img-btn:hover { background: #218838; }
        @media print {
            .print-btn, .download-img-btn { display: none; }
        }
    </style>
    <script>
        function printResume() {
            window.print();
        }
    </script>
</head>
<body>
    <button class="print-btn" onclick="printResume()">Print / Save as PDF</button>
    <div class="' . $templateClass . '">
        <h1>' . htmlspecialchars($field) . '</h1>
        <div class="left-column">' . $leftContent . '</div>
        <div class="right-column">' . $rightContent . '</div>
    </div>
</body>
</html>';
    }
    
    $html .= '
    </div>
</body>
</html>';
    
    return $html;
}

// Function to generate image from HTML resume (saves as PNG)
function generate_resume_image($htmlContent, $resumeId, $template = 'classic') {
    $outputDir = __DIR__ . '/../assets/generated_designs/';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    
    $imagePath = $outputDir . $resumeId . '.png';
    $htmlPath = $outputDir . $resumeId . '.html';
    
    // Save HTML first
    file_put_contents($htmlPath, $htmlContent);
    
    // Try to generate image using available methods
    $imageUrl = null;
    
    // Method 1: Try using wkhtmltoimage if available (common on Linux)
    $wkhtmlPath = 'wkhtmltoimage'; // or full path like '/usr/bin/wkhtmltoimage'
    if (shell_exec("which {$wkhtmlPath}") || file_exists($wkhtmlPath)) {
        $command = escapeshellcmd($wkhtmlPath) . ' --width 1200 --format png --quality 100 ' . 
                   escapeshellarg($htmlPath) . ' ' . escapeshellarg($imagePath) . ' 2>&1';
        @exec($command, $output, $returnCode);
        if ($returnCode === 0 && file_exists($imagePath)) {
            $imageUrl = backend_base_url() . '/resume_generator/assets/generated_designs/' . $resumeId . '.png';
        }
    }

    // Method 2: Use HTML to Image API service (if available)
    // You can integrate services like htmlcsstoimage.com API here

    // Method 3: For XAMPP/Windows, provide HTML that can be converted via browser
    // User can use browser's print to PDF or screenshot functionality

    // Fully-qualified URLs (scheme+host included via backend_base_url()) so
    // a frontend on a different domain (e.g. Cloudflare Pages) can still
    // load these - a host-relative path would resolve against the
    // frontend's own origin instead, which doesn't serve these files.
    $htmlUrl = backend_base_url() . '/resume_generator/assets/generated_designs/' . $resumeId . '.html';
    return [
        'html_path' => $htmlUrl,
        'image_path' => $imageUrl,
        'html_content' => $htmlContent,
        'download_url' => $imageUrl ?: $htmlUrl
    ];
}

?>
