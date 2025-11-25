#!/usr/bin/env python3
"""
Replace NoFill placeholders with context-sensitive German translations.
"""
import re
import sys
from pathlib import Path

# Comprehensive context-aware translations for NoFill entries
NOFILL_TRANSLATIONS = {
    # Admin Dashboard
    'admin.dashboard.modules.controls': 'Controls',

    # Admin Settings - Application
    'admin.settings.title': 'Systemeinstellungen',
    'admin.settings.subtitle': 'Konfiguration von Anwendungsparametern und Systemverhalten',
    'admin.settings.application.title': 'Anwendungseinstellungen',
    'admin.settings.application.description': 'Grundlegende Einstellungen für Sprache, Zeitzone und Anzeigeoptionen',
    'admin.settings.application.locale': 'Sprache / Locale',
    'admin.settings.application.timezone': 'Zeitzone',
    'admin.settings.application.items_per_page': 'Einträge pro Seite',
    'admin.settings.application.locale_settings': 'Spracheinstellungen',
    'admin.settings.application.default_locale': 'Standard-Sprache',
    'admin.settings.application.default_locale_help': 'Standard-Sprache für neue Benutzer und Systemausgaben',
    'admin.settings.application.supported_locales': 'Unterstützte Sprachen',
    'admin.settings.application.supported_locales_help': 'Sprachen, die Benutzer auswählen können (de, en)',
    'admin.settings.application.display_settings': 'Anzeigeeinstellungen',
    'admin.settings.application.items_per_page_help': 'Anzahl der Einträge pro Seite in Tabellen (Standard: 25)',
    'admin.settings.application.timezone_help': 'Zeitzone für Datumsanzeigen (z.B. Europe/Berlin)',
    'admin.settings.application.date_format': 'Datumsformat',
    'admin.settings.application.date_format_help': 'Format für Datumsanzeigen (z.B. d.m.Y für 31.12.2024)',
    'admin.settings.application.datetime_format': 'Datum-/Zeitformat',
    'admin.settings.application.datetime_format_help': 'Format für Datum und Uhrzeit (z.B. d.m.Y H:i für 31.12.2024 14:30)',
    'admin.settings.application.help_text': 'Diese Einstellungen wirken sich global auf alle Mandanten und Benutzer aus',
    'admin.settings.configure': 'Einstellungen konfigurieren',

    # Admin Settings - Security
    'admin.settings.security.title': 'Sicherheitseinstellungen',
    'admin.settings.security.description': 'Konfiguration von Authentifizierung, Session-Management und Passwortrichtlinien (ISO 27001 Anhang A 5.17, 5.18)',
    'admin.settings.security.session_lifetime': 'Session-Laufzeit',
    'admin.settings.security.max_login_attempts': 'Max. Anmeldeversuche',
    'admin.settings.security.require_2fa': 'MFA erzwingen',
    'admin.settings.security.session_settings': 'Session-Einstellungen',
    'admin.settings.security.session_lifetime_help': 'Session-Laufzeit in Minuten (Standard: 1440 = 24 Stunden)',
    'admin.settings.security.remember_me_lifetime': 'Remember-Me Laufzeit',
    'admin.settings.security.remember_me_help': 'Dauer des "Angemeldet bleiben"-Cookies in Tagen (Standard: 30)',
    'admin.settings.security.password_settings': 'Passwortrichtlinien',
    'admin.settings.security.password_min_length': 'Min. Passwortlänge',
    'admin.settings.security.password_min_length_help': 'Minimale Anzahl Zeichen für Passwörter (Standard: 12, empfohlen: 12-16)',
    'admin.settings.security.auth_settings': 'Authentifizierungseinstellungen',
    'admin.settings.security.max_login_attempts_help': 'Anzahl fehlgeschlagener Anmeldeversuche vor Account-Sperre (Standard: 5)',
    'admin.settings.security.lockout_duration': 'Sperrdauer',
    'admin.settings.security.lockout_duration_help': 'Dauer der Account-Sperre in Minuten nach zu vielen Fehlversuchen (Standard: 15)',
    'admin.settings.security.require_2fa_help': 'Multi-Faktor-Authentifizierung für alle Benutzer erzwingen (NIS2-konform, Art. 21.2.b)',
    'admin.settings.security.warning': 'Warnung: Änderungen an Sicherheitseinstellungen können bestehende Sessions ungültig machen',

    # Admin Settings - Features
    'admin.settings.features.title': 'Feature-Einstellungen',
    'admin.settings.features.description': 'Aktivierung und Konfiguration von optionalen Funktionen',
    'admin.settings.features.dark_mode': 'Dark Mode',
    'admin.settings.features.global_search': 'Globale Suche',
    'admin.settings.features.audit_log': 'Audit Log',
    'admin.settings.features.intro': 'Einführungs-Tutorial',
    'admin.settings.features.dark_mode_help': 'Dark Mode für alle Benutzer aktivieren',
    'admin.settings.features.global_search_help': 'Globale Volltextsuche über alle Entities aktivieren',
    'admin.settings.features.quick_view': 'Quick View',
    'admin.settings.features.quick_view_help': 'Schnellansicht-Modals für Entities aktivieren',
    'admin.settings.features.notifications': 'Benachrichtigungen',
    'admin.settings.features.notifications_help': 'System-Benachrichtigungen für Benutzer aktivieren',
    'admin.settings.features.audit_log_help': 'Audit-Protokollierung aller Benutzeraktionen (ISO 27001 Anhang A 8.15)',
    'admin.settings.features.info_text': 'Feature-Flags ermöglichen das selektive Aktivieren von Funktionalitäten',
    'admin.settings.info_title': 'Systemeinstellungen Hilfe',
    'admin.settings.info_text': 'Änderungen werden sofort wirksam und betreffen alle Mandanten',
    'admin.settings.info': 'Information',
    'admin.settings.help': 'Hilfe',

    # Admin Compliance
    'admin.compliance.framework': 'Framework',
    'admin.compliance.total_requirements': 'Gesamtanforderungen',
    'admin.compliance.assessed': 'Bewertet',
    'admin.compliance.compliant': 'Konform',
    'admin.compliance.compliance_rate': 'Compliance-Rate',
    'admin.compliance.progress': 'Fortschritt',
    'admin.compliance.statistics': 'Statistiken',
    'admin.compliance.total_available': 'Gesamt verfügbar',
    'admin.compliance.loaded': 'Geladen',
    'admin.compliance.not_loaded': 'Nicht geladen',
    'admin.compliance.mandatory_missing': 'Pflicht-Frameworks fehlen',
    'admin.compliance.compliance_overview': 'Compliance-Übersicht',
    'admin.compliance.no_frameworks_loaded': 'Keine Frameworks geladen',

    # Admin Panel
    'admin.panel.title': 'Admin-Panel',

    # Admin Data Repair
    'admin.data_repair.current_asset': 'Aktuelles Asset',
    'admin.data_repair.assign_to_asset': 'Asset zuweisen',
    'admin.data_repair.no_asset': 'Kein Asset',
    'admin.data_repair.select_asset': 'Asset auswählen',
    'admin.data_repair.assign_risk': 'Risiko zuweisen',
    'admin.data_repair.select_risk': 'Risiko auswählen',
    'admin.data_repair.assign_asset': 'Asset zuweisen',
    'admin.data_repair.entity_type': 'Entity-Typ',
    'admin.data_repair.issue': 'Problem',
    'admin.data_repair.title': 'Daten-Reparatur',
    'admin.data_repair.description': 'Erkennung und Behebung von Dateninkonsistenzen',
    'admin.data_repair.orphaned_entities': 'Verwaiste Entities',
    'admin.data_repair.no_orphans': 'Keine verwaisten Entities gefunden',
    'admin.data_repair.orphans_found': '%count% verwaiste Entities gefunden',
    'admin.data_repair.orphaned_assets': 'Verwaiste Assets',
    'admin.data_repair.orphaned_risks': 'Verwaiste Risiken',
    'admin.data_repair.orphaned_incidents': 'Verwaiste Vorfälle',
    'admin.data_repair.assign_to_tenant': 'Mandanten zuweisen',
    'admin.data_repair.select_tenant': 'Mandant auswählen',
    'admin.data_repair.all_orphans': 'Alle verwaisten',
    'admin.data_repair.assets_only': 'Nur Assets',
    'admin.data_repair.risks_only': 'Nur Risiken',
    'admin.data_repair.incidents_only': 'Nur Vorfälle',
    'admin.data_repair.assign_orphans': 'Verwaiste zuweisen',
    'admin.data_repair.orphaned_assets_list': 'Liste verwaister Assets',
    'admin.data_repair.orphaned_risks_list': 'Liste verwaister Risiken',
    'admin.data_repair.orphaned_incidents_list': 'Liste verwaister Vorfälle',
    'admin.data_repair.tenant_statistics': 'Mandanten-Statistiken',
    'admin.data_repair.risk_asset_assignment': 'Risiko-Asset-Zuordnung',
    'admin.data_repair.risk_asset_assignment_desc': 'Risiken ohne zugeordnete Assets',
    'admin.data_repair.incident_asset_assignment': 'Vorfall-Asset-Zuordnung',
    'admin.data_repair.incident_asset_assignment_desc': 'Vorfälle ohne zugeordnete Assets',
    'admin.data_repair.controls_without_risks': 'Controls ohne Risiken',
    'admin.data_repair.controls_without_risks_desc': 'Controls, die keinen Risiken zugeordnet sind',
    'admin.data_repair.controls_count': '%count% Controls',
    'admin.data_repair.controls_without_assets': 'Controls ohne Assets',
    'admin.data_repair.controls_without_assets_desc': 'Controls, die keinen Assets zugeordnet sind',
    'admin.data_repair.broken_references': 'Defekte Referenzen',
    'admin.data_repair.broken_references_desc': 'Verweise auf nicht existierende Entities',

    # Admin Modules
    'admin.modules.title': 'Modul-Verwaltung',
    'admin.modules.dependency_graph': 'Abhängigkeitsgraph',
    'admin.modules.stats.total': 'Gesamt',
    'admin.modules.stats.active': 'Aktiv',
    'admin.modules.stats.inactive': 'Inaktiv',
    'admin.modules.stats.required': 'Erforderlich',
    'admin.modules.active_modules': 'Aktive Module',
    'admin.modules.status.active': 'Aktiv',
    'admin.modules.status.inactive': 'Inaktiv',
    'admin.modules.dependencies': 'Abhängigkeiten',
    'admin.modules.required_by': 'Benötigt von',
    'admin.modules.details': 'Details',
    'admin.modules.confirm_deactivate': 'Möchten Sie dieses Modul wirklich deaktivieren?',
    'admin.modules.deactivate': 'Deaktivieren',
    'admin.modules.required': 'Erforderlich',
    'admin.modules.no_active': 'Keine aktiven Module',
    'admin.modules.inactive_modules': 'Inaktive Module',
    'admin.modules.activate': 'Aktivieren',

    # Compliance - Descriptions
    'compliance.description.unique_requirements': 'Einzigartige Anforderungen je Framework',
    'compliance.description.matrix': 'Matrix der Framework-Überschneidungen',

    # Compliance - Sections
    'compliance.section.detailed_comparison': 'Detaillierter Vergleich',
    'compliance.section.unique_to': 'Einzigartig für %framework%',
    'compliance.section.compliance_matrix': 'Compliance-Matrix',
    'compliance.section.cross_framework_mappings': 'Framework-übergreifende Zuordnungen',
    'compliance.section.mapping_explanation': 'Erklärung der Zuordnungen',

    # Compliance - Stats
    'compliance.stats.total_mappings': 'Gesamte Zuordnungen',
    'compliance.stats.full_exceeds': 'Vollständig/Übertrifft',
    'compliance.stats.partial_mappings': 'Teilweise Zuordnungen',
    'compliance.stats.bidirectional_mappings': 'Bidirektionale Zuordnungen',

    # Compliance - Fields
    'compliance.field.category': 'Kategorie',
    'compliance.field.mapping': 'Zuordnung',
    'compliance.field.match_quality': 'Zuordnungsqualität',
    'compliance.field.source_requirement': 'Quellanforderung',
    'compliance.field.target_requirements': 'Zielanforderungen',
    'compliance.field.mapping_strength': 'Zuordnungsstärke',
    'compliance.field.mapped': 'Zugeordnet',

    # Compliance - Management
    'compliance.manage_frameworks': 'Frameworks verwalten',
    'compliance.manage_frameworks_subtitle': 'Compliance-Frameworks laden und konfigurieren',
    'compliance.framework_statistics': 'Framework-Statistiken',
    'compliance.total_available': 'Gesamt verfügbar',
    'compliance.loaded': 'Geladen',
    'compliance.not_loaded': 'Nicht geladen',
    'compliance.mandatory_missing': 'Pflicht-Frameworks fehlen',
    'compliance.available_frameworks': 'Verfügbare Frameworks',
    'compliance.version': 'Version',
    'compliance.industry': 'Branche',
    'compliance.regulatory_body': 'Regulierungsbehörde',
    'compliance.mandatory': 'Pflicht',
    'compliance.required_modules': 'Erforderliche Module',
    'compliance.already_loaded': 'Bereits geladen',
    'compliance.delete_framework': 'Framework löschen',
    'compliance.load_framework': 'Framework laden',
    'compliance.confirm_load': 'Möchten Sie dieses Framework wirklich laden?',
    'compliance.loading': 'Wird geladen...',
    'compliance.successfully_loaded': 'Framework erfolgreich geladen',
    'compliance.load_failed': 'Fehler beim Laden des Frameworks',
    'compliance.error': 'Fehler',
    'compliance.deleting': 'Wird gelöscht...',
    'compliance.successfully_deleted': 'Framework erfolgreich gelöscht',
    'compliance.delete_failed': 'Fehler beim Löschen des Frameworks',

    # Compliance - Mapping
    'compliance.mapping.create_link': 'Zuordnung erstellen',
    'compliance.mapping.create_instruction': 'um zu zeigen, wie Anforderungen mit Ihren Controls zusammenhängen',
    'compliance.mapping.type.full': 'Vollständig (100%)',
    'compliance.mapping.type.partial': 'Teilweise (50-99%)',
    'compliance.mapping.type.weak': 'Schwach (0-49%)',

    # Compliance - Messages
    'compliance.message.no_data_elements': 'Keine Datenelemente vorhanden',
    'compliance.message.no_frameworks': 'Keine Frameworks vorhanden',
    'compliance.message.no_opportunities': 'Keine Wiederverwendungsmöglichkeiten gefunden',
    'compliance.message.no_comparison_data': 'Keine Vergleichsdaten verfügbar',
    'compliance.message.more_requirements': '+ %count% weitere Anforderungen',
    'compliance.message.no_unique': 'Keine einzigartigen Anforderungen',
    'compliance.message.no_gaps': 'Keine Lücken gefunden',
    'compliance.message.no_gaps_desc': 'Alle Anforderungen sind erfüllt',

    # Compliance - Badges
    'compliance.badge.reusable': 'Wiederverwendbar',
    'compliance.badge.mapped': 'Zugeordnet',
    'compliance.badge.no_mapping': 'Nicht zugeordnet',
    'compliance.badge.overdue': 'Überfällig',

    # Compliance Framework - Extended
    'compliance.framework.edit.title': 'Framework bearbeiten',
    'compliance.framework.edit.heading': 'Compliance-Framework bearbeiten',
    'compliance.framework.section.identification': 'Identifikation',
    'compliance.framework.section.details': 'Details',
    'compliance.framework.section.configuration': 'Konfiguration',
    'compliance.framework.field.code': 'Code',
    'compliance.framework.field.version': 'Version',
    'compliance.framework.field.name': 'Name',
    'compliance.framework.field.description': 'Beschreibung',
    'compliance.framework.field.industry': 'Branche',
    'compliance.framework.field.regulatory_body': 'Regulierungsbehörde',
    'compliance.framework.field.scope': 'Geltungsbereich',
    'compliance.framework.field.mandatory': 'Pflicht',
    'compliance.framework.field.active': 'Aktiv',
    'compliance.framework.field.requirements': 'Anforderungen',
    'compliance.framework.field.status': 'Status',
    'compliance.framework.field.compliance': 'Compliance',
    'compliance.framework.info': 'Framework-Information',
    'compliance.framework.confirm.delete': 'Möchten Sie dieses Framework wirklich löschen?',
    'compliance.framework.new.title': 'Neues Framework',
    'compliance.framework.new.heading': 'Neues Compliance-Framework erstellen',
    'compliance.framework.help.code.title': 'Framework-Code',
    'compliance.framework.help.code.text': 'Eindeutiger Identifikator (z.B. ISO27001, TISAX, DSGVO)',
    'compliance.framework.help.mandatory.title': 'Pflicht-Framework',
    'compliance.framework.help.mandatory.text': 'Framework ist regulatorisch verpflichtend',
    'compliance.framework.help.active.title': 'Aktives Framework',
    'compliance.framework.help.active.text': 'Framework ist aktiv und wird für Compliance-Prüfungen verwendet',
    'compliance.framework.action.dashboard': 'Dashboard',
    'compliance.framework.action.new': 'Neues Framework',
    'compliance.framework.action.gap_analysis': 'Gap-Analyse',
    'compliance.framework.action.assess': 'Bewerten',
    'compliance.framework.title': 'Compliance-Frameworks',
    'compliance.framework.heading': 'Framework-Verwaltung',
    'compliance.framework.description': 'Verwaltung von Compliance-Frameworks und deren Anforderungen',
    'compliance.framework.list': 'Framework-Liste',
    'compliance.framework.empty': 'Keine Frameworks vorhanden',
    'compliance.framework.no_requirements': 'Keine Anforderungen definiert',
    'compliance.framework.badge.mandatory': 'Pflicht',
    'compliance.framework.stats.total_requirements': 'Gesamtanforderungen',
    'compliance.framework.stats.applicable': 'Anwendbar',
    'compliance.framework.stats.fulfilled': 'Erfüllt',
    'compliance.framework.stats.compliance': 'Compliance',
    'compliance.requirement.action.new': 'Neue Anforderung',
    'compliance.requirement.priority.critical': 'Kritisch',
    'compliance.requirement.priority.high': 'Hoch',
    'compliance.requirement.priority.medium': 'Mittel',
    'compliance.requirement.priority.low': 'Niedrig',
    'compliance.requirement.view_page_note': 'Seite anzeigen',
    'compliance.requirement.filter_by_framework': 'Nach Framework filtern',
    'compliance.cross_framework.source_target_label': 'Quelle → Ziel',
    'compliance.cross_framework.table.source': 'Quelle',
    'compliance.cross_framework.table.target': 'Ziel',
    'compliance.cross_framework.table.type': 'Typ',
    'compliance.cross_framework.table.strength': 'Stärke',
    'compliance.cross_framework.table.bidirectional': 'Bidirektional',
    'compliance.cross_framework.yes_bidirectional': 'Ja (bidirektional)',
    'compliance.cross_framework.title': 'Framework-übergreifende Zuordnungen',
    'compliance.cross_framework.subtitle': 'Analyse von Zuordnungen zwischen Compliance-Frameworks',
    'compliance.cross_framework.back': 'Zurück',
    'compliance.cross_framework.coverage_matrix': 'Abdeckungsmatrix',
    'compliance.cross_framework.coverage_description': 'Übersicht der gegenseitigen Framework-Abdeckung',
    'compliance.cross_framework.coverage_high': 'Hohe Abdeckung (80-100%)',
    'compliance.cross_framework.coverage_medium': 'Mittlere Abdeckung (50-79%)',
    'compliance.cross_framework.coverage_low': 'Niedrige Abdeckung (0-49%)',
    'compliance.cross_framework.strong_mappings': 'Starke Zuordnungen',
    'compliance.cross_framework.partial_mappings_label': 'Teilweise Zuordnungen',
    'compliance.cross_framework.weak_mappings': 'Schwache Zuordnungen',
    'compliance.cross_framework.showing_of': 'Zeige %current% von %total%',
    'compliance.cross_framework.understanding_title': 'Verständnis der Zuordnungen',
    'compliance.cross_framework.what_title': 'Was sind Framework-Zuordnungen?',
    'compliance.cross_framework.what_description': 'Zuordnungen zeigen, wie Anforderungen verschiedener Frameworks sich überschneiden',
    'compliance.cross_framework.types_title': 'Zuordnungstypen',
    'compliance.cross_framework.types.exceeds': 'Übertrifft (101-150%)',
    'compliance.cross_framework.types.full': 'Vollständig (100%)',
    'compliance.cross_framework.types.partial': 'Teilweise (50-99%)',
    'compliance.cross_framework.types.weak': 'Schwach (0-49%)',
    'compliance.cross_framework.bidirectional_title': 'Bidirektionale Zuordnungen',
    'compliance.cross_framework.bidirectional_description': 'Beide Frameworks referenzieren sich gegenseitig',
    'compliance.cross_framework.strategic_title': 'Strategische Nutzung',
    'compliance.cross_framework.strategic_description': 'Nutzen Sie starke Zuordnungen für effiziente Multi-Framework-Compliance',
    'compliance.page_title': 'Compliance Management',
    'compliance.page_subtitle': 'Framework-Analyse und Gap-Management',
    'compliance.button.cross_framework': 'Framework-Vergleich',
    'compliance.button.transitive': 'Transitive Compliance',
    'compliance.button.compare_frameworks': 'Frameworks vergleichen',
    'compliance.button.open_dashboard': 'Dashboard öffnen',
    'compliance.button.explore_mappings': 'Zuordnungen erkunden',
    'compliance.button.back': 'Zurück',
    'compliance.button.reassess': 'Neu bewerten',
    'compliance.quick_access': 'Schnellzugriff',
    'compliance.quick_access_hint': 'Häufig verwendete Compliance-Funktionen',
    'compliance.data_reuse.title': 'Daten-Wiederverwendung',
    'compliance.data_reuse.description': 'Effizienzgewinne durch Wiederverwendung bestehender Daten',
    'compliance.data_reuse.hours_saved': '%hours% Stunden eingespart',
    'compliance.data_reuse.work_days': '%days% Arbeitstage',
    'compliance.data_reuse.show_details': 'Details anzeigen',
    'compliance.label.compliance': 'Compliance',
    'compliance.label.fulfilled': 'Erfüllt',
    'compliance.label.gaps': 'Lücken',
    'compliance.label.requirements': 'Anforderungen',
    'compliance.label.time_savings': 'Zeitersparnis',
    'compliance.label.days': 'Tage',
    'compliance.label.code': 'Code',
}

def translate_nofill_line(line):
    """Translate a line containing NoFill marker."""
    # Match NoFill pattern: "\0NoFill\0key.path.here"
    match = re.match(r'^(\s*)([^:]+):\s*"\\0NoFill\\0(.+)"(.*)$', line)
    if not match:
        return line

    indent, yaml_key, translation_key, rest = match.groups()

    # Look up translation
    if translation_key in NOFILL_TRANSLATIONS:
        translation = NOFILL_TRANSLATIONS[translation_key]
        # Escape quotes in translation
        translation = translation.replace("'", "''")
        return f"{indent}{yaml_key}: '{translation}'{rest}\n"

    return line

def process_file(file_path):
    """Process a single translation file."""
    with open(file_path, 'r', encoding='utf-8') as f:
        lines = f.readlines()

    translated_lines = [translate_nofill_line(line) for line in lines]

    changes = sum(1 for old, new in zip(lines, translated_lines) if old != new)

    if changes > 0:
        with open(file_path, 'w', encoding='utf-8') as f:
            f.writelines(translated_lines)

    return changes

def main():
    translations_dir = Path('translations')

    total_changes = 0
    files_changed = 0

    for yaml_file in sorted(translations_dir.glob('*.de.yaml')):
        changes = process_file(yaml_file)
        if changes > 0:
