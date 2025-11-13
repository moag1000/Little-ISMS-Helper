# Corporate Structure Integration Plan

## 🎯 Ziel

Vollständige Integration der Konzernstrukturen in alle relevanten Module, sodass:
- **Hierarchical Governance**: Daten werden vom Parent geerbt
- **Shared Governance**: Eigene Daten, Parent als Referenz
- **Independent Governance**: Vollständig isoliert

---

## 📋 Module-Analyse

### ✅ Bereits Tenant-aware (aber ohne Konzernlogik)

| Modul | Entity | Tenant-Feld | Status | Priorität |
|-------|--------|-------------|--------|-----------|
| **ISMSContext** | ISMSContext | tenant_id | ⚠️ Tenant-aware, aber keine Vererbung | **KRITISCH** |
| **Internal Audits** | InternalAudit | tenant_id | ⚠️ Kein Audit-Scope konzernweit | **HOCH** |
| **Controls (SOA)** | Control | tenant_id | ⚠️ Keine Control-Vererbung | **HOCH** |
| **Risks** | Risk | tenant_id | ⚠️ Keine Risiko-Aggregation | **MITTEL** |
| **Assets** | Asset | tenant_id | ⚠️ Keine Asset-Sichtbarkeit | **MITTEL** |
| **Processes** | BusinessProcess | tenant_id | ⚠️ Keine Prozess-Vererbung | **MITTEL** |
| **Documents** | Document | tenant_id | ⚠️ Keine Dokument-Freigabe | **NIEDRIG** |

---

## 🔧 Integration pro Modul

### 1. ISMSContext - Kontext der Organisation ⭐ KRITISCH

**Problem:**
- ISMSContext ist zwar tenant_id-basiert, aber Vererbung fehlt
- `getEffectiveISMSContext()` Service existiert, wird aber nicht überall genutzt

**Lösung:**

#### 1.1 Controller erweitern

```php
// src/Controller/ISMSContextController.php
#[Route('/isms-context/{id}', name: 'isms_context_show')]
public function show(ISMSContext $context): Response
{
    $tenant = $context->getTenant();
    $effectiveContext = $this->corporateStructureService->getEffectiveISMSContext($tenant);

    return $this->render('isms_context/show.html.twig', [
        'context' => $context,
        'effectiveContext' => $effectiveContext,
        'isInherited' => $effectiveContext->getId() !== $context->getId(),
        'parent' => $tenant->getParent(),
    ]);
}
```

#### 1.2 Template anpassen

```twig
{# templates/isms_context/show.html.twig #}
{% if isInherited %}
    <div class="alert alert-info">
        <i class="bi bi-arrow-up-right"></i>
        Dieser ISMS-Kontext wird von der Muttergesellschaft
        <a href="{{ path('tenant_management_show', {id: parent.id}) }}">{{ parent.name }}</a> geerbt.
    </div>
{% endif %}
```

#### 1.3 API erweitern

```php
// Neuer Endpoint: GET /api/isms-contexts/effective/{tenantId}
#[Route('/api/isms-contexts/effective/{tenantId}', methods: ['GET'])]
public function getEffectiveContext(int $tenantId): JsonResponse
{
    $tenant = $this->tenantRepository->find($tenantId);
    $context = $this->corporateStructureService->getEffectiveISMSContext($tenant);

    return $this->json([
        'context' => $context,
        'isInherited' => $context->getTenant()->getId() !== $tenantId,
    ]);
}
```

---

### 2. Internal Audits - Audit Scope ⭐ HOCH

**Problem:**
- Audits sind pro Tenant, aber kein konzernweiter Audit-Scope
- Parent kann nicht Audits für Subsidiaries planen

**Lösung:**

#### 2.1 Audit Scope Typen erweitern

```php
// src/Enum/AuditScopeType.php
enum AuditScopeType: string
{
    case TENANT_ONLY = 'tenant_only';        // Nur dieser Tenant
    case CORPORATE_GROUP = 'corporate_group'; // Gesamter Konzern
    case SUBSIDIARIES = 'subsidiaries';       // Alle Tochtergesellschaften
    case SPECIFIC_TENANTS = 'specific_tenants'; // Ausgewählte Tenants
}
```

#### 2.2 InternalAudit Entity erweitern

```php
#[ORM\Column(length: 30)]
private AuditScopeType $scopeType = AuditScopeType::TENANT_ONLY;

#[ORM\ManyToMany(targetEntity: Tenant::class)]
#[ORM\JoinTable(name: 'audit_scope_tenants')]
private Collection $scopeTenants;
```

#### 2.3 Audit-Planung für Konzern

```php
// src/Service/CorporateAuditService.php
class CorporateAuditService
{
    public function getAuditsForTenantTree(Tenant $tenant): array
    {
        $tenants = [$tenant, ...$tenant->getAllSubsidiaries()];
        return $this->auditRepository->findByTenants($tenants);
    }

    public function scheduleGroupAudit(Tenant $parent, array $subsidiaries, AuditData $data): InternalAudit
    {
        $audit = new InternalAudit();
        $audit->setTenant($parent);
        $audit->setScopeType(AuditScopeType::CORPORATE_GROUP);

        foreach ($subsidiaries as $sub) {
            $audit->addScopeTenant($sub);
        }

        return $audit;
    }
}
```

---

### 3. SOA (Statement of Applicability) - Controls ⭐ HOCH

**Problem:**
- Controls sind tenant-spezifisch
- Keine Vererbung von Control-Status nach Governance-Modell
- Parent-Controls nicht sichtbar für Subsidiaries

**Lösung:**

#### 3.1 ControlStatus Entity mit Vererbungslogik

```php
// src/Service/CorporateControlService.php
class CorporateControlService
{
    public function getEffectiveControlStatus(Control $control, Tenant $tenant): string
    {
        // 1. Prüfe ob Control tenant-spezifischen Status hat
        if ($control->getTenant()->getId() === $tenant->getId()) {
            return $control->getImplementationStatus();
        }

        // 2. Prüfe Governance für Controls
        $governance = $this->governanceRepository->findGovernanceForScope(
            $tenant,
            'control',
            $control->getControlId()
        );

        if (!$governance || $governance->getGovernanceModel() === GovernanceModel::INDEPENDENT) {
            return 'not_applicable';
        }

        // 3. Bei HIERARCHICAL oder SHARED: Hole Parent-Status
        $parent = $tenant->getParent();
        if ($parent) {
            $parentControl = $this->controlRepository->findOneBy([
                'tenant' => $parent,
                'controlId' => $control->getControlId()
            ]);

            if ($parentControl && $governance->getGovernanceModel() === GovernanceModel::HIERARCHICAL) {
                return $parentControl->getImplementationStatus();
            }
        }

        return 'not_implemented';
    }
}
```

#### 3.2 SOA-Übersicht erweitern

```twig
{# templates/soa/index.html.twig #}
{% for control in controls %}
    <tr class="{{ control.isInherited ? 'table-secondary' : '' }}">
        <td>{{ control.controlId }}</td>
        <td>
            {{ control.status }}
            {% if control.isInherited %}
                <span class="badge bg-info">
                    <i class="bi bi-arrow-up-right"></i> Von {{ control.inheritedFrom.name }}
                </span>
            {% endif %}
        </td>
    </tr>
{% endfor %}
```

---

### 4. Risks - Risikoaggregation ⭐ MITTEL

**Problem:**
- Risiken sind pro Tenant isoliert
- Keine Risiko-Aggregation für Konzern
- Parent sieht nicht Subsidiary-Risiken

**Lösung:**

#### 4.1 Corporate Risk Dashboard

```php
// src/Controller/CorporateRiskController.php
#[Route('/admin/corporate-risks', name: 'corporate_risk_dashboard')]
public function dashboard(): Response
{
    $user = $this->getUser();
    $tenant = $user->getTenant();

    // Wenn Parent: Zeige alle Subsidiary-Risiken
    if ($tenant->isCorporateParent()) {
        $allRisks = $this->riskRepository->findByTenantTree($tenant);
        $groupedRisks = $this->groupRisksByTenant($allRisks);

        return $this->render('corporate_risk/dashboard.html.twig', [
            'groupedRisks' => $groupedRisks,
            'aggregatedRiskLevel' => $this->calculateAggregatedRisk($allRisks),
        ]);
    }

    // Sonst: Normale Risiko-Ansicht
    return $this->redirectToRoute('risk_index');
}
```

#### 4.2 Risiko-Aggregation

```php
public function calculateAggregatedRisk(array $risks): array
{
    $levels = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];

    foreach ($risks as $risk) {
        $levels[$risk->getRiskLevel()]++;
    }

    return $levels;
}
```

---

### 5. Assets - Asset-Sichtbarkeit ⭐ MITTEL

**Problem:**
- Assets sind pro Tenant
- Shared Assets nicht unterstützt
- Keine Asset-Inventur für Konzern

**Lösung:**

#### 5.1 Asset Sharing Flag

```php
// src/Entity/Asset.php
#[ORM\Column]
private bool $isSharedWithSubsidiaries = false;

#[ORM\ManyToMany(targetEntity: Tenant::class)]
#[ORM\JoinTable(name: 'asset_shared_with_tenants')]
private Collection $sharedWithTenants;
```

#### 5.2 Asset-Sichtbarkeit Service

```php
// src/Service/CorporateAssetService.php
public function getVisibleAssets(Tenant $tenant): array
{
    $assets = $this->assetRepository->findBy(['tenant' => $tenant]);

    // Wenn Subsidiary: Hole auch Parent-Assets (wenn geteilt)
    if ($tenant->getParent()) {
        $governance = $this->governanceRepository->findGovernanceForScope($tenant, 'asset');

        if ($governance && $governance->getGovernanceModel() === GovernanceModel::SHARED) {
            $parentAssets = $this->assetRepository->findSharedAssets($tenant->getParent());
            $assets = array_merge($assets, $parentAssets);
        }
    }

    return $assets;
}
```

---

### 6. Processes - Prozess-Vererbung ⭐ MITTEL

**Problem:**
- Prozesse sind pro Tenant
- Standard-Prozesse nicht teilbar
- Keine Prozess-Templates

**Lösung:**

#### 6.1 Prozess-Templates

```php
// src/Entity/BusinessProcess.php
#[ORM\Column]
private bool $isTemplate = false;

#[ORM\Column]
private bool $isInheritableTemplate = false;
```

#### 6.2 Prozess-Vererbung

```php
// src/Service/CorporateProcessService.php
public function inheritProcessesFromParent(Tenant $subsidiary): int
{
    $parent = $subsidiary->getParent();
    if (!$parent) {
        return 0;
    }

    $governance = $this->governanceRepository->findGovernanceForScope($subsidiary, 'process');

    if ($governance && $governance->getGovernanceModel() === GovernanceModel::HIERARCHICAL) {
        $templates = $this->processRepository->findTemplates($parent);
        $count = 0;

        foreach ($templates as $template) {
            if ($template->isInheritableTemplate()) {
                $this->cloneProcessForTenant($template, $subsidiary);
                $count++;
            }
        }

        return $count;
    }

    return 0;
}
```

---

### 7. Documents - Dokument-Freigabe ⭐ NIEDRIG

**Problem:**
- Dokumente sind pro Tenant isoliert
- Keine zentrale Dokumentenbibliothek
- Parent-Policies nicht teilbar

**Lösung:**

#### 7.1 Document Visibility

```php
// src/Entity/Document.php
#[ORM\Column(length: 30)]
private DocumentVisibility $visibility = DocumentVisibility::TENANT_ONLY;
```

```php
enum DocumentVisibility: string
{
    case TENANT_ONLY = 'tenant_only';
    case CORPORATE_GROUP = 'corporate_group';
    case PUBLIC_IN_GROUP = 'public_in_group';
}
```

---

## 🔄 Implementation Reihenfolge

### Phase 1: Kritische Module (Woche 1)
1. ✅ ISMSContext - Vererbungslogik aktivieren
2. ✅ Internal Audits - Audit Scope erweitern
3. ✅ SOA/Controls - Control-Vererbung implementieren

### Phase 2: Business Logic (Woche 2)
4. ✅ Risks - Risiko-Aggregation
5. ✅ Assets - Asset-Sharing
6. ✅ Processes - Prozess-Vererbung

### Phase 3: Support-Features (Woche 3)
7. ✅ Documents - Dokument-Freigabe
8. ✅ Reporting - Konzernweite Reports
9. ✅ Dashboard - Konzern-Übersicht

---

## 📊 Datenbank-Änderungen

### Neue Tabellen

```sql
-- Audit Scope Tenants
CREATE TABLE audit_scope_tenants (
    internal_audit_id INT NOT NULL,
    tenant_id INT NOT NULL,
    PRIMARY KEY (internal_audit_id, tenant_id),
    FOREIGN KEY (internal_audit_id) REFERENCES internal_audit(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE
);

-- Asset Shared With Tenants
CREATE TABLE asset_shared_with_tenants (
    asset_id INT NOT NULL,
    tenant_id INT NOT NULL,
    PRIMARY KEY (asset_id, tenant_id),
    FOREIGN KEY (asset_id) REFERENCES asset(id) ON DELETE CASCADE,
    FOREIGN KEY (tenant_id) REFERENCES tenant(id) ON DELETE CASCADE
);
```

### Spalten hinzufügen

```sql
-- InternalAudit
ALTER TABLE internal_audit
ADD COLUMN scope_type VARCHAR(30) DEFAULT 'tenant_only';

-- Asset
ALTER TABLE asset
ADD COLUMN is_shared_with_subsidiaries BOOLEAN DEFAULT FALSE;

-- BusinessProcess
ALTER TABLE business_process
ADD COLUMN is_template BOOLEAN DEFAULT FALSE,
ADD COLUMN is_inheritable_template BOOLEAN DEFAULT FALSE;

-- Document
ALTER TABLE document
ADD COLUMN visibility VARCHAR(30) DEFAULT 'tenant_only';
```

---

## 🧪 Testing-Strategie

### Unit Tests

```php
class CorporateControlServiceTest extends KernelTestCase
{
    public function testHierarchicalGovernanceInheritsParentStatus(): void
    {
        $parent = $this->createTenant('Parent');
        $sub = $this->createTenant('Sub', parent: $parent);

        $parentControl = $this->createControl($parent, 'A.5.1', 'implemented');
        $this->createGovernance($sub, 'control', 'A.5.1', 'hierarchical');

        $status = $this->service->getEffectiveControlStatus($parentControl, $sub);

        $this->assertEquals('implemented', $status);
    }
}
```

### Integration Tests

```php
public function testCorporateAuditCanAccessAllSubsidiaries(): void
{
    $parent = $this->createTenant('Parent');
    $sub1 = $this->createTenant('Sub1', parent: $parent);
    $sub2 = $this->createTenant('Sub2', parent: $parent);

    $audit = $this->createAudit($parent, AuditScopeType::CORPORATE_GROUP);
    $audit->addScopeTenant($sub1);
    $audit->addScopeTenant($sub2);

    $findings = $this->service->performAudit($audit);

    $this->assertCount(3, $findings); // Parent + 2 Subs
}
```

---

## 🚀 Migration-Guide

### Schritt 1: Spalten hinzufügen

```bash
php bin/console make:migration
php bin/console doctrine:migrations:migrate
```

### Schritt 2: Services registrieren

Alle neuen Services werden automatisch via `autoconfigure: true` registriert.

### Schritt 3: Bestehende Daten migrieren

```sql
-- Alle InternalAudits auf TENANT_ONLY setzen (Default)
UPDATE internal_audit SET scope_type = 'tenant_only' WHERE scope_type IS NULL;

-- Assets als nicht-geteilt markieren
UPDATE asset SET is_shared_with_subsidiaries = FALSE WHERE is_shared_with_subsidiaries IS NULL;
```

---

## 📈 Performance-Überlegungen

### Caching-Strategie

```yaml
# config/packages/cache.yaml
framework:
    cache:
        pools:
            corporate.controls.cache:
                adapter: cache.adapter.redis
                default_lifetime: 3600
            corporate.risks.cache:
                adapter: cache.adapter.redis
                default_lifetime: 1800
```

### Query-Optimierung

```php
// Eager Loading für Konzern-Queries
$qb->leftJoin('t.subsidiaries', 's')
   ->addSelect('s')
   ->leftJoin('t.parent', 'p')
   ->addSelect('p');
```

---

## ✅ Checkliste

- [ ] ISMSContext: Vererbungslogik in Controller
- [ ] ISMSContext: Template-Änderungen
- [ ] ISMSContext: API-Endpoint erweitern
- [ ] Internal Audits: AuditScopeType Enum
- [ ] Internal Audits: Entity erweitern
- [ ] Internal Audits: CorporateAuditService
- [ ] SOA: CorporateControlService
- [ ] SOA: Template-Änderungen
- [ ] Risks: Corporate Risk Dashboard
- [ ] Risks: Aggregation Service
- [ ] Assets: Sharing Flag
- [ ] Assets: CorporateAssetService
- [ ] Processes: Template Flag
- [ ] Processes: Vererbung Service
- [ ] Documents: Visibility Enum
- [ ] Migration erstellen
- [ ] Tests schreiben
- [ ] Dokumentation aktualisieren

---

**Version:** 1.0.0
**Status:** Planning Phase
**Estimated Effort:** 3 Wochen (15 Personentage)
