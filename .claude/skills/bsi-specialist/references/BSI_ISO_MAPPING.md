# ISO 27001 auf Basis IT-Grundschutz — Mapping

## Ueberblick

Dieses Mapping verbindet ISO 27001:2022 Annex A Controls mit IT-Grundschutz-Bausteinen. Wird fuer Zertifizierung "ISO 27001 auf Basis IT-Grundschutz" benoetigt.

## Zertifizierungspfade

| Pfad | Grundlage | Zertifizierer |
|------|-----------|---------------|
| ISO 27001 (nativ) | ISO 27001:2022 direkt | Akkreditierte Zertifizierungsstellen |
| ISO 27001 auf Basis IT-Grundschutz | BSI 200-1/2/3 + Kompendium | BSI-zertifizierte Auditoren |

## Mapping: ISO 27001 Annex A -> IT-Grundschutz

### A.5 Organisatorische Controls

| ISO 27001 | Control | IT-Grundschutz Baustein |
|-----------|---------|----------------------|
| A.5.1 | Policies for information security | ISMS.1, ORP.1 |
| A.5.2 | Information security roles | ISMS.1, ORP.1 |
| A.5.3 | Segregation of duties | ORP.1 |
| A.5.4 | Management responsibilities | ISMS.1 |
| A.5.5 | Contact with authorities | ISMS.1 |
| A.5.6 | Contact with special interest groups | ISMS.1 |
| A.5.7 | Threat intelligence | DER.1 |
| A.5.8 | IS in project management | ORP.1 |
| A.5.9 | Inventory of information | ORP.1, AM (C5) |
| A.5.10 | Acceptable use | ORP.1 |
| A.5.11 | Return of assets | ORP.2 |
| A.5.12 | Classification of information | CON.1, ORP.1 |
| A.5.13 | Labelling of information | ORP.1 |
| A.5.14 | Information transfer | CON.9, KOS (C5) |
| A.5.15 | Access control | ORP.4 |
| A.5.16 | Identity management | ORP.4 |
| A.5.17 | Authentication information | ORP.4 |
| A.5.18 | Access rights | ORP.4 |
| A.5.19 | IS in supplier relationships | OPS.2.1, OPS.2.3 |
| A.5.20 | Addressing IS in supplier agreements | OPS.2.1, OPS.2.3 |
| A.5.21 | Managing IS in ICT supply chain | OPS.2.1, DLL (C5) |
| A.5.22 | Monitoring/review of supplier services | OPS.2.1 |
| A.5.23 | IS for cloud services | OPS.2.2 |
| A.5.24 | Incident management planning | DER.2.1 |
| A.5.25 | Assessment of IS events | DER.2.1 |
| A.5.26 | Response to IS incidents | DER.2.1 |
| A.5.27 | Learning from IS incidents | DER.2.1 |
| A.5.28 | Collection of evidence | DER.2.2 |
| A.5.29 | IS during disruption | DER.4 |
| A.5.30 | ICT readiness for business continuity | DER.4, BCM (C5) |
| A.5.31 | Legal requirements | ORP.5 |
| A.5.32 | Intellectual property rights | ORP.5 |
| A.5.33 | Protection of records | ORP.5, OPS.1.2.2 |
| A.5.34 | Privacy and PII | CON.2 |
| A.5.35 | Independent review of IS | DER.3.1 |
| A.5.36 | Compliance with policies | ORP.5, DER.3.1 |
| A.5.37 | Documented operating procedures | OPS.1.1.1 |

### A.6 Personenbezogene Controls

| ISO 27001 | Control | IT-Grundschutz Baustein |
|-----------|---------|----------------------|
| A.6.1 | Screening | ORP.2 |
| A.6.2 | Terms and conditions | ORP.2 |
| A.6.3 | IS awareness/training | ORP.3 |
| A.6.4 | Disciplinary process | ORP.2 |
| A.6.5 | Responsibilities after termination | ORP.2 |
| A.6.6 | Confidentiality agreements | ORP.2, ORP.5 |
| A.6.7 | Remote working | OPS.1.2.4, INF.8, INF.9 |
| A.6.8 | IS event reporting | DER.2.1 |

### A.7 Physische Controls

| ISO 27001 | Control | IT-Grundschutz Baustein |
|-----------|---------|----------------------|
| A.7.1 | Physical security perimeters | INF.1 |
| A.7.2 | Physical entry | INF.1 |
| A.7.3 | Securing offices/rooms/facilities | INF.7, INF.10 |
| A.7.4 | Physical security monitoring | INF.1 |
| A.7.5 | Protecting against threats | INF.1 |
| A.7.6 | Working in secure areas | INF.2 |
| A.7.7 | Clear desk and clear screen | INF.7 |
| A.7.8 | Equipment siting and protection | INF.2, INF.7 |
| A.7.9 | Security of assets off-premises | INF.9 |
| A.7.10 | Storage media | SYS.4.5, CON.6 |
| A.7.11 | Supporting utilities | INF.2 |
| A.7.12 | Cabling security | INF.12 |
| A.7.13 | Equipment maintenance | OPS.1.1.1 |
| A.7.14 | Secure disposal or re-use | CON.6 |

### A.8 Technologische Controls

| ISO 27001 | Control | IT-Grundschutz Baustein |
|-----------|---------|----------------------|
| A.8.1 | User endpoint devices | SYS.2.1, SYS.3.1, SYS.3.2.1 |
| A.8.2 | Privileged access rights | ORP.4 |
| A.8.3 | Information access restriction | ORP.4, APP.* |
| A.8.4 | Access to source code | CON.8 |
| A.8.5 | Secure authentication | ORP.4, APP.* |
| A.8.6 | Capacity management | OPS (C5) |
| A.8.7 | Protection against malware | OPS.1.1.4 |
| A.8.8 | Management of technical vulns | OPS.1.1.3 |
| A.8.9 | Configuration management | OPS.1.1.1, OPS.1.1.3 |
| A.8.10 | Information deletion | CON.6 |
| A.8.11 | Data masking | CON.1 |
| A.8.12 | Data leakage prevention | CON.1, OPS.1.1.5 |
| A.8.13 | Information backup | CON.3 |
| A.8.14 | Redundancy of information processing | OPS (C5), BCM (C5) |
| A.8.15 | Logging | OPS.1.1.5 |
| A.8.16 | Monitoring activities | DER.1 |
| A.8.17 | Clock synchronization | OPS.1.2.6 |
| A.8.18 | Use of privileged utility programs | OPS.1.1.2 |
| A.8.19 | Installation of software | OPS.1.1.6 |
| A.8.20 | Networks security | NET.1.1, NET.3.2 |
| A.8.21 | Security of network services | NET.1.1 |
| A.8.22 | Segregation of networks | NET.1.1, NET.3.2 |
| A.8.23 | Web filtering | NET.3.2 |
| A.8.24 | Use of cryptography | CON.1 |
| A.8.25 | Secure development lifecycle | CON.8, CON.10 |
| A.8.26 | Application security requirements | CON.8, APP.3.1 |
| A.8.27 | Secure system architecture | CON.8 |
| A.8.28 | Secure coding | CON.8, CON.10 |
| A.8.29 | Security testing | OPS.1.1.6 |
| A.8.30 | Outsourced development | OPS.2.1 |
| A.8.31 | Separation of environments | OPS.1.1.1, OPS.1.1.6 |
| A.8.32 | Change management | OPS.1.1.3 |
| A.8.33 | Test information | OPS.1.1.6 |
| A.8.34 | Protection during audit testing | DER.3.1 |