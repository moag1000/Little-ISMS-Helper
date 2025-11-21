#!/bin/bash
set -e

# Script to upload logo and README to Docker Hub repository
# Usage: ./upload-dockerhub-logo.sh <username> <repository> <token> <logo-file> <readme-file>

DOCKERHUB_USERNAME="${1:-}"
DOCKERHUB_REPO="${2:-little-isms-helper}"
DOCKERHUB_TOKEN="${3:-}"
LOGO_FILE="${4:-public/logo-512.png}"
README_FILE="${5:-README.md}"

if [ -z "$DOCKERHUB_USERNAME" ] || [ -z "$DOCKERHUB_TOKEN" ]; then
    echo "❌ Error: DOCKERHUB_USERNAME and DOCKERHUB_TOKEN are required"
    echo "Usage: $0 <username> <repository> <token> <logo-file> <readme-file>"
    exit 1
fi

if [ ! -f "$LOGO_FILE" ]; then
    echo "❌ Error: Logo file not found: $LOGO_FILE"
    exit 1
fi

echo "🔧 Updating Docker Hub repository..."
echo "   Repository: $DOCKERHUB_USERNAME/$DOCKERHUB_REPO"
echo "   Logo file: $LOGO_FILE"
echo "   README file: $README_FILE"

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
else
    echo "⚠️  Logo upload returned HTTP $HTTP_CODE"
    echo "   Response: $RESPONSE_BODY"
fi

# Upload README/Description to Docker Hub
if [ -f "$README_FILE" ]; then
    echo ""
    echo "📄 Updating Docker Hub description from $README_FILE..."

    # Read README content and escape for JSON
    README_CONTENT=$(cat "$README_FILE" | jq -Rs .)

    # Update full description (Overview tab)
    DESC_RESPONSE=$(curl -s -w "\n%{http_code}" -X PATCH \
        "https://hub.docker.com/v2/repositories/$DOCKERHUB_USERNAME/$DOCKERHUB_REPO/" \
        -H "Authorization: JWT $JWT_TOKEN" \
        -H "Content-Type: application/json" \
        -d "{\"full_description\": $README_CONTENT}")

    DESC_HTTP_CODE=$(echo "$DESC_RESPONSE" | tail -n1)
    DESC_BODY=$(echo "$DESC_RESPONSE" | head -n-1)

    if [ "$DESC_HTTP_CODE" -eq 200 ] || [ "$DESC_HTTP_CODE" -eq 201 ]; then
        echo "✅ README/Description updated successfully!"
    else
        echo "⚠️  Description update returned HTTP $DESC_HTTP_CODE"
        echo "   Response: $DESC_BODY"
    fi
else
    echo "⚠️  README file not found: $README_FILE"
fi

echo ""
echo "🎉 Visit: https://hub.docker.com/r/$DOCKERHUB_USERNAME/$DOCKERHUB_REPO"
exit 0
