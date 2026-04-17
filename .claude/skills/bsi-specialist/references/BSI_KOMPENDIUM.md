# IT-Grundschutz-Kompendium — Bausteine

## Ueberblick

Das IT-Grundschutz-Kompendium enthaelt Bausteine mit konkreten Anforderungen und Umsetzungshinweisen. Jeder Baustein adressiert ein spezifisches Thema und enthaelt Anforderungen in drei Stufen: Basis (B), Standard (S), Hoch (H).

## Maschinenlesbare Formate

Das BSI stellt das Kompendium in maschinenlesbaren Formaten bereit:
- **XML**: Vollstaendiges Kompendium als strukturiertes XML (primaeres maschinenlesbares Format)
- **Excel**: Kreuzreferenztabellen (Bausteine <-> ISO 27001 Controls)
- **PDF**: Menschenlesbare Gesamtfassung

Download: https://www.bsi.bund.de -> IT-Grundschutz -> IT-Grundschutz-Kompendium

Die XML-Version eignet sich fuer automatisierten Import von Bausteinen, Anforderungen und Kreuzreferenzen in ISMS-Tools.

## Baustein-Schichten

### ISMS — Sicherheitsmanagement
| Baustein | Titel |
|----------|-------|
| ISMS.1 | Sicherheitsmanagement |

### ORP — Organisation und Personal
| Baustein | Titel |
|----------|-------|
| ORP.1 | Organisation |
| ORP.2 | Personal |
| ORP.3 | Sensibilisierung und Schulung |
| ORP.4 | Identitaets- und Berechtigungsmanagement |
| ORP.5 | Compliance Management |

### CON — Konzeption und Vorgehensweisen
| Baustein | Titel |
|----------|-------|
| CON.1 | Kryptokonzept |
| CON.2 | Datenschutz |
| CON.3 | Datensicherungskonzept |
| CON.6 | Loeschen und Vernichten |
| CON.7 | Informationssicherheit auf Auslandsreisen |
| CON.8 | Software-Entwicklung |
| CON.9 | Informationsaustausch |
| CON.10 | Entwicklung von Webanwendungen |
| CON.11 | Geheimschutz |

### OPS — Betrieb
| Baustein | Titel |
|----------|-------|
| OPS.1.1.1 | Allgemeiner IT-Betrieb |
| OPS.1.1.2 | Ordnungsgemaesse IT-Administration |
| OPS.1.1.3 | Patch- und Aenderungsmanagement |
| OPS.1.1.4 | Schutz vor Schadprogrammen |
| OPS.1.1.5 | Protokollierung |
| OPS.1.1.6 | Software-Tests und -Freigaben |
| OPS.1.2.2 | Archivierung |
| OPS.1.2.4 | Telearbeit |
| OPS.1.2.5 | Fernwartung |
| OPS.1.2.6 | NTP-Zeitsynchronisation |
| OPS.2.1 | Outsourcing fuer Kunden |
| OPS.2.2 | Cloud-Nutzung |
| OPS.2.3 | Nutzung von Outsourcing |

### DER — Detektion und Reaktion
| Baustein | Titel |
|----------|-------|
| DER.1 | Detektion von sicherheitsrelevanten Ereignissen |
| DER.2.1 | Behandlung von Sicherheitsvorfaellen |
| DER.2.2 | Vorsorge fuer die IT-Forensik |
| DER.3.1 | Audits und Revisionen |
| DER.3.2 | Revisionen auf Basis des Leitfadens IS-Revision |
| DER.4 | Notfallmanagement |

### APP — Anwendungen
| Baustein | Titel |
|----------|-------|
| APP.1.1 | Office-Produkte |
| APP.1.2 | Webbrowser |
| APP.1.4 | Mobile Anwendungen |
| APP.2.1 | Allgemeiner Verzeichnisdienst |
| APP.2.2 | Active Directory Domain Services |
| APP.2.3 | OpenLDAP |
| APP.3.1 | Webanwendungen und Webservices |
| APP.3.2 | Webserver |
| APP.3.4 | Samba |
| APP.3.6 | DNS-Server |
| APP.4.2 | SAP-ERP-System |
| APP.4.3 | Relationale Datenbanksysteme |
| APP.4.4 | Kubernetes |
| APP.4.6 | SAP ABAP-Programmierung |
| APP.5.2 | Microsoft Exchange/Outlook |
| APP.5.3 | Allgemeiner E-Mail-Client/-Server |
| APP.5.4 | Unified Communications und Collaboration |
| APP.6 | Allgemeine Software |
| APP.7 | Entwicklung von Individualsoftware |

### SYS — IT-Systeme
| Baustein | Titel |
|----------|-------|
| SYS.1.1 | Allgemeiner Server |
| SYS.1.2.2 | Windows Server 2012 |
| SYS.1.2.3 | Windows Server |
| SYS.1.3 | Server unter Linux und Unix |
| SYS.1.5 | Virtualisierung |
| SYS.1.6 | Containerisierung |
| SYS.1.7 | IBM Z |
| SYS.1.8 | Speicherloesungen |
| SYS.1.9 | Terminalserver |
| SYS.2.1 | Allgemeiner Client |
| SYS.2.2.3 | Clients unter Windows |
| SYS.2.3 | Clients unter Linux und Unix |
| SYS.2.4 | Clients unter macOS |
| SYS.2.5 | Client-Virtualisierung |
| SYS.2.6 | Virtual Desktop Infrastructure |
| SYS.3.1 | Laptops |
| SYS.3.2.1 | Allgemeine Smartphones und Tablets |
| SYS.3.2.2 | Mobile Device Management (MDM) |
| SYS.3.2.3 | iOS (for Enterprise) |
| SYS.3.2.4 | Android |
| SYS.4.1 | Drucker, Kopierer, Multifunktionsgeraete |
| SYS.4.3 | Eingebettete Systeme |
| SYS.4.4 | Allgemeines IoT-Geraet |
| SYS.4.5 | Wechseldatentraeger |

### IND — Industrielle IT
| Baustein | Titel |
|----------|-------|
| IND.1 | Prozessleit- und Automatisierungstechnik |
| IND.2.1 | Allgemeine ICS-Komponente |
| IND.2.2 | Speicherprogrammierbare Steuerung (SPS) |
| IND.2.3 | Sensoren und Aktoren |
| IND.2.4 | Maschine |
| IND.2.7 | Safety-Instrumented Systems |
| IND.3.2 | Fernwartung im industriellen Umfeld |

### NET — Netze und Kommunikation
| Baustein | Titel |
|----------|-------|
| NET.1.1 | Netzarchitektur und -design |
| NET.1.2 | Netzmanagement |
| NET.2.1 | WLAN-Betrieb |
| NET.2.2 | WLAN-Nutzung |
| NET.3.1 | Router und Switches |
| NET.3.2 | Firewall |
| NET.3.3 | VPN |
| NET.3.4 | Network Access Control |
| NET.4.1 | TK-Anlagen |
| NET.4.2 | VoIP |
| NET.4.3 | Faxgeraete und Faxserver |

### INF — Infrastruktur
| Baustein | Titel |
|----------|-------|
| INF.1 | Allgemeines Gebaeude |
| INF.2 | Rechenzentrum sowie Serverraum |
| INF.5 | Raum sowie Schrank fuer technische Infrastruktur |
| INF.6 | Datentraegerarchiv |
| INF.7 | Bueroarbeitsplatz |
| INF.8 | Haeuslicher Arbeitsplatz |
| INF.9 | Mobiler Arbeitsplatz |
| INF.10 | Besprechungs-, Veranstaltungs-, Schulungsraeume |
| INF.12 | Verkabelung |
| INF.13 | Technisches Gebaeudemanagement |
| INF.14 | Gebaeudeautomation |