#!/bin/bash
set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(dirname "$(dirname "$SCRIPT_DIR")")"

cd "$REPO_ROOT"

echo "==> Shutting down existing containers and removing volumes..."
docker compose down -v

echo "==> Starting new environment..."
docker compose up -d

echo "==> Waiting for MediaWiki to initialize (this takes ~30 seconds)..."
sleep 30

echo "==> Creating test user (TestUser / testpass123)..."
docker compose exec -T wiki php maintenance/run.php createAndPromote TestUser testpass123 || echo "User may already exist"

echo ""
echo "==> Environment ready!"
echo ""
echo "Access:"
echo "  URL: http://localhost:8888"
echo "  Admin: Admin / dockerpass (sysop - can access all permission levels)"
echo "  Test:  TestUser / testpass123 (user - can access public/internal only)"
echo ""
echo "Next steps for testing:"
echo "  1. Log in as Admin"
echo "  2. Upload a test file via Special:Upload"
echo "  3. Set permission level via SQL (see tests/LocalSettings.test.php for command)"
echo "  4. Log out and test access as anonymous/TestUser"
echo ""
