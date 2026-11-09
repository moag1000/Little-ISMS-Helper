# Docker Secrets Management Guide

How to keep credentials out of the compose file for the production image
(`docker-compose.prod.yml`, embedded MariaDB). Container setup: [DOCKER_PRODUCTION.md](DOCKER_PRODUCTION.md).

## Which values are secret

| Variable | Needed | Notes |
|---|---|---|
| `APP_SECRET` | yes | Generated on first start and kept in `var/app_secret` (volume). Set it only to take over a value from another host. Changing it makes encrypted backup fields and MFA secrets (without `MFA_ENCRYPTION_KEY`) unreadable |
| `MYSQL_PASSWORD` | no | Empty = generated, stored in `var/mysql_password.txt` |
| `MAILER_DSN` | for mail | Contains the SMTP password |
| `APP_AUDIT_HMAC_KEY` | recommended | ≥ 32 random bytes; signs the audit log |
| `MFA_ENCRYPTION_KEY` | optional | Own key for TOTP secrets (fallback `APP_SECRET`) |
| `AZURE_CLIENT_SECRET`, `SAML_SP_PRIVATE_KEY` | with SSO | See [AUTHENTICATION_SETUP.md](../setup/AUTHENTICATION_SETUP.md) |

Not secret, but required: `DEFAULT_URI` (public URL).

## Recommended: environment file with restricted permissions

The image reads plain environment variables. It has no support for `*_FILE` variables or
`/run/secrets/*` files, so Docker/Swarm secrets cannot be wired in without a custom entrypoint.

```bash
install -m 600 /dev/null /etc/isms/app.env
cat >> /etc/isms/app.env <<'EOF'
MAILER_DSN=smtp://user:password@smtp.example.com:587
APP_AUDIT_HMAC_KEY=<openssl rand -hex 32>
DEFAULT_URI=https://isms.example.com
EOF
```

```yaml
# docker-compose.override.yml
services:
  app:
    env_file:
      - /etc/isms/app.env
```

```bash
docker compose -f docker-compose.prod.yml -f docker-compose.override.yml up -d
```

`environment:` entries in `docker-compose.prod.yml` win over `env_file` — `MAILER_DSN` is set
there as `${MAILER_DSN:-}`; pass it via `--env-file` for interpolation or remove that line in the
override.

Keep the file out of Git and out of the image. `docker inspect` shows the values to anyone with
access to the Docker socket — limit that group.

## Back up the keys

`APP_SECRET`, `APP_AUDIT_HMAC_KEY` and `MFA_ENCRYPTION_KEY` are not part of the application backup.
Store them separately (password manager, vault):

```bash
docker compose -f docker-compose.prod.yml exec app cat /var/www/html/var/app_secret
```

Restoring on another host: [DISASTER_RECOVERY.md §4](../operations/DISASTER_RECOVERY.md#4-app_secret-bei-wiederherstellung).

## Rotation

| Value | How |
|---|---|
| SMTP / SSO credentials | Change in the env file, `docker compose up -d` |
| `MYSQL_PASSWORD` | Change the MariaDB user's password inside the container, then the variable, then restart |
| `APP_SECRET` | Avoid. Sessions end; encrypted backup fields of older backups and MFA secrets without own key become unreadable |
| `APP_AUDIT_HMAC_KEY` | Avoid. Rows signed with the old key no longer verify |
| `MFA_ENCRYPTION_KEY` | Set the new key and re-encrypt with `php bin/console app:encrypt-mfa-secrets` |
| API tokens | Profile → API tokens (`/profile/api-tokens`): revoke and re-create |

## Checklist

- [ ] Env file mode `600`, owned by root, not in Git
- [ ] `APP_SECRET` / HMAC / MFA keys stored outside the server
- [ ] Docker socket access restricted
- [ ] No secrets in `docker-compose*.yml` committed to Git
