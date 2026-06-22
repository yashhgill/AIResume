#!/usr/bin/env bash
# Assembles a single backend-deploy.zip you can upload to InfinityFree's
# File Manager in one go, then extract in place (File Manager has an
# "Extract" option after upload - right-click the zip once it's uploaded).
#
# Produces this structure inside the zip (matches DEPLOYMENT.md Part A3
# exactly, so extracting it directly into htdocs/ just works):
#
#   resume_generator/
#     api/                         (entire folder, except your local secrets
#                                    file and OS junk files)
#     assets/
#       generated_designs/         (empty, writable - PHP creates files here)
#       uploaded_images/           (empty, writable)
#       user_photos/               (empty, writable)
#       templates/                 (template preview SVGs)
#       site-theme.css, app-config.js, airesume-logo.svg, three-bg.js
#
# NOTE: api/config.local.php is deliberately NOT included (it has your
# local XAMPP database credentials, which are wrong for InfinityFree).
# api/config.local.php.example IS included - on InfinityFree, rename it
# to config.local.php and fill in the real values per DEPLOYMENT.md Part A4.

set -e
PROJECT_DIR="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"

mkdir -p "$WORK/backend-deploy/resume_generator/api"
mkdir -p "$WORK/backend-deploy/resume_generator/assets/generated_designs"
mkdir -p "$WORK/backend-deploy/resume_generator/assets/uploaded_images"
mkdir -p "$WORK/backend-deploy/resume_generator/assets/user_photos"

# api/ - everything except the local secrets file and OS junk
rsync -a --exclude='config.local.php' --exclude='.DS_Store' "$PROJECT_DIR/api/" "$WORK/backend-deploy/resume_generator/api/"

# assets/ - templates folder + the loose static files the backend may serve directly
cp -r "$PROJECT_DIR/assets/templates" "$WORK/backend-deploy/resume_generator/assets/templates"
cp "$PROJECT_DIR/assets/site-theme.css" "$WORK/backend-deploy/resume_generator/assets/" 2>/dev/null || true
cp "$PROJECT_DIR/assets/app-config.js" "$WORK/backend-deploy/resume_generator/assets/" 2>/dev/null || true
cp "$PROJECT_DIR/assets/airesume-logo.svg" "$WORK/backend-deploy/resume_generator/assets/" 2>/dev/null || true
cp "$PROJECT_DIR/assets/three-bg.js" "$WORK/backend-deploy/resume_generator/assets/" 2>/dev/null || true

# Writable folders just need to exist - drop a placeholder so the empty
# folders survive the zip (zip tools sometimes drop truly empty directories)
touch "$WORK/backend-deploy/resume_generator/assets/generated_designs/.keep"
touch "$WORK/backend-deploy/resume_generator/assets/uploaded_images/.keep"
touch "$WORK/backend-deploy/resume_generator/assets/user_photos/.keep"

# Build the zip in a scratch dir (not the synced project folder - some
# sync layers block the create-then-rename step zip uses), then copy the
# finished file into the project folder as a single, already-complete file.
( cd "$WORK/backend-deploy" && zip -r -q "$WORK/backend-deploy.zip" resume_generator )
rm -f "$PROJECT_DIR/backend-deploy.zip"
cp "$WORK/backend-deploy.zip" "$PROJECT_DIR/backend-deploy.zip"
rm -rf "$WORK"

echo "Created backend-deploy.zip"
unzip -l "$PROJECT_DIR/backend-deploy.zip" | head -40
