<?php

declare(strict_types=1);

namespace App\Setup;

/**
 * FairyAurora Setup-Flow — 12-Step Alva-Dialog + Phase-Mapping
 *
 * Source-of-Truth für die Setup-Wizard-Left-Panel-Inhalte (Mood + Line + Sub + Phase).
 * Wird von `SetupFlowExtension` als Twig-Function `setup_flow(id)` exponiert.
 *
 * Plan § 13 Pattern-Source + § 26 i18n × Typewriter.
 */
final class SetupFlow
{
    /**
     * Step-ID ist der Twig-Route-Key (matching existing step0..step11).
     *
     * @return array<string, array{
     *     kind:string, phase:string, mood:string, num:int,
     *     line_de:string, line_en:string, sub_de:string, sub_en:string
     * }>
     */
    public static function steps(): array
    {
        return [
            'welcome'     => ['num' =>  1, 'kind' => 'welcome',      'phase' => 'Start',   'mood' => 'happy',       'line_de' => 'Hi, ich bin Alva.',                       'line_en' => 'Hi, I\'m Alva.',                       'sub_de' => 'Deine Begleiterin durch Little ISMS Helper. Wir richten das System jetzt ein.', 'sub_en' => 'Your companion through Little ISMS Helper. Let\'s set up the system now.'],
            'requirements'=> ['num' =>  2, 'kind' => 'Technik',      'phase' => 'Technik', 'mood' => 'scanning',    'line_de' => 'Ich mess\' mal deinen Server aus.',        'line_en' => 'Let me scan your server.',              'sub_de' => 'PHP, Datenbank, Schreibrechte — das Übliche. Dauert 30 Sekunden.',             'sub_en' => 'PHP, database, write permissions — the usuals. Takes 30 seconds.'],
            'database'    => ['num' =>  3, 'kind' => 'Technik',      'phase' => 'Technik', 'mood' => 'focused',     'line_de' => 'Sag mir, wo ich schreiben darf.',          'line_en' => 'Tell me where I can write.',            'sub_de' => 'Eine dedizierte DB. Ich lege das Schema selbst an.',                            'sub_en' => 'A dedicated DB. I\'ll create the schema myself.'],
            'backup'      => ['num' =>  4, 'kind' => 'Technik',      'phase' => 'Technik', 'mood' => 'idle',        'line_de' => 'Hast du ein Altsystem?',                   'line_en' => 'Do you have a legacy system?',          'sub_de' => 'Wenn ja, spielen wir\'s jetzt ein. Wenn nicht, weiter.',                         'sub_en' => 'If yes, we import it now. If not, we move on.'],
            'admin'       => ['num' =>  5, 'kind' => 'Technik',      'phase' => 'Technik', 'mood' => 'focused',     'line_de' => 'Wer führt hier das Kommando?',             'line_en' => 'Who\'s in charge here?',                'sub_de' => 'Der erste Admin hat volle Rechte. Wähl weise.',                                 'sub_en' => 'The first admin has full rights. Choose wisely.'],
            'email'       => ['num' =>  6, 'kind' => 'Technik',      'phase' => 'Technik', 'mood' => 'focused',     'line_de' => 'Wie erreich\' ich dich?',                  'line_en' => 'How do I reach you?',                   'sub_de' => 'SMTP für Erinnerungen, Resets, Reports. Test-Mail inklusive.',                  'sub_en' => 'SMTP for reminders, resets, reports. Test mail included.'],
            'organisation'=> ['num' =>  7, 'kind' => 'Inhalt',       'phase' => 'Inhalt',  'mood' => 'happy',       'line_de' => 'Jetzt zu dir.',                            'line_en' => 'Now about you.',                        'sub_de' => 'Basisdaten deiner Organisation — prägt den Scope.',                             'sub_en' => 'Basic data about your organization — shapes the scope.'],
            'modules'     => ['num' =>  8, 'kind' => 'Inhalt',       'phase' => 'Inhalt',  'mood' => 'focused',     'line_de' => 'Was willst du verwalten?',                 'line_en' => 'What do you want to manage?',           'sub_de' => 'Core-Module sind dabei, der Rest ist deine Wahl.',                              'sub_en' => 'Core modules are included, the rest is your choice.'],
            'frameworks'  => ['num' =>  9, 'kind' => 'Inhalt',       'phase' => 'Inhalt',  'mood' => 'working',     'line_de' => 'Welchen Standards folgst du?',             'line_en' => 'Which standards do you follow?',        'sub_de' => 'Ich lade die Controls und verknüpfe alles quer.',                               'sub_en' => 'I\'ll load the controls and cross-link everything.'],
            'masterdata'  => ['num' => 10, 'kind' => 'Inhalt',       'phase' => 'Inhalt',  'mood' => 'focused',     'line_de' => 'Standorte, Abteilungen.',                  'line_en' => 'Locations, departments.',               'sub_de' => 'Nur die wichtigsten. Der Rest kommt später.',                                   'sub_en' => 'Only the most important ones. The rest comes later.'],
            'demo'        => ['num' => 11, 'kind' => 'Inhalt',       'phase' => 'Inhalt',  'mood' => 'idle',        'line_de' => 'Beispieldaten zum Ausprobieren?',          'line_en' => 'Sample data to try?',                   'sub_de' => 'Jederzeit löschbar. Gut für Schulungen.',                                       'sub_en' => 'Removable anytime. Great for training.'],
            'complete'    => ['num' => 12, 'kind' => 'Fertig',       'phase' => 'Fertig',  'mood' => 'celebrating', 'line_de' => 'Fertig. Das war\'s.',                      'line_en' => 'Done. That\'s it.',                     'sub_de' => 'Dein ISMS ist einsatzbereit. Komm mit aufs Dashboard.',                         'sub_en' => 'Your ISMS is ready. Let\'s head to the dashboard.'],
        ];
    }

    public static function total(): int
    {
        return count(self::steps());
    }

    /**
     * @return array{kind:string, phase:string, mood:string, num:int,
     *   line:string, sub:string, line_de:string, line_en:string, sub_de:string, sub_en:string}|null
     */
    public static function get(string $id, string $locale = 'de'): ?array
    {
        $steps = self::steps();
        if (!isset($steps[$id])) {
            return null;
        }
        $step = $steps[$id];
        $step['line'] = $locale === 'en' ? $step['line_en'] : $step['line_de'];
        $step['sub']  = $locale === 'en' ? $step['sub_en']  : $step['sub_de'];
        $step['id']   = $id;
        return $step;
    }

    public static function phases(): array
    {
        return [
            ['label' => 'Start',   'range' => [1, 1]],
            ['label' => 'Technik', 'range' => [2, 6]],
            ['label' => 'Inhalt',  'range' => [7, 11]],
            ['label' => 'Fertig',  'range' => [12, 12]],
        ];
    }
}
