# SSL/TLS Certificate Setup

This directory contains SSL certificates for HTTPS support in Little ISMS Helper.

## 🔒 Quick Start

### Development (Self-Signed Certificates)

For local development, generate self-signed certificates:

```bash
# Navigate to this directory
cd docker/ssl

# Generate self-signed certificate (valid for 365 days)
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout key.pem \
  -out cert.pem \
  -subj "/C=DE/ST=State/L=City/O=Development/OU=IT/CN=localhost"

# Set proper permissions
chmod 600 key.pem
chmod 644 cert.pem
```

### Production (Let's Encrypt)

For production, use **Let's Encrypt** for free, trusted SSL certificates:

#### Option 1: Certbot with Docker

```bash
# Install certbot
sudo apt-get update
sudo apt-get install certbot

# Generate certificate (replace with your domain)
sudo certbot certonly --standalone \
  -d your-domain.com \
  -d www.your-domain.com \
  --email your-email@example.com \
  --agree-tos

# Certificates will be in /etc/letsencrypt/live/your-domain.com/
# Copy to this directory:
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem ./cert.pem
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem ./key.pem
```

#### Option 2: Certbot with Webroot

If your application is already running:

```bash
# Use webroot method (requires nginx to serve .well-known/)
sudo certbot certonly --webroot \
  -w /var/www/certbot \
  -d your-domain.com \
  -d www.your-domain.com \
  --email your-email@example.com \
  --agree-tos
```

#### Option 3: Use Existing Certificates

If you have certificates from a CA:

```bash
# Copy your certificates here
cp /path/to/your/certificate.crt ./cert.pem
cp /path/to/your/private.key ./key.pem

# Ensure proper permissions
chmod 600 key.pem
chmod 644 cert.pem
```

## 🔧 Nginx Configuration

### Enable HTTPS in Docker

1. **Update docker-compose.prod.yml:**

```yaml
app:
  volumes:
    - ./docker/ssl:/etc/nginx/ssl:ro
```

2. **Switch to SSL Nginx config:**

```dockerfile
# In Dockerfile (production stage)
COPY docker/nginx/ssl.conf /etc/nginx/http.d/default.conf
```

Or mount at runtime:

```yaml
app:
  volumes:
    - ./docker/nginx/ssl.conf:/etc/nginx/http.d/default.conf:ro
```

3. **Expose port 443:**

```yaml
app:
  ports:
    - "443:443"
    - "80:80"  # For redirect
```

### Verify SSL Configuration

```bash
# Test SSL certificate
openssl x509 -in docker/ssl/cert.pem -text -noout

# Check certificate expiry
openssl x509 -in docker/ssl/cert.pem -noout -enddate

# Test HTTPS connection
curl -v https://localhost:443 --insecure  # For self-signed

# Test with SSL Labs (Production only)
# https://www.ssllabs.com/ssltest/analyze.html?d=your-domain.com
```

## 🔄 Certificate Renewal

### Let's Encrypt Auto-Renewal

```bash
# Test renewal (dry-run)
sudo certbot renew --dry-run

# Actual renewal (automatic via cron)
sudo certbot renew

# After renewal, copy new certificates
sudo cp /etc/letsencrypt/live/your-domain.com/fullchain.pem ./docker/ssl/cert.pem
sudo cp /etc/letsencrypt/live/your-domain.com/privkey.pem ./docker/ssl/key.pem

# Reload nginx
docker exec isms-app-prod nginx -s reload
```

### Automatic Renewal Script

Create a renewal script at `/etc/cron.monthly/renew-ssl.sh`:

```bash
#!/bin/bash
# Renew Let's Encrypt certificates

certbot renew --quiet

# Copy new certificates
cp /etc/letsencrypt/live/your-domain.com/fullchain.pem /path/to/Little-ISMS-Helper/docker/ssl/cert.pem
cp /etc/letsencrypt/live/your-domain.com/privkey.pem /path/to/Little-ISMS-Helper/docker/ssl/key.pem

# Reload nginx
docker exec isms-app-prod nginx -s reload

echo "SSL certificates renewed: $(date)" >> /var/log/ssl-renewal.log
```

Make it executable:

```bash
chmod +x /etc/cron.monthly/renew-ssl.sh
```

## 🛡️ Security Best Practices

### Certificate Storage

- ✅ **Never commit** `key.pem` to version control (already in `.gitignore`)
- ✅ Set restrictive permissions: `chmod 600 key.pem`
- ✅ Backup certificates securely (encrypted)
- ✅ Use strong key size: minimum 2048-bit RSA or 256-bit ECC

### SSL Configuration

The `ssl.conf` file implements:
- ✅ TLS 1.2 and 1.3 only (no SSLv3, TLS 1.0, TLS 1.1)
- ✅ Strong cipher suites (Mozilla Intermediate profile)
- ✅ HSTS (HTTP Strict Transport Security)
- ✅ OCSP Stapling for faster verification
- ✅ Session cache for performance
- ✅ Content Security Policy (CSP)

### Testing SSL Security

```bash
# Test SSL configuration with testssl.sh
docker run --rm -ti drwetter/testssl.sh https://your-domain.com

# Or use nmap
nmap --script ssl-enum-ciphers -p 443 your-domain.com
```

## 📁 File Structure

```
docker/ssl/
├── README.md          # This file
├── cert.pem          # Public certificate (gitignored)
├── key.pem           # Private key (gitignored)
└── .gitkeep          # Keep directory in git
```

## 🔍 Troubleshooting

### Error: "SSL certificate problem"

```bash
# Check certificate validity
openssl verify cert.pem

# Check if certificate matches key
openssl x509 -noout -modulus -in cert.pem | openssl md5
openssl rsa -noout -modulus -in key.pem | openssl md5
# Both should output the same hash
```

### Error: "Permission denied" for key.pem

```bash
# Fix permissions
chmod 600 key.pem
chown root:root key.pem  # Or www-data if nginx runs as www-data
```

### Browser shows "Not Secure" with self-signed cert

This is expected for development. You can:
1. Click "Advanced" → "Proceed anyway" (Chrome/Edge)
2. Add exception in Firefox
3. Trust the certificate in your OS keychain (macOS/Windows)

For production, always use certificates from a trusted CA (like Let's Encrypt).

## 📚 Additional Resources

- [Let's Encrypt Documentation](https://letsencrypt.org/docs/)
- [Mozilla SSL Configuration Generator](https://ssl-config.mozilla.org/)
- [SSL Labs Server Test](https://www.ssllabs.com/ssltest/)
- [Certbot Documentation](https://certbot.eff.org/docs/)

## ⚙️ Environment-Specific Configuration

### Development
- Use self-signed certificates
- Access via `https://localhost:443`
- Browser will show security warning (expected)

### Staging
- Use Let's Encrypt staging environment
- Test certificate renewal process
- Verify HTTPS redirect works

### Production
- Use Let's Encrypt production certificates
- Enable HSTS with `includeSubDomains`
- Set up auto-renewal via cron
- Monitor certificate expiry (30 days before)

---

**Last Updated:** 2025-11-14
**Maintained by:** Little ISMS Helper Project
