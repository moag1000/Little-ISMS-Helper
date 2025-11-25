#!/usr/bin/env python3
r"""
Translate \0NoFill\ placeholders in YAML translation files.
Generates context-aware translations based on key names and ISO standards.
"""
import re
import sys

# Translation mappings based on key patterns - ISO 27001/27005/22301 compliant
TRANSLATIONS_DE = {
    # Common patterns
    'title': 'Titel',
    'description': 'Beschreibung',
    'name': 'Name',
    'status': 'Status',
    'created': 'Erstellt',
    'updated': 'Aktualisiert',
    'action': 'Aktion',
    'actions': 'Aktionen',
    'details': 'Details',
    'overview': 'Übersicht',
    'summary': 'Zusammenfassung',
    'type': 'Typ',
    'date': 'Datum',
    'priority': 'Priorität',
    'severity': 'Schweregrad',

    # Compliance & Framework specific (ISO 27001)
    'framework': 'Rahmenwerk',
    'frameworks': 'Rahmenwerke',
    'requirement': 'Anforderung',
    'requirements': 'Anforderungen',
    'unique_requirements': 'Einzigartige Anforderungen',
    'total_requirements': 'Gesamtanforderungen',
    'source_requirement': 'Quellanforderung',
    'target_requirements': 'Zielanforderungen',
    'mapping': 'Zuordnung',
    'mappings': 'Zuordnungen',
    'total_mappings': 'Gesamtzuordnungen',
    'partial_mappings': 'Teilweise Zuordnungen',
    'bidirectional_mappings': 'Bidirektionale Zuordnungen',
    'cross_framework_mappings': 'Rahmenwerk-übergreifende Zuordnungen',
    'mapping_strength': 'Zuordnungsstärke',
    'mapping_explanation': 'Erklärung der Zuordnung',
    'match_quality': 'Übereinstimmungsqualität',
    'full_exceeds': 'Vollständig/Übertrifft',
    'compliance_matrix': 'Compliance-Matrix',
    'compliance': 'Compliance',
    'detailed_comparison': 'Detaillierter Vergleich',
    'unique_to': 'Einzigartig für',
    'matrix': 'Matrix',
    'category': 'Kategorie',
    'categories': 'Kategorien',
    'mapped': 'Zugeordnet',
    'coverage': 'Abdeckung',
    'gap': 'Lücke',
    'gaps': 'Lücken',
    'assessment': 'Bewertung',
    'evidence': 'Nachweis',
    'implementation': 'Umsetzung',

    # Risk Management (ISO 27005)
    'risk': 'Risiko',
    'risks': 'Risiken',
    'threat': 'Gefährdung',
    'threats': 'Gefährdungen',
    'vulnerability': 'Schwachstelle',
    'vulnerabilities': 'Schwachstellen',
    'likelihood': 'Wahrscheinlichkeit',
    'impact': 'Auswirkung',
    'treatment': 'Behandlung',
    'mitigation': 'Minderung',
    'residual': 'Restrisiko',
    'inherent': 'Inhärentes Risiko',
    'owner': 'Eigentümer',
    'accepted': 'Akzeptiert',
    'mitigated': 'Gemindert',
    'transferred': 'Übertragen',
    'avoided': 'Vermieden',

    # Assets & Controls
    'asset': 'Asset',
    'assets': 'Assets',
    'control': 'Maßnahme',
    'controls': 'Maßnahmen',
    'measure': 'Maßnahme',
    'measures': 'Maßnahmen',
    'effectiveness': 'Wirksamkeit',

    # Sections
    'basic_info': 'Grundinformationen',
    'contact_info': 'Kontaktinformationen',
    'settings': 'Einstellungen',
    'configuration': 'Konfiguration',
    'general': 'Allgemein',
    'advanced': 'Erweitert',

    # Actions
    'create': 'Erstellen',
    'edit': 'Bearbeiten',
    'delete': 'Löschen',
    'save': 'Speichern',
    'cancel': 'Abbrechen',
    'export': 'Exportieren',
    'import': 'Importieren',
    'view': 'Anzeigen',
    'add': 'Hinzufügen',
    'remove': 'Entfernen',
    'update': 'Aktualisieren',

    # Status values
    'active': 'Aktiv',
    'inactive': 'Inaktiv',
    'draft': 'Entwurf',
    'pending': 'Ausstehend',
    'approved': 'Genehmigt',
    'rejected': 'Abgelehnt',
    'in_progress': 'In Bearbeitung',
    'completed': 'Abgeschlossen',
    'not_started': 'Nicht begonnen',

    # Fields
    'field': 'Feld',
    'label': 'Bezeichnung',
    'value': 'Wert',
    'comment': 'Kommentar',
    'comments': 'Kommentare',
    'note': 'Notiz',
    'notes': 'Notizen',
    'responsible': 'Verantwortlich',
    'assignee': 'Zugewiesen an',
    'due_date': 'Fälligkeitsdatum',
    'created_at': 'Erstellt am',
    'updated_at': 'Aktualisiert am',

    # Admin & Settings
    'subtitle': 'Untertitel',
    'locale': 'Sprache',
    'timezone': 'Zeitzone',
    'items_per_page': 'Einträge pro Seite',
    'locale_settings': 'Spracheinstellungen',
    'default_locale': 'Standardsprache',
    'default_locale_help': 'Die Sprache, die neuen Benutzern standardmäßig zugewiesen wird',
    'supported_locales': 'Unterstützte Sprachen',
    'supported_locales_help': 'Sprachen, die in der Anwendung verfügbar sind',
    'display_settings': 'Anzeigeeinstellungen',
    'items_per_page_help': 'Anzahl der Einträge, die pro Seite in Listen angezeigt werden',
    'timezone_help': 'Zeitzone für Datums- und Zeitangaben',
    'date_format': 'Datumsformat',
    'date_format_help': 'Format zur Anzeige von Datumsangaben',
    'datetime_format': 'Datum-/Zeitformat',
    'datetime_format_help': 'Format zur Anzeige von Datum und Uhrzeit',
    'help_text': 'Hilfetext',
    'configure': 'Konfigurieren',
    'attributions_provided': 'Lizenznachweise bereitgestellt',

    # Common actions & elements
    'new': 'Neu',
    'show': 'Anzeigen',
    'list': 'Liste',
    'index': 'Übersicht',
    'back': 'Zurück',
    'submit': 'Absenden',
    'confirm': 'Bestätigen',
    'reset': 'Zurücksetzen',
    'search': 'Suchen',
    'filter': 'Filtern',
    'sort': 'Sortieren',
    'download': 'Herunterladen',
    'upload': 'Hochladen',
    'close': 'Schließen',
    'open': 'Öffnen',
    'select': 'Auswählen',
    'deselect': 'Abwählen',
    'enable': 'Aktivieren',
    'disable': 'Deaktivieren',
    'enabled': 'Aktiviert',
    'disabled': 'Deaktiviert',
    'bcm_insights': 'BCM-Erkenntnisse',
    'by_type': 'Nach Typ',

    # User & Team
    'user': 'Benutzer',
    'users': 'Benutzer',
    'team': 'Team',
    'role': 'Rolle',
    'roles': 'Rollen',
    'permission': 'Berechtigung',
    'permissions': 'Berechtigungen',
    'password': 'Passwort',
    'username': 'Benutzername',
    'firstname': 'Vorname',
    'lastname': 'Nachname',
    'phone': 'Telefon',
    'address': 'Adresse',
    'company': 'Unternehmen',
    'position': 'Position',

    # Audit & Compliance
    'audit': 'Audit',
    'audits': 'Audits',
    'auditor': 'Auditor',
    'auditee': 'Auditierter',
    'audited': 'Auditiert',
    'finding': 'Feststellung',
    'findings': 'Feststellungen',
    'observation': 'Beobachtung',
    'observations': 'Beobachtungen',
    'recommendation': 'Empfehlung',
    'recommendations': 'Empfehlungen',
    'nonconformity': 'Nichtkonformität',
    'nonconformities': 'Nichtkonformitäten',
    'corrective': 'Korrekturmaßnahme',
    'preventive': 'Vorbeugungsmaßnahme',
    'scope': 'Geltungsbereich',
    'objective': 'Zielsetzung',
    'objectives': 'Zielsetzungen',
    'criteria': 'Kriterien',
    'methodology': 'Methodik',
    'conclusion': 'Schlussfolgerung',
    'result': 'Ergebnis',
    'results': 'Ergebnisse',

    # Documents & Files
    'document': 'Dokument',
    'documents': 'Dokumente',
    'file': 'Datei',
    'files': 'Dateien',
    'attachment': 'Anhang',
    'attachments': 'Anhänge',
    'version': 'Version',
    'revision': 'Revision',
    'approved': 'Genehmigt',
    'reviewed': 'Überprüft',

    # Business Continuity
    'exercise': 'Übung',
    'exercises': 'Übungen',
    'plan': 'Plan',
    'plans': 'Pläne',
    'procedure': 'Verfahren',
    'procedures': 'Verfahren',
    'recovery': 'Wiederherstellung',
    'continuity': 'Kontinuität',
    'incident': 'Vorfall',
    'incidents': 'Vorfälle',
    'crisis': 'Krise',
    'emergency': 'Notfall',

    # Privacy & Data Protection
    'personal_data': 'Personenbezogene Daten',
    'data_subject': 'Betroffene Person',
    'data_subject_impact': 'Auswirkung auf betroffene Personen',
    'processing': 'Verarbeitung',
    'processor': 'Auftragsverarbeiter',
    'controller': 'Verantwortlicher',
    'consent': 'Einwilligung',
    'breach': 'Datenschutzverstoß',
    'dpia': 'Datenschutz-Folgenabschätzung',
    'legal_basis': 'Rechtsgrundlage',
    'retention': 'Aufbewahrung',
    'deletion': 'Löschung',
    'anonymization': 'Anonymisierung',
    'pseudonymization': 'Pseudonymisierung',

    # Training & Awareness
    'training': 'Schulung',
    'trainings': 'Schulungen',
    'course': 'Kurs',
    'participant': 'Teilnehmer',
    'participants': 'Teilnehmer',
    'instructor': 'Schulungsleiter',
    'certificate': 'Zertifikat',
    'completion': 'Abschluss',
    'attendance': 'Teilnahme',

    # Suppliers & Third Parties
    'supplier': 'Lieferant',
    'suppliers': 'Lieferanten',
    'vendor': 'Anbieter',
    'contract': 'Vertrag',
    'contracts': 'Verträge',
    'agreement': 'Vereinbarung',
    'service': 'Dienst',
    'services': 'Dienste',

    # Location & Organization
    'location': 'Standort',
    'locations': 'Standorte',
    'site': 'Standort',
    'office': 'Büro',
    'organization': 'Organisation',
    'subsidiary': 'Tochtergesellschaft',
    'subsidiaries': 'Tochtergesellschaften',
    'parent': 'Muttergesellschaft',

    # Time & Dates
    'planned': 'Geplant',
    'actual': 'Tatsächlich',
    'scheduled': 'Terminiert',
    'deadline': 'Frist',
    'duration': 'Dauer',
    'start': 'Beginn',
    'end': 'Ende',
    'year': 'Jahr',
    'month': 'Monat',
    'day': 'Tag',
    'today': 'Heute',
    'yesterday': 'Gestern',
    'tomorrow': 'Morgen',

    # Misc
    'yes': 'Ja',
    'no': 'Nein',
    'success': 'Erfolg',
    'error': 'Fehler',
    'warning': 'Warnung',
    'info': 'Information',
    'help': 'Hilfe',
    'page': 'Seite',
    'counter': 'Zähler',
    'total': 'Gesamt',
    'number': 'Nummer',
    'id': 'ID',
    'code': 'Code',
    'reference': 'Referenz',
}

TRANSLATIONS_EN = {
    # Common patterns
    'title': 'Title',
    'description': 'Description',
    'name': 'Name',
    'status': 'Status',
    'created': 'Created',
    'updated': 'Updated',
    'action': 'Action',
    'actions': 'Actions',
    'details': 'Details',
    'overview': 'Overview',
    'summary': 'Summary',

    # Compliance specific
    'framework': 'Framework',
    'requirement': 'Requirement',
    'requirements': 'Requirements',
    'unique_requirements': 'Unique Requirements',
    'total_requirements': 'Total Requirements',
    'source_requirement': 'Source Requirement',
    'target_requirements': 'Target Requirements',
    'mapping': 'Mapping',
    'mappings': 'Mappings',
    'total_mappings': 'Total Mappings',
    'partial_mappings': 'Partial Mappings',
    'bidirectional_mappings': 'Bidirectional Mappings',
    'cross_framework_mappings': 'Cross-Framework Mappings',
    'mapping_strength': 'Mapping Strength',
    'mapping_explanation': 'Mapping Explanation',
    'match_quality': 'Match Quality',
    'full_exceeds': 'Full/Exceeds',
    'compliance_matrix': 'Compliance Matrix',
    'detailed_comparison': 'Detailed Comparison',
    'unique_to': 'Unique to',
    'matrix': 'Matrix',
    'category': 'Category',
    'mapped': 'Mapped',

    # Sections
    'basic_info': 'Basic Information',
    'contact_info': 'Contact Information',
    'settings': 'Settings',
    'configuration': 'Configuration',
}

def translate_key(key, lang='de'):
    """Generate translation based on key name."""
    translations = TRANSLATIONS_DE if lang == 'de' else TRANSLATIONS_EN

    # Direct match
    if key in translations:
        return translations[key]

    # Handle underscores - convert to readable format
    words = key.split('_')

    # For German, try compound words first
    if lang == 'de':
        # Try translating complete multi-word combinations
        if len(words) >= 2:
            # Check common compound patterns
            combined_key = '_'.join(words)
            # Add more specific translations for common patterns
            specific_translations = {
                'edit_suffix': 'Bearbeitungssuffix',
                'save_changes': 'Änderungen speichern',
                'by_action': 'Nach Aktion',
                'created_at': 'Erstellt am',
                'updated_at': 'Aktualisiert am',
                'last_login': 'Letzte Anmeldung',
                'auth_provider': 'Authentifizierungsanbieter',
                'new_user': 'Neuer Benutzer',
                'active_users': 'Aktive Benutzer',
                'logins_today': 'Anmeldungen heute',
                'today_active': 'Heute aktiv',
            }
            if combined_key in specific_translations:
                return specific_translations[combined_key]

    # Try to translate each word
    translated_words = []
    all_translated = True

    for word in words:
        if word in translations:
            translated_words.append(translations[word])
        else:
            # Word not found in dictionary - keep as is but mark
            all_translated = False
            # Capitalize for fallback
            translated_words.append(word.capitalize())

    # If not all words translated, return key as-is (better than mixing languages)
    if not all_translated and lang == 'de':
        # For German, return the original key formatted
        return ' '.join(w.capitalize() for w in words)

    result = ' '.join(translated_words)
    return result

def process_file(filename, lang='de'):
    """Process a YAML file and replace NoFill placeholders."""
    with open(filename, 'r', encoding='utf-8') as f:
        content = f.read()

    # Pattern 1: Regular YAML keys including dotted like "key: "\0NoFill\0path"" or "title.edit: "\0NoFill\0path""
    pattern1 = r'(\s+)([\w.]+):\s*"\\0NoFill\\0([^"]+)"'

    # Pattern 2: Quoted string keys like "'Some text': "\0NoFill\0Some text""
    pattern2 = r"(\s*)'([^']+)':\s*\"\\0NoFill\\0([^\"]+)\""

    def replace_nofill_regular(match):
        indent = match.group(1)
        key = match.group(2)
        full_path = match.group(3)

        # For dotted keys like "title.edit", extract the last part for translation
        if '.' in key:
            key_parts = key.split('.')
            last_part = key_parts[-1]
            translation = translate_key(last_part, lang)
        else:
            # Generate translation
            translation = translate_key(key, lang)

        # Return replaced line with proper quoting
        if any(c in translation for c in [':',  '/', '-', '(']):
            return f'{indent}{key}: "{translation}"'
        else:
            return f'{indent}{key}: {translation}'

    def replace_nofill_quoted(match):
        indent = match.group(1)
        key_text = match.group(2)  # The full German/English text
        full_path = match.group(3)

        # For validator messages, the key IS the translation
        # Just return it without the NoFill wrapper
        return f"{indent}'{key_text}': '{key_text}'"

    # Replace all NoFill entries - pattern2 first (quoted keys), then pattern1 (regular keys)
    # This order prevents pattern1 from partially matching pattern2
    new_content = re.sub(pattern2, replace_nofill_quoted, content)
    count2 = content.count('\\0NoFill\\0') - new_content.count('\\0NoFill\\0')

    new_content = re.sub(pattern1, replace_nofill_regular, new_content)
    count1 = content.count('\\0NoFill\\0') - count2 - new_content.count('\\0NoFill\\0')

    # Write back
    with open(filename, 'w', encoding='utf-8') as f:
        f.write(new_content)

    return count1 + count2

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Usage: python3 translate_nofill.py <file.yaml> [lang]")
        sys.exit(1)

    filename = sys.argv[1]
    lang = sys.argv[2] if len(sys.argv) > 2 else ('en' if '.en.yaml' in filename else 'de')

    count = process_file(filename, lang)
    print(f"Translated {count} NoFill placeholders in {filename} ({lang})")
