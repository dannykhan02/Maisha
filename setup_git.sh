#!/usr/bin/env bash
# =========================================================
# setup_git.sh
# Initializes git for the maisha repo (backend + ai-engine),
# keeping frontend/ excluded (it has its own repo).
#
# Run this from the maisha/ root:
#   chmod +x setup_git.sh
#   ./setup_git.sh
# =========================================================

set -e  # stop on first error

echo "=== Step 1: Checking for existing git repo ==="
if [ -d .git ]; then
  echo "A .git directory already exists here. Aborting to avoid overwriting history."
  exit 1
fi

echo "=== Step 2: Scanning for secrets before anything is tracked ==="
find backend ai-engine -iname "*.env*" 2>/dev/null || true
echo "^ Confirm these are the ONLY .env-like files, and that .gitignore covers them."
read -p "Press enter to continue once confirmed, or Ctrl+C to stop and investigate..."

echo "=== Step 3: Checking .gitignore is present ==="
if [ ! -f .gitignore ]; then
  echo "ERROR: .gitignore not found in current directory. Place it here before continuing."
  exit 1
fi
echo ".gitignore found."

echo "=== Step 4: Initializing repo ==="
git init
git branch -M main

echo "=== Step 5: Dry-run check — review carefully ==="
git status
echo ""
echo "Look above for: vendor/, node_modules/, venv/, .env, frontend/"
echo "If ANY of those appear as 'to be committed', STOP and fix .gitignore first."
read -p "Press enter to proceed with staging, or Ctrl+C to stop..."

echo "=== Step 6: Staging files ==="
git add .
git status

echo "=== Step 7: Ready to commit ==="
read -p "Does the staged file list look correct? Press enter to commit, or Ctrl+C to abort..."
git commit -m "Initial commit: backend (Laravel) + ai-engine (Flask)"

echo "=== Step 8: Verifying frontend independence ==="
if [ -d frontend/.git ] || [ -d frontend/*/.git ]; then
  echo "Frontend has its own .git — confirmed separate."
else
  echo "NOTE: no .git found inside frontend/ subdirectories. Verify frontend repo location manually."
fi

echo ""
echo "=== Done ==="
echo "Next step: add a remote and push, e.g.:"
echo "  git remote add origin <your-repo-url>"
echo "  git push -u origin main"