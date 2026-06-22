# What changed, and what you need to set up

This document covers the four changes made to the original project, and the
exact steps you need to take before it will run.

## 1. Database — already local, now hardened

The database was **already local** (MySQL via XAMPP on `127.0.0.1`), not in
the cloud — there was nothing to migrate. What changed instead is how
secrets are stored:

- `api/config.php` now loads real credentials from `api/config.local.php`
  (a file you create yourself, never committed to git) instead of having
  them hardcoded in source. If `config.local.php` is missing, safe defaults
  are used so the app still boots.
- **Action required:** copy `api/config.local.php.example` to
  `api/config.local.php` and fill in your DB password (if any), a random
  `AUTH_SECRET`, your Groq key, and your Google Client ID (see below).
- A root `.gitignore` was added so `config.local.php`, uploaded photos, and
  generated resumes never get committed.

No resume or user data is sent anywhere except the one Groq API call you
configure in step 3 — everything else stays on your machine.

## 2. Sign up / log in — email+password kept, Google Sign-In added

- The existing email/password register + login flow is untouched.
- A new **"Sign in with Google"** button appears on `login.html` and
  `register.html`, backed by a new endpoint `api/google_auth.php`.
- How it works: Google's Identity Services library gives the browser a
  signed ID token; the backend asks Google's own `tokeninfo` endpoint to
  verify it (no extra JWT library needed), then finds-or-creates a user by
  email and issues the same kind of session token as normal login.
- `users` table gained three columns: `google_id`, `auth_provider`,
  `avatar_url` (see `migration_add_google_auth.sql` — already merged into
  `ai_resume_db.sql` for fresh installs).

**Action required (free, ~5 min):**
1. Go to https://console.cloud.google.com/apis/credentials
2. Create an OAuth Client ID → Application type **Web application**.
3. Authorized JavaScript origins: `http://localhost`, `http://127.0.0.1`,
   and whichever port you actually use (e.g. `http://localhost:3001`).
4. Copy the Client ID into **two** places:
   - `assets/app-config.js` → `GOOGLE_CLIENT_ID`
   - `api/config.local.php` → `GOOGLE_CLIENT_ID`
   (Client IDs are not secret — they're meant to be visible in frontend
   code — only the matching is what matters.)
5. If you already imported the old `ai_resume_db.sql`, run
   `migration_add_google_auth.sql` against your existing database once.

## 3. AI provider — Gemini replaced with Groq

- `api/config.php`: `GEMINI_API_KEY` / `GEMINI_API_BASE` are gone, replaced
  by `GROQ_API_KEY` / `GROQ_API_BASE`. The Gemini key that used to be
  hardcoded in this file was a real, exposed key — it has been removed
  entirely; you must supply your own.
- `generate_resume_html_css_with_gemini()` → `generate_resume_html_css_with_groq()`
  and `generate_resume_with_gemini()` → `generate_resume_with_groq()`. Same
  prompts, same template logic — only the HTTP call and response shape
  changed (Groq uses the OpenAI-compatible `chat/completions` format).
- Model fallback chain: `openai/gpt-oss-120b` → `llama-3.3-70b-versatile` →
  `openai/gpt-oss-20b` → `llama-3.1-8b-instant`. Check
  https://console.groq.com/docs/models if any of these get retired later.
- `api/list_models.php` now queries Groq's `/models` endpoint (run with
  `php api/list_models.php` to sanity-check your key).

**Action required:** get a free key at https://console.groq.com/keys and put
it in `api/config.local.php` as `GROQ_API_KEY`.

### About generating multiple resumes with multiple templates

This already works, and nothing about switching to Groq changes it: the
"Create with AI" flow (`frontendreact/onboard/templates.html`) sends **one
request per template** you click — each call to `POST api/resumes.php` with
`action=generate` produces one resume in one template, saved as its own row.
Click "Create with AI" on as many template cards as you like and they all
land in **My Resumes**. Multi-template generation is orchestration logic in
your own code, not a feature of the model provider, so it works identically
on Gemini, Groq, or anything else you point `GROQ_API_BASE` at later.

## 4. Frontend — colorful, animated, 3D

- New shared files: `assets/site-theme.css` (gradient theme, glass cards,
  hover/entrance animations) and `assets/three-bg.js` (an animated 3D
  background — floating icosahedrons/torus shapes with mouse parallax,
  built with Three.js r128 loaded from CDN).
- Applied to every page: `index.html`, `login.html`, `register.html`,
  `my-resumes.html`, `profile.html`, `onboard/start.html`,
  `onboard/templates.html`.
- No existing element IDs, classes used by JavaScript, or API calls were
  renamed — only visuals changed, so none of the existing functionality
  (resume list, delete, profile edit, password change, template picker)
  should behave differently.
- Three.js fails gracefully: if WebGL or the CDN script is unavailable, the
  page keeps the CSS gradient background and everything else still works.

## Quick checklist before running

- [ ] `cp api/config.local.php.example api/config.local.php` and fill it in
- [ ] Add your Groq key (`GROQ_API_KEY`)
- [ ] Create a Google OAuth Client ID and put it in **both**
      `api/config.local.php` and `assets/app-config.js`
- [ ] If upgrading an existing DB, run `migration_add_google_auth.sql`
- [ ] Start XAMPP (Apache + MySQL) as before
