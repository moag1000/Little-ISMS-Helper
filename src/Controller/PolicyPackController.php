<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\PolicyTemplateRepository;
use App\Service\PolicyWizard\PolicyPackAdapter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F38 — Policy-Pack export.
 *
 * Downloads the curated policy-template catalogue (optionally filtered by
 * standard) as a portable, versioned policy-pack JSON. Export-only by design —
 * see {@see PolicyPackAdapter} for the curated-library import constraint.
 */
#[IsGranted('ROLE_ADMIN')]
final class PolicyPackController extends AbstractController
{
    public function __construct(
        private readonly PolicyTemplateRepository $templateRepository,
        private readonly PolicyPackAdapter $adapter,
    ) {
    }

    #[Route('/admin/policy-packs/export', name: 'admin_policy_pack_export', methods: ['GET'])]
    public function export(Request $request): Response
    {
        $standard = $request->query->get('standard');
        $locale   = $request->getLocale();

        $templates = is_string($standard) && $standard !== ''
            ? $this->templateRepository->findActiveByStandard($standard)
            : $this->templateRepository->findBy(['active' => true], ['key' => 'ASC']);

        $packName = is_string($standard) && $standard !== ''
            ? 'policy-pack-' . preg_replace('/[^a-z0-9]+/i', '-', $standard)
            : 'policy-pack-all';

        $json = $this->adapter->export($templates, $locale, $packName);

        return new Response($json, Response::HTTP_OK, [
            'Content-Type'        => 'application/json; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.json"', $packName),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }
}
