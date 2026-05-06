# Compliance Workflow: Von ISO 27001 zu NIS2

> Visuelle End-to-End-Sicht: [Sichtwechsel — Compliance-Manager](sichtwechsel/compliance-manager.md)

## Szenario
Du hast ISO 27001 vollständig implementiert und möchtest jetzt auch NIS2 erfüllen.

![Compliance-Wizard für Framework-Onboarding](sichtwechsel/img/compliance-manager/compliance-wizard.png)

## 🎯 Schritt-für-Schritt Anleitung

### 1. NIS2 Framework laden

**Navigation:** Admin Panel → Compliance Frameworks (`http://127.0.0.1:8000/de/admin/compliance`)

1. Scrolle zu **NIS2** Framework Karte
2. Klicke auf "⬇️ Load Framework"
3. Bestätige die Aktion
4. Warte bis "✅ Successfully Loaded" erscheint

**Was passiert:**
- System lädt ~80 NIS2 Anforderungen
- Erstellt automatisch Cross-Framework Mappings zu ISO 27001
- Berechnet initiale Erfüllungsgrade basierend auf existierenden Controls

---

### 2. Gap Analysis durchführen

**Navigation:** Compliance → Requirements (`http://127.0.0.1:8000/de/compliance/requirement`)

1. Wähle im Filter **"NIS2"** aus
2. Sortiere nach **Fulfillment** (aufsteigend)
3. Identifiziere Anforderungen mit **0% oder niedrigem Fulfillment**

**Beispiel-Ansicht:**
```
┌─────────────┬──────────────────────────┬────────────┬──────────────┐
│ ID          │ Title                    │ Fulfillment│ Applicable   │
├─────────────┼──────────────────────────┼────────────┼──────────────┤
│ NIS2-4.1    │ Incident Reporting       │ 95% ████   │ Yes          │
│ NIS2-4.2    │ Cyber Threat Handling    │ 85% ███    │ Yes          │
│ NIS2-5.1    │ Security Measures        │ 45% ██     │ Yes ⚠️       │
│ NIS2-6.1    │ Supply Chain Security    │ 20% █      │ Yes ⚠️       │
│ NIS2-7.1    │ Network Segmentation     │ 0%         │ Yes ❌       │
└─────────────┴──────────────────────────┴────────────┴──────────────┘
```

---

### 3. Cross-Framework Mapping prüfen

**Navigation:** Compliance → Mappings (`http://127.0.0.1:8000/de/compliance/mapping`)

![Mapping-Hub](sichtwechsel/img/compliance-manager/mapping-hub.png)

1. Filtere nach **Source Framework: ISO 27001**
2. Filtere nach **Target Framework: NIS2**
3. Prüfe welche Controls bereits mapped sind

**Beispiel Mappings:**
```
ISO 27001 A.5.24 (Incident Management Planning)
    ↓ 80% Full Mapping
NIS2-4.1 (Incident Reporting)
    → Bereits teilweise erfüllt durch existierende Controls

ISO 27001 A.8.30 (Network Segmentation)
    ↓ 60% Partial Mapping
NIS2-7.1 (Network Segmentation)
    → Zusätzliche Maßnahmen erforderlich
```

---

### 4. Fehlende Anforderungen erfüllen

Für jede NIS2 Anforderung mit niedrigem Fulfillment:

#### Option A: Existierende Controls erweitern

**Navigation:** Compliance → Requirements → [Klick auf Anforderung] → Mapped Controls

1. Öffne NIS2 Anforderung (z.B. NIS2-7.1)
2. Prüfe "Mapped Controls" Sektion
3. Wenn ISO 27001 Controls gemappt sind:
   - Öffne das gemappte Control
   - Prüfe Implementation Status
   - Erweitere Control um NIS2-spezifische Aspekte
   - Update Implementation Percentage

#### Option B: Neue Controls erstellen

Wenn keine passenden Controls existieren:

1. **Navigation:** Controls → Statement of Applicability
2. Klicke "➕ New Control"
3. Erstelle NIS2-spezifisches Control
4. Mappe es zur NIS2 Anforderung
5. Mappe es ggf. auch zu ISO 27001 (falls relevant)

---

### 5. Fortschritt tracken

**Navigation:** Admin → Compliance → Statistics (`http://127.0.0.1:8000/de/admin/compliance/statistics`)

Dort siehst du:

```
📊 Compliance Overview

┌──────────────┬───────────────┬─────────────┬──────────────┐
│ Framework    │ Requirements  │ Compliant   │ Percentage   │
├──────────────┼───────────────┼─────────────┼──────────────┤
│ ISO 27001    │ 114           │ 114         │ 100% ✅      │
│ NIS2         │ 82            │ 48          │ 58% 🟡       │
│ GDPR         │ 45            │ 42          │ 93% ✅       │
└──────────────┴───────────────┴─────────────┴──────────────┘
```

---

### 6. Quick Updates nutzen

Für schnelle Fortschritts-Updates:

**Navigation:** Compliance → Requirements → [Anforderung öffnen]

Rechte Sidebar nutzen:

```
┌──────────────────────────┐
│ Fulfillment Status       │
├──────────────────────────┤
│ Current: 45%             │
│                          │
│ [Slider: 0% ━━━━━ 100%] │
│                          │
│ ☑ Applicable             │
│                          │
│ [Quick Update Button]    │
└──────────────────────────┘
```

---

## 🎯 Beispiel: NIS2-7.1 Network Segmentation

### Schritt 1: Anforderung prüfen
```
NIS2-7.1: Network Segmentation
Fulfillment: 0%
Mapped to: ISO 27001 A.8.30 (Partial, 60%)
```

### Schritt 2: ISO Control prüfen
```
ISO 27001 A.8.30: Network Segmentation
Implementation: 75%
Status: Implemented

Current Controls:
✓ VLANs für Departments
✓ Firewall Rules
✗ OT/IT Segmentation (fehlt für NIS2!)
✗ DMZ für Internet-facing Systems
```

### Schritt 3: Control erweitern
1. Öffne Control A.8.30
2. Füge hinzu:
   - OT/IT Network Segmentation
   - DMZ Implementation
   - Network Access Controls
3. Update Implementation auf 95%

### Schritt 4: Mapping aktualisieren
```
ISO 27001 A.8.30 (Network Segmentation)
    ↓ 95% Full Mapping (vorher 60%)
NIS2-7.1 (Network Segmentation)
    → Fulfillment jetzt 95%
```

---

## 📈 Typische Wiederverwendung ISO 27001 → NIS2

Basierend auf Cross-Framework Analysis:

| NIS2 Bereich | ISO 27001 Wiederverwendung | Gap |
|--------------|---------------------------|-----|
| **Governance** | 90% | Spezifische Rollen (CISO, etc.) |
| **Risk Management** | 95% | NIS2 Threat Intelligence |
| **Incident Response** | 85% | 24h Meldepflicht |
| **Business Continuity** | 90% | Krisenkommunikation |
| **Supply Chain** | 60% | ⚠️ Supplier Assessments erweitern |
| **Network Security** | 70% | ⚠️ OT Security hinzufügen |
| **Access Control** | 95% | MFA Requirements |
| **Cryptography** | 90% | Quantum-safe Crypto |

**Durchschnitt:** ~80% der ISO 27001 Controls sind für NIS2 nutzbar!

---

## ✅ Praktische Checkliste

- [ ] NIS2 Framework im Admin Panel laden
- [ ] Gap Analysis durchführen (Filter in Requirements)
- [ ] Cross-Framework Mappings prüfen
- [ ] Anforderungen mit 0-50% Fulfillment identifizieren
- [ ] Für jede Anforderung:
  - [ ] Gemappte ISO 27001 Controls prüfen
  - [ ] Controls erweitern oder neue erstellen
  - [ ] Fulfillment % updaten
- [ ] Fortschritt in Statistics Dashboard tracken
- [ ] Bei 100%: Audit Evidence sammeln

---

## 🔧 Hilfreiche Features

### Transitive Compliance
**Navigation:** Compliance → Transitive Analysis

Zeigt automatisch:
- Welche Anforderungen durch gleiche Controls erfüllt werden
- Cluster von zusammenhängenden Requirements
- Optimierungspotenzial (ein Control für mehrere Frameworks)

### Compliance Radar
**Navigation:** Analytics → Dashboard**

Visueller Überblick über alle Frameworks:
```
        ISO 27001
           /|\
          / | \
         /  |  \
    NIS2---+---GDPR
         \  |  /
          \ | /
           \|/
         TISAX
```

---

## 💡 Pro-Tipps

1. **Start mit High-Priority Requirements**
   - Filtere nach Priority: "Critical" oder "High"
   - Diese haben oft rechtliche Konsequenzen

2. **Nutze Bulk Operations**
   - Markiere mehrere ähnliche Requirements
   - Update Applicability in einem Schritt

3. **Evidence Management**
   - Für jeden Control: Documents hochladen
   - Audit Trail wird automatisch erstellt

4. **Regular Reviews**
   - Setze Review Dates für Controls
   - System erinnert dich an fällige Reviews

5. **Mapping Quality**
   - Prüfe "Confidence" Score bei Mappings
   - Niedrige Confidence → Manual Review nötig

---

## 📞 Support

Bei Fragen:
- Check: `docs/COMPLIANCE_FRAMEWORKS_GUIDE.md`
- CLI Commands: `php bin/console app:compliance:load --help`
- UI: Admin Panel → Compliance → Statistics
