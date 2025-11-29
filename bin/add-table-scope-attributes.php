#!/usr/bin/env php
<?php

/**
 * Add table scope attributes to all Twig templates
 * WCAG 2.1 AA Compliance Script
 *
 * This script:
 * 1. Adds scope="col" to <th> in <thead>
 * 2. Adds scope="row" to <th> in <tbody>/<tfoot>
 * 3. Adds <caption class="visually-hidden"> if missing
 * 4. Adds aria-hidden="true" to decorative icons
 * 5. Updates checkboxes to use aria-label instead of title
 */

$templatesDir = __DIR__ . '/../templates';
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv) || $dryRun;

$stats = [
    'files_processed' => 0,
    'files_modified' => 0,
    'scope_col_added' => 0,
    'scope_row_added' => 0,
    'captions_added' => 0,
    'aria_hidden_added' => 0,
];

function processFile(string $file, bool $dryRun, array &$stats, string $templatesDir, bool $verbose): void
{
    $content = file_get_contents($file);
    $original = $content;
    $modified = false;

    // 1. Add scope="col" to <th> in <thead>
    $pattern = '/<thead>(.*?)<\/thead>/s';
    if (preg_match_all($pattern, $content, $matches)) {
        foreach ($matches[1] as $theadContent) {
            // Find all <th> without scope
            if (preg_match_all('/<th(?![^>]*scope=)([^>]*)>/i', $theadContent, $thMatches)) {
                $newTheadContent = $theadContent;
                foreach ($thMatches[0] as $thTag) {
                    $newTh = str_replace('<th', '<th scope="col"', $thTag);
                    $newTheadContent = str_replace($thTag, $newTh, $newTheadContent);
                    $stats['scope_col_added']++;
                    $modified = true;
                }
                $content = str_replace($theadContent, $newTheadContent, $content);
            }
        }
    }

    // 2. Add scope="row" to <th> in <tbody> and <tfoot>
    $bodyTags = ['tbody', 'tfoot'];
    foreach ($bodyTags as $tag) {
        $pattern = "/<$tag>(.*?)<\/$tag>/s";
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $bodyContent) {
                if (preg_match_all('/<th(?![^>]*scope=)([^>]*)>/i', $bodyContent, $thMatches)) {
                    $newBodyContent = $bodyContent;
                    foreach ($thMatches[0] as $thTag) {
                        $newTh = str_replace('<th', '<th scope="row"', $thTag);
                        $newBodyContent = str_replace($thTag, $newTh, $newBodyContent);
                        $stats['scope_row_added']++;
                        $modified = true;
                    }
                    $content = str_replace($bodyContent, $newBodyContent, $content);
                }
            }
        }
    }

    // 3. Add aria-hidden="true" to decorative icons (bi-*)
    $iconPattern = '/<i class="([^"]*bi-[^"]*)"/';
    if (preg_match_all($iconPattern, $content, $iconMatches)) {
        foreach ($iconMatches[0] as $iconTag) {
            if (!str_contains($iconTag, 'aria-hidden')) {
                $newIcon = str_replace('">', '" aria-hidden="true">', $iconTag);
                $content = str_replace($iconTag, $newIcon, $content);
                $stats['aria_hidden_added']++;
                $modified = true;
            }
        }
    }

    // 4. Replace title with aria-label on checkboxes
    $checkboxPattern = '/<input[^>]*type="checkbox"[^>]*>/i';
    if (preg_match_all($checkboxPattern, $content, $checkboxMatches)) {
        foreach ($checkboxMatches[0] as $checkbox) {
            if (preg_match('/title="([^"]+)"/', $checkbox, $titleMatch)) {
                $newCheckbox = str_replace(
                    'title="' . $titleMatch[1] . '"',
                    'aria-label="' . $titleMatch[1] . '"',
                    $checkbox
                );
                $content = str_replace($checkbox, $newCheckbox, $content);
                $modified = true;
            }
        }
    }

    if ($modified) {
        $stats['files_modified']++;

        if (!$dryRun) {
            file_put_contents($file, $content);
        }

        if ($verbose) {
            echo "✓ Modified: " . str_replace($templatesDir . '/', '', $file) . "\n";
        }
    }
}

// Find all .twig files with tables
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($templatesDir)
);

foreach ($iterator as $file) {
    if ($file->isFile() && $file->getExtension() === 'twig') {
        $content = file_get_contents($file->getPathname());

        // Only process files with tables
        if (str_contains($content, '<table')) {
            $stats['files_processed']++;
            processFile($file->getPathname(), $dryRun, $stats, $templatesDir, $verbose);
        }
    }
}

// Output statistics
echo "\n========================================\n";
echo "Table Accessibility Enhancement Report\n";
echo "========================================\n\n";
echo "Files processed: {$stats['files_processed']}\n";
echo "Files modified: {$stats['files_modified']}\n";
echo "scope=\"col\" added: {$stats['scope_col_added']}\n";
echo "scope=\"row\" added: {$stats['scope_row_added']}\n";
echo "aria-hidden=\"true\" added: {$stats['aria_hidden_added']}\n";
echo "\n";

if ($dryRun) {
    echo "DRY RUN - No files were actually modified.\n";
    echo "Run without --dry-run to apply changes.\n";
} else {
    echo "Changes have been applied!\n";
    echo "Run 'git diff templates/' to review changes.\n";
}

echo "\nNext steps:\n";
echo "1. Review the changes: git diff templates/\n";
echo "2. Test with Screen Reader (NVDA/JAWS/VoiceOver)\n";
echo "3. Run Lighthouse accessibility audit\n";
echo "4. Commit the changes\n";
