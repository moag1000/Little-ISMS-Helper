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
    'Add New': 'Neu anlegen',
    'Set Parent': 'Muttergesellschaft zuordnen',
    'Update Governance': 'Governance aktualisieren',
    'Remove Parent': 'Muttergesellschaft entfernen',
    'Submit For Assessment': 'Zur Bewertung einreichen',
    'Notify Subjects': 'Betroffene Personen benachrichtigen',
    'Update Status': 'Status aktualisieren',
    'Create New': 'Neu erstellen',

    # Status & States
    'Pending Authority': 'Meldung an Behörde ausstehend',
    'Active Users': 'Aktive Benutzer',
    'Inactive Users': 'Inaktive Benutzer',
    'High Risks': 'Hohe Risiken',
    'Critical Assets': 'Kritische Assets',
    'Special Categories': 'Besondere Kategorien personenbezogener Daten',

    # Fields & Labels
    'Business Impact': 'Geschäftliche Auswirkung',
    'Current Value': 'Aktueller Wert',
    'Asset Count': 'Anzahl Assets',
    'Last Review': 'Letzte Überprüfung',
    'Time Remaining': 'Verbleibende Zeit',
    'Affected Subjects': 'Betroffene Personen',
    'Corporate Notes': 'Konzernhinweise',
    'Azure Tenant ID': 'Azure-Mandanten-ID',

    # BCM/BCP specific
    'Bc Team': 'BC-Team',
    'Activation Criteria': 'Aktivierungskriterien',
    'Roles Responsibilities': 'Rollen und Verantwortlichkeiten',
    'Recovery Procedures': 'Wiederherstellungsverfahren',
    'Communication Plan': 'Kommunikationsplan',
    'Internal Communication': 'Interne Kommunikation',
    'Backup Recovery': 'Backup und Wiederherstellung',
    'Version Review': 'Versionsüberprüfung',
    'Rto Analysis': 'RTO-Analyse',
    'Rto Category': 'RTO-Kategorie',
    'Dependency Insights': 'Abhängigkeitsanalyse',

    # Risk Management
    'Risk Value Threshold': 'Risikobewertungs-Schwellenwert',
    'Total Identified': 'Gesamt identifiziert',

    # Privacy/GDPR
    'Data Subject': 'Betroffene Person',
    'Data Subjects': 'Betroffene Personen',
    'Processing Scale': 'Umfang der Verarbeitung',
    'Legal Basis': 'Rechtsgrundlage',

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
