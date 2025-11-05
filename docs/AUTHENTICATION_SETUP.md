# Authentifizierungs- und Berechtigungssystem

## Übersicht

Das Little ISMS Helper Framework implementiert ein ausgefeiltes Benutzer-, Rollen- und Berechtigungsmanagement mit Unterstützung für externe Identity Provider, insbesondere Microsoft Azure AD über OAuth2 und SAML.

## Funktionen

### Authentifizierungsmethoden

1. **Lokale Authentifizierung**: Klassisches E-Mail/Passwort-Login
2. **Azure OAuth2**: Single Sign-On über Microsoft Azure AD mit OAuth 2.0
3. **Azure SAML**: SAML 2.0 basierte Authentifizierung über Microsoft Azure AD

### Berechtigungssystem

- **Granulare Berechtigungen**: Feinkörnige Kontrolle über einzelne Aktionen (view, create, edit, delete, approve, export)
- **Rollenverwaltung**: Vordefinierte und benutzerdefinierte Rollen
- **Rollenhierarchie**: ROLE_USER < ROLE_AUDITOR < ROLE_MANAGER < ROLE_ADMIN < ROLE_SUPER_ADMIN
- **Security Voters**: Automatische Berechtigungsprüfung auf Entity-Ebene

## Einrichtung

### 1. Umgebungsvariablen konfigurieren

Kopieren Sie `.env` nach `.env.local` und fügen Sie folgende Variablen hinzu:

```bash
# Azure OAuth2 Configuration
AZURE_CLIENT_ID=your-azure-app-client-id
AZURE_CLIENT_SECRET=your-azure-app-client-secret
AZURE_TENANT_ID=your-azure-tenant-id

# Azure SAML Configuration
SAML_IDP_ENTITY_ID=https://sts.windows.net/your-tenant-id/
SAML_IDP_SSO_URL=https://login.microsoftonline.com/your-tenant-id/saml2
SAML_IDP_SLO_URL=https://login.microsoftonline.com/your-tenant-id/saml2
SAML_IDP_CERT="-----BEGIN CERTIFICATE-----
MIICmzCCAYMCBgF...
-----END CERTIFICATE-----"

# Service Provider (SP) SAML Configuration
SAML_SP_CERT="-----BEGIN CERTIFICATE-----
Your SP Certificate
-----END CERTIFICATE-----"
SAML_SP_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----
Your SP Private Key
-----END PRIVATE KEY-----"

# Database Configuration
DATABASE_URL="mysql://user:password@127.0.0.1:3306/isms_db?serverVersion=8.0&charset=utf8mb4"
```

### 2. Azure AD OAuth2 App registrieren

#### In Azure Portal:

1. Navigieren Sie zu **Azure Active Directory** > **App registrations** > **New registration**
2. Name: `Little ISMS Helper`
3. Supported account types: Wählen Sie entsprechend Ihrer Organisation
4. Redirect URI:
   - Type: Web
   - URI: `https://your-domain.com/oauth/azure/check`
5. Klicken Sie auf **Register**

#### Konfiguration:

1. Notieren Sie die **Application (client) ID** → `AZURE_CLIENT_ID`
2. Notieren Sie die **Directory (tenant) ID** → `AZURE_TENANT_ID`
3. Gehen Sie zu **Certificates & secrets**:
   - Klicken Sie auf **New client secret**
   - Beschreibung: `ISMS Helper OAuth`
   - Expiry: 24 months
   - Notieren Sie den **Value** → `AZURE_CLIENT_SECRET`
4. Gehen Sie zu **API permissions**:
   - **Add permission** > **Microsoft Graph** > **Delegated permissions**
   - Fügen Sie hinzu: `User.Read`, `email`, `openid`, `profile`
   - Klicken Sie auf **Grant admin consent**

### 3. Azure AD SAML App registrieren

#### In Azure Portal:

1. Navigieren Sie zu **Enterprise applications** > **New application** > **Create your own application**
2. Name: `Little ISMS Helper SAML`
3. Wählen Sie **Integrate any other application you don't find in the gallery (Non-gallery)**
4. Klicken Sie auf **Create**

#### SAML Konfiguration:

1. Gehen Sie zu **Single sign-on** > Wählen Sie **SAML**
2. **Basic SAML Configuration**:
   - Identifier (Entity ID): `https://your-domain.com/saml/metadata`
   - Reply URL (ACS): `https://your-domain.com/saml/acs`
   - Sign on URL: `https://your-domain.com/saml/login`
3. **Attributes & Claims**:
   - Standard Claims werden automatisch konfiguriert
   - Stellen Sie sicher, dass folgende Claims vorhanden sind:
     - `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress`
     - `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname`
     - `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname`
     - `http://schemas.microsoft.com/identity/claims/objectidentifier`
     - `http://schemas.microsoft.com/identity/claims/tenantid`
4. **SAML Certificates**:
   - Download **Certificate (Base64)** → Inhalt in `SAML_IDP_CERT`
   - Notieren Sie **Login URL** → `SAML_IDP_SSO_URL`
   - Notieren Sie **Azure AD Identifier** → `SAML_IDP_ENTITY_ID`
5. Generieren Sie SP Zertifikat und Schlüssel:
   ```bash
   openssl req -new -x509 -days 3652 -nodes -out sp.crt -keyout sp.key
   ```
   - Inhalt von `sp.crt` → `SAML_SP_CERT`
   - Inhalt von `sp.key` → `SAML_SP_PRIVATE_KEY`

### 4. Datenbank einrichten

```bash
# Migration ausführen
php bin/console doctrine:migrations:migrate

# Berechtigungen und Rollen initialisieren
php bin/console app:setup-permissions

# Admin-Benutzer erstellen
php bin/console app:setup-permissions --admin-email=admin@example.com --admin-password=SecurePassword123!
```

### 5. Berechtigungen zurücksetzen (optional)

```bash
php bin/console app:setup-permissions --reset
```

**WARNUNG**: Dies löscht alle bestehenden Berechtigungen und Rollen!

## Verwendung

### Login-Seiten

- **Haupt-Login**: `/login` - Bietet alle Authentifizierungsmethoden
- **Azure OAuth**: `/oauth/azure/connect` - Direkter OAuth-Login
- **Azure SAML**: `/saml/login` - Direkter SAML-Login
- **SAML Metadata**: `/saml/metadata` - SP Metadata für Azure AD Konfiguration

### Benutzerverwaltung

Admin-Benutzer können auf die Benutzerverwaltung unter `/admin/users` zugreifen:

- Benutzer anzeigen, erstellen, bearbeiten und löschen
- Rollen und Berechtigungen zuweisen
- Benutzer aktivieren/deaktivieren
- Benutzerstatistiken einsehen

### Rollenverwaltung

Admin-Benutzer können auf die Rollenverwaltung unter `/admin/roles` zugreifen:

- Rollen anzeigen, erstellen, bearbeiten und löschen
- Berechtigungen zu Rollen zuweisen
- System-Rollen anzeigen (können nicht gelöscht werden)

### Vordefinierte Rollen

#### ROLE_USER
- **Beschreibung**: Basis-Benutzerrolle mit Nur-Lese-Zugriff
- **Berechtigungen**:
  - Risiken, Assets, Vorfälle, Controls anzeigen
  - Audits und Compliance-Daten anzeigen
  - Berichte anzeigen

#### ROLE_AUDITOR
- **Beschreibung**: Interner Auditor
- **Berechtigungen**:
  - Alle ROLE_USER Berechtigungen
  - Audits erstellen und bearbeiten
  - Berichte erstellen

#### ROLE_MANAGER
- **Beschreibung**: ISMS Manager
- **Berechtigungen**:
  - Alle ROLE_AUDITOR Berechtigungen
  - Risiken erstellen, bearbeiten und genehmigen
  - Assets erstellen und bearbeiten
  - Vorfälle erstellen, bearbeiten und genehmigen
  - Controls erstellen und bearbeiten
  - Compliance-Daten bearbeiten
  - Berichte exportieren

#### ROLE_ADMIN
- **Beschreibung**: Administrator mit vollem Systemzugriff
- **Berechtigungen**: Alle Berechtigungen

#### ROLE_SUPER_ADMIN
- **Beschreibung**: Super-Administrator
- **Berechtigungen**:
  - Alle ROLE_ADMIN Berechtigungen
  - Benutzer impersonieren (switch_user)

## Berechtigungskategorien

### User (Benutzer)
- `user.view`, `user.view_all`, `user.create`, `user.edit`, `user.delete`
- `user.manage_roles`, `user.manage_permissions`

### Role (Rollen)
- `role.view`, `role.create`, `role.edit`, `role.delete`

### Risk (Risiken)
- `risk.view`, `risk.create`, `risk.edit`, `risk.delete`, `risk.approve`, `risk.export`

### Asset (Assets)
- `asset.view`, `asset.create`, `asset.edit`, `asset.delete`, `asset.export`

### Incident (Vorfälle)
- `incident.view`, `incident.create`, `incident.edit`, `incident.delete`, `incident.approve`

### Control (Controls)
- `control.view`, `control.create`, `control.edit`, `control.delete`

### Audit (Audits)
- `audit.view`, `audit.create`, `audit.edit`, `audit.delete`, `audit.approve`

### Compliance
- `compliance.view`, `compliance.edit`, `compliance.export`

### Report (Berichte)
- `report.view`, `report.create`, `report.export`

## Programmierung mit Berechtigungen

### In Controllern

```php
// Berechtigungsprüfung mit Attribut
#[IsGranted('ROLE_ADMIN')]
class AdminController extends AbstractController
{
    // ...
}

// Einzelne Berechtigungsprüfung
public function someAction(Risk $risk): Response
{
    $this->denyAccessUnlessGranted('risk.edit', $risk);
    // ...
}

// Manuelle Prüfung
if ($this->isGranted('risk.delete', $risk)) {
    // Benutzer kann Risiko löschen
}
```

### In Twig-Templates

```twig
{% if is_granted('ROLE_ADMIN') %}
    <a href="{{ path('admin_panel') }}">Admin-Bereich</a>
{% endif %}

{% if is_granted('risk.edit', risk) %}
    <a href="{{ path('risk_edit', {id: risk.id}) }}">Bearbeiten</a>
{% endif %}
```

### Eigene Security Voters erstellen

```php
namespace App\Security\Voter;

use App\Entity\Risk;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class RiskVoter extends Voter
{
    public const EDIT = 'risk.edit';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::EDIT && $subject instanceof Risk;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Risk $risk */
        $risk = $subject;

        // Admins können alles bearbeiten
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        // Benutzer mit der Berechtigung können bearbeiten
        return $user->hasPermission(self::EDIT);
    }
}
```

## Benutzerimpersonierung

Super-Admins können andere Benutzer impersonieren:

```
# Als anderer Benutzer anmelden
https://your-domain.com/?_switch_user=user@example.com

# Zurück zum Original-Benutzer wechseln
https://your-domain.com/?_switch_user=_exit
```

## Sicherheitshinweise

1. **Passwörter**: Verwenden Sie starke Passwörter für lokale Benutzer
2. **Azure Secrets**: Bewahren Sie Azure Client Secrets sicher auf (verwenden Sie `.env.local`, nicht `.env`)
3. **SAML Zertifikate**: Schützen Sie SP Private Keys
4. **HTTPS**: Verwenden Sie immer HTTPS in Produktion
5. **CSRF**: CSRF-Schutz ist standardmäßig aktiviert
6. **Remember Me**: Remember-Me-Cookies verwenden einen sicheren Secret
7. **Sitzungsverwaltung**: Symfony verwaltet Sitzungen sicher

## Troubleshooting

### OAuth-Fehler

```
Invalid state parameter
```
- Lösung: Löschen Sie Browser-Cookies und versuchen Sie es erneut

### SAML-Fehler

```
SAML authentication failed
```
- Überprüfen Sie SAML_IDP_CERT Konfiguration
- Überprüfen Sie ACS URL in Azure AD
- Aktivieren Sie Debug-Modus: `APP_ENV=dev`

### Berechtigungsfehler

```
Access Denied
```
- Überprüfen Sie Benutzerrollen mit: `php bin/console debug:container --env-vars`
- Prüfen Sie Berechtigungen in der Datenbank
- Führen Sie `php bin/console app:setup-permissions --reset` aus

## Support

Bei Problemen oder Fragen:
1. Überprüfen Sie die Logs: `var/log/dev.log` oder `var/log/prod.log`
2. Aktivieren Sie den Debug-Modus: `APP_ENV=dev`
3. Konsultieren Sie die Symfony-Dokumentation: https://symfony.com/doc/current/security.html

## Lizenz

Proprietary - Alle Rechte vorbehalten
