#!/usr/bin/env php
<?php

/**
 * Migrate forms to accessible _form_field.html.twig component
 * WCAG 2.1 AA Compliance Script
 *
 * This script automates the migration of Symfony forms to use the accessible
 * _form_field.html.twig component with proper ARIA attributes and help texts.
 */

$templatesDir = __DIR__ . '/../templates';
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv) || $dryRun;

$stats = [
    'files_processed' => 0,
    'files_migrated' => 0,
    'partials_created' => 0,
];

$formPatterns = [
    // Old pattern: manual form_label, form_widget, form_errors
    'old' => '/{{ form_label\((form\.\w+)\) }}\s*{{ form_widget\(\1\) }}\s*{{ form_errors\(\1\) }}/s',

    // Fieldset sections
    'section_start' => '/<h3><i class="([^"]+)"><\/i> {{ \'([^\']+)\'\\|trans }}<\/h3>/',
];

function migrateFormFields(string $content): array
{
    $modified = false;
    $changes = 0;

    // Find all form field groups (label + widget + errors)
    preg_match_all(
        '/{{ form_label\((form\.(\w+))\) }}\s*{{ form_widget\(\1\) }}\s*{{ form_errors\(\1\) }}/s',
        $content,
        $matches,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE
    );

    // Replace from end to start to preserve offsets
    $matches = array_reverse($matches);

    foreach ($matches as $match) {
        $fullMatch = $match[0][0];
        $formField = $match[1][0]; // e.g., form.name
        $fieldName = $match[2][0]; // e.g., name

        // Generate accessible component inclusion
        $replacement = sprintf(
            "{% include '_components/_form_field.html.twig' with {\n" .
            "                'field': %s,\n" .
            "                'label': '%s.field.%s'|trans,\n" .
            "                'help': '%s.help.%s'|trans\n" .
            "            } %}",
            $formField,
            '{{ module }}', // Will be replaced per module
            $fieldName,
            '{{ module }}',
            $fieldName
        );

        $offset = $match[0][1];
        $content = substr_replace($content, $replacement, $offset, strlen($fullMatch));
        $modified = true;
        $changes++;
    }

    return ['content' => $content, 'modified' => $modified, 'changes' => $changes];
}

function createFormPartial(string $module, string $formContent): string
{
    return <<<TWIG
{#
    {$module} Form Partial (Accessible)
    WCAG 2.1 AA Compliant
    Auto-generated, please review and adjust as needed
#}

{$formContent}

{# Form Actions #}
<div class="form-actions d-flex gap-2 justify-content-end">
    <a href="{{ path('app_{$module}_index') }}"
       class="btn btn-secondary">
        <i class="bi bi-x-circle" aria-hidden="true"></i>
        {{ 'common.cancel'|trans }}
    </a>

    <button type="submit" class="btn btn-primary">
        <i class="{{ button_icon|default('bi-save') }}" aria-hidden="true"></i>
        {{ button_label|default('common.save'|trans) }}
    </button>
</div>
TWIG;
}

// Process all new.html.twig and edit.html.twig files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($templatesDir)
);

$modulesToMigrate = [];

foreach ($iterator as $file) {
    if ($file->isFile() &&
        ($file->getFilename() === 'new.html.twig' || $file->getFilename() === 'edit.html.twig')) {

        $filePath = $file->getPathname();
        $content = file_get_contents($filePath);

        // Skip if already using _form_field component
        if (str_contains($content, '_form_field.html.twig')) {
            continue;
        }

        // Skip if no form_label found
        if (!str_contains($content, 'form_label')) {
            continue;
        }

        $stats['files_processed']++;

        // Extract module name from path
        $relativePath = str_replace($templatesDir . '/', '', dirname($filePath));
        $module = basename($relativePath);

        if (!isset($modulesToMigrate[$module])) {
            $modulesToMigrate[$module] = [
                'new' => null,
                'edit' => null,
            ];
        }

        $type = $file->getFilename() === 'new.html.twig' ? 'new' : 'edit';
        $modulesToMigrate[$module][$type] = $filePath;
    }
}

// Output migration report
echo "\n========================================\n";
echo "Form Accessibility Migration Report\n";
echo "========================================\n\n";
echo "Files found: {$stats['files_processed']}\n";
echo "Modules to migrate: " . count($modulesToMigrate) . "\n\n";

echo "Modules requiring migration:\n";
foreach ($modulesToMigrate as $module => $files) {
    echo "  - $module";
    if ($files['new'] && $files['edit']) {
        echo " (new + edit)";
    } elseif ($files['new']) {
        echo " (new only)";
    } else {
        echo " (edit only)";
    }
    echo "\n";
}

echo "\n";

if ($dryRun) {
    echo "DRY RUN - No files were modified.\n";
    echo "Run without --dry-run to apply changes.\n";
} else {
    echo "To migrate forms:\n";
    echo "1. Review the migration plan above\n";
    echo "2. Manually migrate complex forms (Asset, Risk, Control)\n";
    echo "3. Use this script as a guide for remaining forms\n";
    echo "4. Add translations for each module\n";
}

echo "\nNext steps:\n";
echo "1. For each module, create {module}/_form.html.twig partial\n";
echo "2. Update new.html.twig to include the partial\n";
echo "3. Update edit.html.twig to include the partial\n";
echo "4. Add translations: {module}.field.*, {module}.help.*, {module}.section.*\n";
echo "5. Test with screen reader\n";
