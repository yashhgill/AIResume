#!/bin/bash
# Cloudflare Pages build script.
# Set this project's "Build command" to:   bash build-pages.sh
# Set "Build output directory" to:          pages-deploy
#
# This assembles ONLY the static frontend pieces into pages-deploy/,
# preserving the /resume_generator/... absolute paths already used
# throughout the HTML/CSS/JS (so nothing else needed to change). The
# api/ folder (PHP source, backend logic) is deliberately NOT included -
# it's hosted separately and should never be served as static files from
# the frontend domain.

set -e

rm -rf pages-deploy
mkdir -p pages-deploy/resume_generator/assets

# Frontend pages
cp -r frontendreact pages-deploy/resume_generator/

# Static assets only - NOT the backend-only dynamic folders
# (generated_designs/, uploaded_images/, user_photos/ are created at
# runtime by the PHP backend and don't belong on the frontend host).
cp assets/app-config.js pages-deploy/resume_generator/assets/
cp assets/site-theme.css pages-deploy/resume_generator/assets/
cp assets/airesume-logo.svg pages-deploy/resume_generator/assets/
cp assets/three-bg.js pages-deploy/resume_generator/assets/
cp -r assets/templates pages-deploy/resume_generator/assets/

# Optional clean root URL (yoursite.pages.dev/ instead of only the
# longer /resume_generator/frontendreact/index.html path)
cp cloudflare-pages-root-redirect.html pages-deploy/index.html

echo "Build output ready in pages-deploy/"
