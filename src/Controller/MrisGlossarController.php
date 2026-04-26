<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Yaml\Yaml;

/**
 * Glossar-Seite zu MRIS-Begriffen.
 *
 * Liest fixtures/mris/help-texts.yaml und rendert alle Eintraege mit
 * vorhandenem `glossar`-Block alphabetisch sortiert. Die Sprache richtet
 * sich nach dem Request-Locale (de|en).
 *
 * Quelle Fachkonzept: Peddi, R. (2026). MRIS — Mythos-resistente
 * Informationssicherheit, v1.5. Lizenz: CC BY 4.0.
 */
#[IsGranted('ROLE_USER')]
final class MrisGlossarController extends AbstractController
{
    #[Route('/mris/glossar', name: 'app_mris_glossar', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $projectDir = (string) $this->getParameter('kernel.project_dir');
        $yamlPath = $projectDir . '/fixtures/mris/help-texts.yaml';

        $locale = $request->getLocale();
        if (!in_array($locale, ['de', 'en'], true)) {
            $locale = 'de';
        }

        $entries = [];
        if (is_file($yamlPath) && is_readable($yamlPath)) {
            /** @var array<string, mixed> $data */
            $data = Yaml::parseFile($yamlPath) ?? [];
            $items = $data['items'] ?? [];

            if (is_array($items)) {
                foreach ($items as $item) {
                    if (!is_array($item) || empty($item['glossar']) || !is_array($item['glossar'])) {
                        continue;
                    }
                    $g = $item['glossar'];

                    $term = $locale === 'en'
                        ? ($g['term_en'] ?? $g['term_de'] ?? '')
                        : ($g['term_de'] ?? $g['term_en'] ?? '');

                    $definition = $locale === 'en'
                        ? ($g['definition_en'] ?? $g['definition_de'] ?? '')
                        : ($g['definition_de'] ?? $g['definition_en'] ?? '');

                    $term = trim((string) $term);
                    $definition = trim((string) $definition);
                    if ($term === '' && $definition === '') {
                        continue;
                    }

                    $entries[] = [
                        'key' => (string) ($item['key'] ?? ''),
                        'term' => $term,
                        'definition' => $definition,
                        'analogy_9001' => trim((string) ($g['analogy_9001'] ?? '')),
                        'source' => trim((string) ($g['source'] ?? '')),
                    ];
                }
            }
        }

        // Alphabetisch nach Begriff sortieren (locale-aware).
        usort(
            $entries,
            static fn(array $a, array $b): int => strcoll(
                mb_strtolower($a['term']),
                mb_strtolower($b['term'])
            )
        );

        return $this->render('mris/glossar.html.twig', [
            'entries' => $entries,
            'locale' => $locale,
        ]);
    }
}
