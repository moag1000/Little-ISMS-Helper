# Mein Tag — Inbox-Referenz (v3.5)

"Mein Tag" ist die zentrale Aufgaben-Inbox des Little ISMS Helper. Sie fasst alle
offenen Aktionspunkte aus verschiedenen Modulen in einer Ansicht zusammen, ohne dass
Nutzer jeden Bereich einzeln aufrufen muessen.

---

## Die 19 Buckets im Ueberblick

"Mein Tag" gliedert sich in vier Ausbaustufen. Alle Buckets sind in der gleichen
Ansicht sichtbar; Compliance-Manager-exklusive Buckets (CM-Buckets) werden nur fuer
Nutzer mit `ROLE_COMPLIANCE_MANAGER` angezeigt.

### V3-Original (7 Buckets)

| Bucket | Beschreibung | Quell-Modul |
|---|---|---|
| **Workflow-Inbox** | Offene Workflow-Schritte mit Frist und Ampelstatus | Workflow-System |
| **4-Eyes-Pending** | Vier-Augen-Freigaben, die auf Ihre Bestaetigung warten | Kern |
| **Audit-Findings** | Ihnen zugewiesene offene Audit-Befunde | Audit-Modul |
| **DSR-Requests** | Betroffenenrechte-Anfragen (Auskunft, Loeschung) mit Fristampel | GDPR / Privacy |
| **Policy-Acknowledgements** | Pflicht-Policies, die Sie noch gelesen+bestaetigt haben muessen | Dokument-Modul |
| **Notifications** | Ungelesene System-Benachrichtigungen | Notifications |
| **Activity-Feed (ungelesen)** | Neue Events im Activity-Feed mit direktem Link | Audit-Log / Feed |

### V4-Round-1 (3 Buckets)

| Bucket | Beschreibung | Quell-Modul |
|---|---|---|
| **Risk-Reviews** | Risiken, deren periodischer Review-Termin abgelaufen ist | Risikomanagement |
| **Patch-Deadlines** | Patches / CVEs mit CVSS >= 7.0 ohne Behebungsbestaetigung | Patch-Mgmt |
| **Supplier-Reviews** | Lieferanten mit ueberfaelliger jaehrlicher Neubewertung | Lieferanten |

### V4-Round-2 (6 Buckets)

| Bucket | Beschreibung | Quell-Modul |
|---|---|---|
| **Control-Evidence-Due** | Controls mit abgelaufener Nachweis-Gueltigkeit | SoA / Controls |
| **Training-Due** | Pflichtsschulungen, die Sie noch nicht abgeschlossen haben | Training |
| **Incident-Follow-Ups** | Incidents mit offenen Nachverfolgungspunkten (Lessons Learned) | Incident |
| **Management-Reviews** | Management-Review-Sitzungen mit Ihrer Teilnahmepflicht | Management Review |
| **BC-Plan-Tests** | BC-Uebungen mit ueberfaelligem Testdatum | BCM |
| **DPIA-Deadlines** | DPIAs mit offener DPO-Pruefung oder ablaufender Gueltigkeit | GDPR / Privacy |

### V4-EF-7 CM-Only (3 Buckets)

Diese Buckets sind ausschliesslich fuer Nutzer mit `ROLE_COMPLIANCE_MANAGER` sichtbar.

| Bucket | Beschreibung | Quell-Modul |
|---|---|---|
| **Framework-Gap-Alerts** | Frameworks mit Compliance-Score unter dem konfigurierten Schwellenwert | Compliance / Wizard |
| **Mapping-Review-Queue** | Cross-Framework-Mappings im Status `review` mit Ihrer Freigabe ausstehend | Compliance-Mapping |
| **Cert-Bundle-Preflight** | Zertifizierungspakete mit offenen Pflichtdokumenten oder abgelaufenen Nachweisen | Compliance / Reports |

---

## Visibility-Gating

| Bedingung | Verhalten |
|---|---|
| Nutzer hat `ROLE_COMPLIANCE_MANAGER` | Alle 19 Buckets sichtbar |
| Nutzer hat `ROLE_AUDITOR` | 16 Buckets; CM-Only-Buckets ausgeblendet |
| Normaler Nutzer (USER / MANAGER) | 16 Buckets; DSR, Cert-Bundle und Mapping-Review nur wenn Tenant aktiv |
| Modul deaktiviert (z. B. `bcm` inaktiv) | BC-Plan-Tests-Bucket wird automatisch ausgeblendet |

Das Gating erfolgt ueber `ModuleConfigurationService` und Symfony Security Voter.
Kein Bucket ist hartkodiert sichtbar; jeder prueft beim Laden seinen Modul-Status.

---

## Workflow-Inbox-Aggregation

Der Workflow-Inbox-Bucket aggregiert alle aktiven Workflow-Instanzen, bei denen der
angemeldete Nutzer als naechster Approver eingetragen ist. Die Darstellung zeigt:

- Workflow-Typ (Datenpannen-Meldung / Incident-Response / Risikobehandlung / DPIA)
- Schritt-Name und Faelligkeit
- Ampelfarbe (grun / gelb / rot) basierend auf verbleibender Zeit bis zur Frist
- Direkt-Link auf die Entitaet (DataBreach, Incident, Risk, DPIA)

Mehrere Workflow-Instanzen desselben Typs werden gebuendelt angezeigt
("3 offene Datenpannen-Schritte"), ausklappbar auf Einzelansicht.

---

## Auto-Reactions Integration

Wenn ein Auto-Reaction-Listener einen Workflow-Schritt automatisch weiterschalten will
(z. B. weil alle Pflichtfelder belegt sind), erscheint in "Mein Tag" kein manueller
Freigabepunkt mehr -- der Bucket-Eintrag verschwindet, sobald die automatische
Progression erfolgt ist.

Fuer Faelle, in denen die Auto-Reaction-Bedingung noch nicht erfuellt ist, zeigt
"Mein Tag" einen Hinweis: "Noch X Felder ausreichend fuer automatische Freigabe."
Dieser Hinweis verlinkt direkt auf das Formular der betroffenen Entitaet.

---

## Notifications-Bucket

Der Notifications-Bucket blendet die letzten ungelesenen System-Benachrichtigungen
ein (max. 10 in der kompakten Ansicht, Vollansicht per Klick). Benachrichtigungen
entstehen durch:

- Neue Workflow-Zuweisungen
- Eskalationen (Frist gerissen)
- Policy-Updates mit Pflicht-Ack
- Automatisch ausgeloeste Events (Auto-Reactions)

Alle Benachrichtigungen koennen direkt aus "Mein Tag" als gelesen markiert werden.

---

## Technische Referenz

- Controller: `src/Controller/MeinTagController.php`
- Bucket-Aggregator: `src/Service/MeinTagAggregatorService.php`
- Template: `templates/mein_tag/index.html.twig`
- Route: `/{locale}/mein-tag`

---

## Verwandte Dokumente

- `docs/user-guide/ACTIVITY_FEED.md` — Activity-Feed-Vollansicht
- `docs/user-guide/PERSONA_DASHBOARDS.md` — Rollenspezifische Dashboards
- `docs/WORKFLOW_AUTO_PROGRESSION.md` — Automatische Workflow-Progression
