#!/bin/bash
set -e

# Script to upload logo to Docker Hub repository
# Usage: ./upload-dockerhub-logo.sh <username> <repository> <token> <logo-file>

DOCKERHUB_USERNAME="${1:-}"
DOCKERHUB_REPO="${2:-little-isms-helper}"
DOCKERHUB_TOKEN="${3:-}"
LOGO_FILE="${4:-public/logo-512.png}"

if [ -z "$DOCKERHUB_USERNAME" ] || [ -z "$DOCKERHUB_TOKEN" ]; then
    echo "❌ Error: DOCKERHUB_USERNAME and DOCKERHUB_TOKEN are required"
    echo "Usage: $0 <username> <repository> <token> <logo-file>"
    exit 1
fi

if [ ! -f "$LOGO_FILE" ]; then
    echo "❌ Error: Logo file not found: $LOGO_FILE"
    exit 1
fi

echo "🔧 Uploading logo to Docker Hub..."
echo "   Repository: $DOCKERHUB_USERNAME/$DOCKERHUB_REPO"
echo "   Logo file: $LOGO_FILE"

# Get JWT token from Docker Hub
echo "🔑 Authenticating with Docker Hub..."
JWT_TOKEN=$(curl -s -X POST \
    https://hub.docker.com/v2/users/login/ \
    -H "Content-Type: application/json" \
    -d "{\"username\": \"$DOCKERHUB_USERNAME\", \"password\": \"$DOCKERHUB_TOKEN\"}" \
    | grep -o '"token":"[^"]*' | sed 's/"token":"//')

if [ -z "$JWT_TOKEN" ]; then
    echo "❌ Error: Failed to authenticate with Docker Hub"
    echo "   Please check your username and token"
    exit 1
fi

echo "✅ Authentication successful"

# Upload logo using Docker Hub API
echo "📤 Uploading logo..."

# Docker Hub expects the logo as form data
RESPONSE=$(curl -s -w "\n%{http_code}" -X PATCH \
    "https://hub.docker.com/v2/repositories/$DOCKERHUB_USERNAME/$DOCKERHUB_REPO/" \
    -H "Authorization: JWT $JWT_TOKEN" \
    -F "logo_url=@$LOGO_FILE")

HTTP_CODE=$(echo "$RESPONSE" | tail -n1)
RESPONSE_BODY=$(echo "$RESPONSE" | head -n-1)

if [ "$HTTP_CODE" -eq 200 ] || [ "$HTTP_CODE" -eq 201 ]; then
    echo "✅ Logo uploaded successfully!"
    echo "🎉 Visit: https://hub.docker.com/r/$DOCKERHUB_USERNAME/$DOCKERHUB_REPO"
else
    echo "⚠️  Logo upload returned HTTP $HTTP_CODE"
    echo "   This might be expected if the API endpoint has changed."
    echo "   Response: $RESPONSE_BODY"
    echo ""
    echo "ℹ️  Alternative: Manual upload at Docker Hub Settings"
    echo "   If automatic upload doesn't work, you can upload manually:"
    echo "   1. Go to https://hub.docker.com/r/$DOCKERHUB_USERNAME/$DOCKERHUB_REPO"
    echo "   2. Click on repository name (top left, near the icon)"
    echo "   3. Hover over the icon placeholder and click 'Edit'"
    echo "   4. Upload: $LOGO_FILE"

    # Don't fail the build for logo upload issues
    exit 0
fi
