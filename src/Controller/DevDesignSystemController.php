<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class DevDesignSystemController extends AbstractController
{
    #[Route('/dev/design-system', name: 'dev_design_system', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createNotFoundException();
        }

        return $this->render('dev/design_system.html.twig', [
            'spec_sections' => $this->loadSpecSections(),
        ]);
    }

    /**
     * Loads the canonical living-spec section HTML files shipped under
     * `docs/design_system/sections/`. Each file is a self-contained HTML
     * fragment that documents a single Aurora area (alva, tokens, modals, ...).
     *
     * Returned as a list of { key, title, html } so the template can render
     * collapsible `<details>` blocks next to the live macro demos.
     *
     * @return list<array{key: string, title: string, html: string}>
     */
    private function loadSpecSections(): array
    {
        $dir = $this->getParameter('kernel.project_dir') . '/docs/design_system/sections';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.html');
        if ($files === false) {
            return [];
        }

        $sections = [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $key = basename($file, '.html');
            $sections[] = [
                'key' => $key,
                'title' => ucwords(str_replace('-', ' ', $key)),
                'html' => (string) file_get_contents($file),
            ];
        }

        return $sections;
    }
}
