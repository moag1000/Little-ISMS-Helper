#!/bin/bash
#
# SSL Certificate Setup Script for Little ISMS Helper
#
# This script automates SSL certificate generation for both development and production.
#
# Usage:
#   chmod +x scripts/setup-ssl.sh
#   ./scripts/setup-ssl.sh [development|production]
#
# Author: Little ISMS Helper Project
# License: AGPL-3.0-or-later
#

set -e  # Exit on error
set -u  # Exit on undefined variable

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"
SSL_DIR="$PROJECT_ROOT/docker/ssl"

# Functions
print_header() {
    echo -e "${BLUE}========================================${NC}"
    echo -e "${BLUE}  Little ISMS Helper - SSL Setup${NC}"
    echo -e "${BLUE}========================================${NC}"
    echo ""
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

print_info() {
    echo -e "${BLUE}ℹ $1${NC}"
}

check_requirements() {
    print_info "Checking requirements..."

    # Check for openssl
    if ! command -v openssl &> /dev/null; then
        print_error "OpenSSL is not installed"
        echo "Install with: apt-get install openssl (Debian/Ubuntu) or brew install openssl (macOS)"
        exit 1
    fi
    print_success "OpenSSL found: $(openssl version)"

    # Create SSL directory if it doesn't exist
    if [ ! -d "$SSL_DIR" ]; then
        mkdir -p "$SSL_DIR"
        print_success "Created SSL directory: $SSL_DIR"
    fi

    echo ""
}

setup_development() {
    print_header
    echo -e "${YELLOW}Setting up SSL for DEVELOPMENT environment${NC}"
    echo ""

    print_info "This will generate a self-signed certificate valid for 365 days"
    print_warning "Self-signed certificates will show security warnings in browsers"
    echo ""

    # Prompt for domain (default: localhost)
    read -p "Enter domain name [localhost]: " DOMAIN
    DOMAIN=${DOMAIN:-localhost}

    # Prompt for additional SANs (Subject Alternative Names)
    read -p "Enter additional domains (comma-separated, optional): " SANS

    print_info "Generating self-signed certificate for: $DOMAIN"
    echo ""

    # Build SAN extension
    SAN_EXT="subjectAltName=DNS:$DOMAIN,DNS:*.${DOMAIN}"
    if [ -n "$SANS" ]; then
        IFS=',' read -ra SAN_ARRAY <<< "$SANS"
        for san in "${SAN_ARRAY[@]}"; do
            SAN_EXT="${SAN_EXT},DNS:${san// /}"
        done
    fi

    # Generate certificate
    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout "$SSL_DIR/key.pem" \
        -out "$SSL_DIR/cert.pem" \
        -subj "/C=DE/ST=State/L=City/O=Little ISMS Helper Development/OU=IT/CN=$DOMAIN" \
        -addext "$SAN_EXT" \
        2>/dev/null

    # Set proper permissions
    chmod 600 "$SSL_DIR/key.pem"
    chmod 644 "$SSL_DIR/cert.pem"

    print_success "Self-signed certificate generated successfully!"
    echo ""
    print_info "Certificate details:"
    openssl x509 -in "$SSL_DIR/cert.pem" -text -noout | grep -E "Subject:|Not Before|Not After|DNS:"
    echo ""

    print_warning "Next steps:"
    echo "1. Update docker-compose.yml to mount SSL certificates"
    echo "2. Switch nginx config to ssl.conf"
    echo "3. Rebuild containers: docker-compose up -d --build"
    echo "4. Access via: https://localhost:443"
    echo ""
    print_warning "Browser will show 'Not Secure' warning - this is expected for self-signed certs"
}

setup_production() {
    print_header
    echo -e "${YELLOW}Setting up SSL for PRODUCTION environment${NC}"
    echo ""

    print_warning "This wizard will help you set up Let's Encrypt certificates"
    echo ""

    # Check for certbot
    if ! command -v certbot &> /dev/null; then
        print_error "Certbot is not installed"
        echo ""
        echo "Install with:"
        echo "  Debian/Ubuntu: sudo apt-get install certbot"
        echo "  CentOS/RHEL:   sudo yum install certbot"
        echo "  macOS:         brew install certbot"
        echo ""
        exit 1
    fi
    print_success "Certbot found: $(certbot --version)"
    echo ""

    # Prompt for domain
    read -p "Enter your production domain (e.g., isms.example.com): " DOMAIN
    if [ -z "$DOMAIN" ]; then
        print_error "Domain is required"
        exit 1
    fi

    # Prompt for email
    read -p "Enter email for Let's Encrypt notifications: " EMAIL
    if [ -z "$EMAIL" ]; then
        print_error "Email is required"
        exit 1
    fi

    # Choose method
    echo ""
    print_info "Choose certificate generation method:"
    echo "1) Standalone (recommended if port 80 is free)"
    echo "2) Webroot (recommended if application is already running)"
    read -p "Select method [1]: " METHOD
    METHOD=${METHOD:-1}

    echo ""
    print_info "Generating Let's Encrypt certificate..."
    echo ""

    if [ "$METHOD" == "1" ]; then
        # Standalone method
        sudo certbot certonly --standalone \
            -d "$DOMAIN" \
            --email "$EMAIL" \
            --agree-tos \
            --non-interactive
    else
        # Webroot method
        WEBROOT_PATH="/var/www/certbot"
        print_info "Using webroot path: $WEBROOT_PATH"
        sudo certbot certonly --webroot \
            -w "$WEBROOT_PATH" \
            -d "$DOMAIN" \
            --email "$EMAIL" \
            --agree-tos \
            --non-interactive
    fi

    # Copy certificates
    print_info "Copying certificates to docker/ssl/"
    sudo cp "/etc/letsencrypt/live/$DOMAIN/fullchain.pem" "$SSL_DIR/cert.pem"
    sudo cp "/etc/letsencrypt/live/$DOMAIN/privkey.pem" "$SSL_DIR/key.pem"

    # Set proper permissions
    sudo chown $(whoami):$(whoami) "$SSL_DIR/cert.pem" "$SSL_DIR/key.pem"
    chmod 600 "$SSL_DIR/key.pem"
    chmod 644 "$SSL_DIR/cert.pem"

    print_success "Production certificate installed successfully!"
    echo ""

    # Setup auto-renewal
    print_info "Setting up auto-renewal..."
    CRON_SCRIPT="/etc/cron.monthly/renew-ssl-isms.sh"

    cat > "/tmp/renew-ssl-isms.sh" <<EOF
#!/bin/bash
# Auto-renewal script for Little ISMS Helper SSL certificates

certbot renew --quiet

# Copy renewed certificates
cp /etc/letsencrypt/live/$DOMAIN/fullchain.pem $SSL_DIR/cert.pem
cp /etc/letsencrypt/live/$DOMAIN/privkey.pem $SSL_DIR/key.pem

# Reload nginx in container
docker exec isms-app-prod nginx -s reload 2>/dev/null || true

echo "SSL certificates renewed: \$(date)" >> /var/log/ssl-renewal-isms.log
EOF

    sudo mv "/tmp/renew-ssl-isms.sh" "$CRON_SCRIPT"
    sudo chmod +x "$CRON_SCRIPT"

    print_success "Auto-renewal script created: $CRON_SCRIPT"
    echo ""

    print_warning "Next steps:"
    echo "1. Update docker-compose.prod.yml to mount SSL certificates"
    echo "2. Switch nginx config to ssl.conf"
    echo "3. Expose ports 80 and 443 in docker-compose.prod.yml"
    echo "4. Deploy: docker-compose -f docker-compose.prod.yml up -d --build"
    echo "5. Test: https://$DOMAIN"
    echo ""
    print_info "Certificate will auto-renew monthly via cron job"
    print_info "Test renewal with: sudo certbot renew --dry-run"
}

verify_certificate() {
    print_header
    echo -e "${YELLOW}Verifying SSL Certificate${NC}"
    echo ""

    if [ ! -f "$SSL_DIR/cert.pem" ]; then
        print_error "Certificate not found: $SSL_DIR/cert.pem"
        exit 1
    fi

    if [ ! -f "$SSL_DIR/key.pem" ]; then
        print_error "Private key not found: $SSL_DIR/key.pem"
        exit 1
    fi

    print_success "Certificate and key found"
    echo ""

    print_info "Certificate details:"
    openssl x509 -in "$SSL_DIR/cert.pem" -text -noout | grep -E "Subject:|Issuer:|Not Before|Not After|DNS:"
    echo ""

    print_info "Verifying certificate matches private key..."
    CERT_MD5=$(openssl x509 -noout -modulus -in "$SSL_DIR/cert.pem" | openssl md5)
    KEY_MD5=$(openssl rsa -noout -modulus -in "$SSL_DIR/key.pem" 2>/dev/null | openssl md5)

    if [ "$CERT_MD5" == "$KEY_MD5" ]; then
        print_success "Certificate and private key match!"
    else
        print_error "Certificate and private key DO NOT match!"
        exit 1
    fi

    echo ""
    print_info "Checking certificate expiry..."
    EXPIRY=$(openssl x509 -enddate -noout -in "$SSL_DIR/cert.pem" | cut -d= -f2)
    EXPIRY_TIMESTAMP=$(date -d "$EXPIRY" +%s 2>/dev/null || date -j -f "%b %d %T %Y %Z" "$EXPIRY" +%s)
    NOW_TIMESTAMP=$(date +%s)
    DAYS_LEFT=$(( ($EXPIRY_TIMESTAMP - $NOW_TIMESTAMP) / 86400 ))

    if [ "$DAYS_LEFT" -lt 30 ]; then
        print_warning "Certificate expires in $DAYS_LEFT days - RENEW SOON!"
    elif [ "$DAYS_LEFT" -lt 90 ]; then
        print_warning "Certificate expires in $DAYS_LEFT days"
    else
        print_success "Certificate valid for $DAYS_LEFT days"
    fi

    echo ""
    print_info "Testing with SSL Labs..."
    echo "Visit: https://www.ssllabs.com/ssltest/analyze.html?d=YOUR_DOMAIN"
}

show_usage() {
    echo "Usage: $0 [development|production|verify]"
    echo ""
    echo "Commands:"
    echo "  development  - Generate self-signed certificate for local development"
    echo "  production   - Generate Let's Encrypt certificate for production"
    echo "  verify       - Verify existing certificate"
    echo ""
    echo "Examples:"
    echo "  $0 development"
    echo "  $0 production"
    echo "  $0 verify"
    echo ""
}

# Main script
main() {
    if [ $# -eq 0 ]; then
        show_usage
        exit 1
    fi

    case "$1" in
        development|dev)
            check_requirements
            setup_development
            ;;
        production|prod)
            check_requirements
            setup_production
            ;;
        verify|check)
            verify_certificate
            ;;
        help|--help|-h)
            show_usage
            ;;
        *)
            print_error "Unknown command: $1"
            echo ""
            show_usage
            exit 1
            ;;
    esac
}

main "$@"
