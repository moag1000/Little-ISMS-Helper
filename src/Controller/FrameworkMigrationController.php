<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\FrameworkVersionMigrator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sprint 5 / M5 — Versions-Migrations-UI.
 *
 * UI für den FrameworkVersionMigrator — löst das CLI-Only-Problem
 * für ISO 27001:2013 → 2022, C5:2020 → 2026, NIS2-Updates.
 *
 *  - GET  /compliance/framework/{id}/migrate  → Formular
 *  - POST mit preview=1                        → Dry-Run-Preview
 *  - POST mit commit=1                         → Bridges anlegen
 *
 * Rollt transitive Coverage aus alter Version in die neue ein, ohne
 * dass Nachweise neu verknüpft werden müssen.
 */
#[IsGranted('ROLE_MANAGER')]
class FrameworkMigrationController extends AbstractController
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly FrameworkVersionMigrator $migrator,
    ) {
    }

    #[Route('/compliance/framework/{id}/migrate', name: 'app_compliance_framework_migrate', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function migrate(int $id, Request $request): Response
    {
        $old = $this->frameworkRepository->find($id);
        if (!$old instanceof ComplianceFramework) {
            throw $this->createNotFoundException();
        }

        $candidates = $this->candidateTargets($old);
        $result = null;
        $error = null;
        $targetId = (int) $request->request->get('target_framework_id', 0);
        $strategy = (string) $request->request->get('strategy', FrameworkVersionMigrator::MATCH_STRATEGY_ID);
        $commit = $request->request->getBoolean('commit');

        if ($request->isMethod('POST')) {
            $token = (string) $request->request->get('_token');
            if (!$this->isCsrfTokenValid('framework_migrate_' . $id, $token)) {
                throw $this->createAccessDeniedException('Invalid CSRF token.');
            }

            $new = $targetId > 0 ? $this->frameworkRepository->find($targetId) : null;
            if (!$new instanceof ComplianceFramework) {
                $error = 'compliance.framework.migrate.error.target_required';
            } elseif ($new->getId() === $old->getId()) {
                $error = 'compliance.framework.migrate.error.same_framework';
            } elseif (!in_array($strategy, [
                FrameworkVersionMigrator::MATCH_STRATEGY_ID,
                FrameworkVersionMigrator::MATCH_STRATEGY_TITLE,
                FrameworkVersionMigrator::MATCH_STRATEGY_BOTH,
            ], true)) {
                $error = 'compliance.framework.migrate.error.invalid_strategy';
            } else {
                $result = $this->migrator->migrate($old, $new, $strategy, $commit);

                if ($commit && $result['bridges_created'] > 0) {
                    $this->addFlash('success', sprintf(
                        '%d Bridge-Mapping(s) angelegt, %d bestehend übersprungen.',
                        $result['bridges_created'],
                        $result['bridges_skipped_existing'],
                    ));
                }
            }
        }

        return $this->render('compliance/framework/migrate.html.twig', [
            'old_framework' => $old,
            'candidates' => $candidates,
            'target_id' => $targetId,
            'strategy' => $strategy,
            'result' => $result,
            'error' => $error,
            'was_commit' => $commit,
        ]);
    }

    /** @return list<ComplianceFramework> */
    private function candidateTargets(ComplianceFramework $old): array
    {
        $all = $this->frameworkRepository->findBy([], ['code' => 'ASC']);
        $out = [];
        foreach ($all as $fw) {
            if ($fw->getId() === $old->getId()) {
                continue;
            }
            $out[] = $fw;
        }
        return $out;
    }
}
