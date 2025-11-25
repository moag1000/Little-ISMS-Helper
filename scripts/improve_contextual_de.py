#!/usr/bin/env python3
"""
Improve context-sensitive German translations.
"""
import re
import sys
from pathlib import Path

# Context-aware translation mappings
CONTEXT_TRANSLATIONS = {
    # Actions & Buttons
    'View Critical': 'Kritische anzeigen',
    'View Details': 'Details anzeigen',
    'View Structure': 'Struktur anzeigen',
    'View All': 'Alle anzeigen',
    'Add New': 'Neu anlegen',
    'Set Parent': 'Muttergesellschaft zuordnen',
    'Update Governance': 'Governance aktualisieren',
    'Remove Parent': 'Muttergesellschaft entfernen',
    'Submit For Assessment': 'Zur Bewertung einreichen',
    'Notify Subjects': 'Betroffene Personen benachrichtigen',
    'Update Status': 'Status aktualisieren',
    'Create New': 'Neu erstellen',
    'Mark Resolved': 'Als gelöst markieren',
    'Request Approval': 'Genehmigung anfordern',
    'Log Activity': 'Aktivität protokollieren',

    # Document Management
    'Total Documents': 'Gesamtanzahl Dokumente',
    'Total Size': 'Gesamtgröße',
    'All Documents': 'Alle Dokumente',
    'Document Types': 'Dokumenttypen',
    'Recent Documents': 'Neueste Dokumente',
    'Related Documents': 'Zugehörige Dokumente',
    'File Size': 'Dateigröße',
    'File Type': 'Dateityp',
    'Upload Date': 'Upload-Datum',
    'Last Modified': 'Zuletzt geändert',
    'Document Owner': 'Dokumentverantwortlicher',
    'Access Level': 'Zugriffsstufe',

    # Incident Management
    'Incident Response': 'Incident-Response',
    'Root Cause': 'Grundursache',
    'Lessons Learned': 'Gelernte Lektionen',
    'Action Taken': 'Durchgeführte Maßnahme',
    'Resolution Time': 'Lösungszeit',
    'Response Team': 'Response-Team',
    'Incident Type': 'Vorfalltyp',
    'Affected Systems': 'Betroffene Systeme',
    'Business Impact': 'Geschäftliche Auswirkung',

    # Context/Organization
    'External Context': 'Externer Kontext',
    'Internal Context': 'Interner Kontext',
    'Stakeholder Needs': 'Stakeholder-Anforderungen',
    'Interested Parties': 'Interessierte Parteien',
    'Legal Requirements': 'Rechtliche Anforderungen',
    'Regulatory Requirements': 'Regulatorische Anforderungen',
    'Contractual Obligations': 'Vertragliche Verpflichtungen',

    # MFA/Authentication
    'Two Factor': 'Zwei-Faktor',
    'Backup Codes': 'Backup-Codes',
    'Recovery Codes': 'Wiederherstellungscodes',
    'Enable Mfa': 'MFA aktivieren',
    'Disable Mfa': 'MFA deaktivieren',
    'Verify Code': 'Code überprüfen',
    'Setup Complete': 'Einrichtung abgeschlossen',
    'Scan Code': 'Code scannen',

    # Audit specific
    'Lead Auditor': 'Leitender Auditor',
    'Audit Team': 'Audit-Team',
    'Audit Scope': 'Audit-Umfang',
    'Audit Findings': 'Audit-Feststellungen',
    'Corrective Actions': 'Korrekturmaßnahmen',
    'Follow Up': 'Nachverfolgung',
    'Audit Trail': 'Audit-Trail',
    'Evidence Collected': 'Gesammelte Nachweise',

    # Status & States
    'Pending Authority': 'Meldung an Behörde ausstehend',
    'Active Users': 'Aktive Benutzer',
    'Inactive Users': 'Inaktive Benutzer',
    'High Risks': 'Hohe Risiken',
    'Critical Assets': 'Kritische Assets',
    'Special Categories': 'Besondere Kategorien personenbezogener Daten',
    'Not Started': 'Nicht begonnen',
    'In Progress': 'In Bearbeitung',
    'Completed': 'Abgeschlossen',
    'On Hold': 'Pausiert',
    'Cancelled': 'Abgebrochen',

    # Fields & Labels
    'Business Impact': 'Geschäftliche Auswirkung',
    'Current Value': 'Aktueller Wert',
    'Asset Count': 'Anzahl Assets',
    'Last Review': 'Letzte Überprüfung',
    'Next Review': 'Nächste Überprüfung',
    'Time Remaining': 'Verbleibende Zeit',
    'Affected Subjects': 'Betroffene Personen',
    'Corporate Notes': 'Konzernhinweise',
    'Azure Tenant ID': 'Azure-Mandanten-ID',
    'Business Process': 'Geschäftsprozess',
    'Process Owner': 'Prozessverantwortlicher',
    'Created By': 'Erstellt von',
    'Updated By': 'Aktualisiert von',
    'Created At': 'Erstellt am',
    'Updated At': 'Aktualisiert am',
    'Due Date': 'Fälligkeitsdatum',
    'Start Date': 'Startdatum',
    'End Date': 'Enddatum',

    # BCM/BCP specific
    'Bc Team': 'BC-Team',
    'Activation Criteria': 'Aktivierungskriterien',
    'Roles Responsibilities': 'Rollen und Verantwortlichkeiten',
    'Recovery Procedures': 'Wiederherstellungsverfahren',
    'Communication Plan': 'Kommunikationsplan',
    'Internal Communication': 'Interne Kommunikation',
    'External Communication': 'Externe Kommunikation',
    'Backup Recovery': 'Backup und Wiederherstellung',
    'Version Review': 'Versionsüberprüfung',
    'Rto Analysis': 'RTO-Analyse',
    'Rto Category': 'RTO-Kategorie',
    'Dependency Insights': 'Abhängigkeitsanalyse',
    'Business Continuity': 'Geschäftskontinuität',
    'Disaster Recovery': 'Disaster Recovery',
    'Alternative Site': 'Alternativstandort',

    # Risk Management
    'Risk Value Threshold': 'Risikobewertungs-Schwellenwert',
    'Total Identified': 'Gesamt identifiziert',
    'Risk Score': 'Risikobewertung',
    'Risk Level': 'Risikostufe',
    'Risk Owner': 'Risikoverantwortlicher',
    'Risk Treatment': 'Risikobehandlung',
    'Residual Risk': 'Restrisiko',
    'Inherent Risk': 'Inhärentes Risiko',

    # Privacy/GDPR
    'Data Subject': 'Betroffene Person',
    'Data Subjects': 'Betroffene Personen',
    'Processing Scale': 'Umfang der Verarbeitung',
    'Legal Basis': 'Rechtsgrundlage',
    'Processing Activity': 'Verarbeitungstätigkeit',
    'Data Categories': 'Datenkategorien',
    'Retention Period': 'Aufbewahrungsfrist',
    'Third Countries': 'Drittländer',

    # Generic terms
    'Title': 'Titel',
    'Description': 'Beschreibung',
    'Message': 'Nachricht',
    'Hint': 'Hinweis',
    'Text': 'Text',
    'Info': 'Information',
    'Label': 'Bezeichnung',
    'Empty': 'Keine Einträge vorhanden',
    'No Data': 'Keine Daten vorhanden',
    'No Items': 'Keine Einträge',
    'None': 'Keine',
    'Unknown': 'Unbekannt',
    'Not Applicable': 'Nicht anwendbar',
    'Not Available': 'Nicht verfügbar',
    'Not Set': 'Nicht gesetzt',

    # Compliance specific
    'Coverage Analysis': 'Abdeckungsanalyse',
    'Gap Analysis': 'Gap-Analyse',
    'Compliance Score': 'Compliance-Score',
    'Implementation Status': 'Umsetzungsstatus',
    'Mapping Quality': 'Zuordnungsqualität',
    'Requirements Coverage': 'Anforderungsabdeckung',

    # SOA specific
    'Control Implementation': 'Maßnahmenumsetzung',
    'Implementation Details': 'Umsetzungsdetails',
    'Implementation Plan': 'Umsetzungsplan',
    'Applicable Controls': 'Anwendbare Maßnahmen',
    'Not Applicable Controls': 'Nicht anwendbare Maßnahmen',

    # BC Exercises
    'Exercise Type': 'Übungstyp',
    'Exercise Date': 'Übungsdatum',
    'Participants List': 'Teilnehmerliste',
    'Success Rating': 'Erfolgsbewertung',
    'Areas Improvement': 'Verbesserungsbereiche',
    'Next Exercise': 'Nächste Übung',

    # Business Process
    'Process Description': 'Prozessbeschreibung',
    'Process Owner': 'Prozessverantwortlicher',
    'Process Flow': 'Prozessablauf',
    'Input Output': 'Ein- und Ausgaben',
    'Key Activities': 'Hauptaktivitäten',

    # More common patterns
    'Show All': 'Alle anzeigen',
    'Hide All': 'Alle ausblenden',
    'Expand All': 'Alle aufklappen',
    'Collapse All': 'Alle zuklappen',
    'Select All': 'Alle auswählen',
    'Deselect All': 'Alle abwählen',
    'Clear All': 'Alle löschen',
    'Reset All': 'Alle zurücksetzen',
    'Apply Filter': 'Filter anwenden',
    'Clear Filter': 'Filter löschen',
    'Sort By': 'Sortieren nach',
    'Group By': 'Gruppieren nach',
    'Search Results': 'Suchergebnisse',
    'No Results Found': 'Keine Ergebnisse gefunden',
    'Loading': 'Laden...',
    'Please Wait': 'Bitte warten',
    'Success': 'Erfolg',
    'Failed': 'Fehlgeschlagen',
    'Warning': 'Warnung',
    'Error Message': 'Fehlermeldung',
    'Confirmation Required': 'Bestätigung erforderlich',
}

def improve_line(line):
    """Improve a single line if it contains untranslated English."""
    # Match YAML key: value pairs
    match = re.match(r'^(\s*)([\w.]+):\s*(["\']?)(.+?)(["\']?)\s*$', line)
    if not match:
        return line

    indent, key, quote_start, value, quote_end = match.groups()

    # Check if value is in our translation dictionary
    if value in CONTEXT_TRANSLATIONS:
        new_value = CONTEXT_TRANSLATIONS[value]
        # Preserve quotes if they were there
        if quote_start or any(c in new_value for c in [':', ',', '#', '-']):
            return f"{indent}{key}: '{new_value}'\n"
        else:
            return f"{indent}{key}: {new_value}\n"

    return line

def improve_file(file_path):
    """Improve translations in a single file."""
    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    improved_lines = [improve_line(line) for line in lines]

    changes = sum(1 for old, new in zip(lines, improved_lines) if old != new)

    if changes > 0:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.writelines(improved_lines)

    return changes

def main():
    translations_dir = Path('translations')

    total_changes = 0
    files_changed = 0

    for yaml_file in sorted(translations_dir.glob('*.de.yaml')):
        changes = improve_file(yaml_file)
        if changes > 0:
            print(f"{yaml_file.name}: {changes} improvements")
            total_changes += changes
            files_changed += 1

    print(f"\nTotal: {total_changes} improvements across {files_changed} files")

if __name__ == '__main__':
    main()
