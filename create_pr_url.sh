#!/bin/bash

# GitHub Repository Info
REPO="moag1000/Little-ISMS-Helper"
BASE_BRANCH="main"
HEAD_BRANCH="claude/review-implementation-011CUtM3CCyTQqwETurnUkYo"

# Create PR URL
PR_URL="https://github.com/${REPO}/compare/${BASE_BRANCH}...${HEAD_BRANCH}?expand=1"

echo "🔗 Öffnen Sie diese URL im Browser:"
echo ""
echo "$PR_URL"
echo ""
echo "Die PR-Beschreibung finden Sie in: PR_PHASE5_COMPLETE.md"
