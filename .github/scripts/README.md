# GitHub Actions Scripts

Dieses Verzeichnis enthält Utility-Scripts für GitHub Actions Workflows.

## 📜 Verfügbare Scripts

### upload-dockerhub-logo.sh

Lädt automatisch das Repository-Logo zu Docker Hub hoch.

**Usage:**
```bash
./upload-dockerhub-logo.sh <username> <repository> <token> <logo-file>
```

**Parameter:**
- `username`: Docker Hub Username
- `repository`: Repository Name (default: `little-isms-helper`)
- `token`: Docker Hub Access Token
- `logo-file`: Pfad zum Logo (default: `public/logo-512.png`)

**Beispiel:**
```bash
./upload-dockerhub-logo.sh myuser little-isms-helper dckr_pat_xxx public/logo-512.png
```

**Features:**
- ✅ Authentifizierung mit Docker Hub API
- ✅ Upload von PNG/JPG Logos
- ✅ Fehler-tolerant (exit 0 bei Fehlern)
- ✅ Detaillierte Status-Meldungen

**Integration:**
Wird automatisch vom CI/CD Workflow ausgeführt beim Push zu `main` Branch.

**Siehe auch:**
- [DOCKER_HUB.md](../../docs/setup/DOCKER_HUB.md) - Docker Hub Integration Guide
