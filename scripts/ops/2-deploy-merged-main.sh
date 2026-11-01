#!/bin/bash
# Run after kimi-homepage-og is merged into GitHub main. Fast-forwards the server repo, then deploys.
set -euo pipefail
REPO=/home/olastofx/repositories/event-staff-system
cd "$REPO"
git fetch https://github.com/martinsstudioentertainment-ops/event-staff-system.git main:refs/remotes/gh/main
git merge --ff-only gh/main
git rev-parse HEAD
/bin/bash scripts/cpanel-deploy.sh
echo "Deployed $(git rev-parse --short HEAD)"
