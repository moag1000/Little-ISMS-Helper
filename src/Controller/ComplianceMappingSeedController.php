<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mapping-Seed-UI (Sprint 4 / M2).
 *
 * Aus dem 3-Personas-Walkthrough: die `app:seed-bsi-iso27001-mappings`,
 * `app:seed-soc2-iso27001-mappings` und `app:seed-c52026-iso27001-mappings`
 * Commands waren CLI-only. Der CM beklagte zu Recht, dass sein
 * Controller-Team SSH-Zugang bräuchte. Diese UI gibt den Seeds eine
 * Card-Übersicht mit Ein-Klick-Trigger.
 *
 * Implementierung aus Audit-Sicht defensiv: Der Seed-Prozess läuft via
 * `symfony/process` in-process; der CSRF-Schutz hängt an einer
 * POST-Route; das Ergebnis wird direkt in das Audit-Log geloggt (über
 * das aufgerufene Command selbst, das bereits eine verified_by-Spalte
 * setzt — kein zusätzliches Audit-Logging nötig).
 */
#[IsGranted('ROLE_MANAGER')]
class ComplianceMappingSeedController extends AbstractController
{
    /**
     * Kuratierte Seed-Inventur. IDs sind die Command-Namen ohne Prefix.
     *
     * @return list<array{
     *     id: string, command: string, source_code: string, target_code: string,
     *     source_label: string, target_label: string, mapping_count: int,
     *     rationale_source: string, icon: string
     * }>
     */
    private function seeds(): array
    {
        return [
            [
                'id' => 'bsi-iso27001',
                'command' => 'app:seed-bsi-iso27001-mappings',
                'source_code' => 'BSI_GRUNDSCHUTZ',
                'target_code' => 'ISO27001',
                'source_label' => 'BSI IT-Grundschutz (Kompendium 2023)',
                'target_label' => 'ISO/IEC 27001:2022 Annex A',
                'mapping_count' => 42,
                'rationale_source' => 'Offizielle BSI-Cross-Reference-Tabelle (Kompendium 2023)',
                'icon' => 'bi-shield-check',
            ],
            [
                'id' => 'soc2-iso27001',
                'command' => 'app:seed-soc2-iso27001-mappings',
                'source_code' => 'SOC2',
                'target_code' => 'ISO27001',
                'source_label' => 'SOC 2 Trust Services Criteria',
                'target_label' => 'ISO/IEC 27001:2022 Annex A',
                'mapping_count' => 38,
                'rationale_source' => 'AICPA Trust Services Criteria + Vanta/Drata Cross-Refs',
                'icon' => 'bi-globe-americas',
            ],
            [
                'id' => 'c52026-iso27001',
                'command' => 'app:seed-c52026-iso27001-mappings',
                'source_code' => 'BSI-C5-2026',
                'target_code' => 'ISO27001',
                'source_label' => 'BSI C5:2026 (Cloud Computing Compliance)',
                'target_label' => 'ISO/IEC 27001:2022 Annex A',
                'mapping_count' => 16,
                'rationale_source' => 'C5:2026 ↔ 27001:2022 Annex A (ISO-tagged + SCS + CNT + CSA + CFC)',
                'icon' => 'bi-cloud',
            ],
        ];
    }

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly TranslatorInterface $translator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/compliance/mappings/seeds', name: 'app_compliance_mapping_seeds_index', methods: ['GET'])]
    public function index(): Response
    {
        $rows = [];
        foreach ($this->seeds() as $seed) {
            $src = $this->frameworkRepository->findOneBy(['code' => $seed['source_code']]);
            $tgt = $this->frameworkRepository->findOneBy(['code' => $seed['target_code']]);
            $loaded = null;
            if ($src !== null && $tgt !== null) {
                $loaded = $this->countLoadedBetween($src, $tgt);
            }
            $rows[] = array_merge($seed, [
                'source_loaded' => $src !== null,
                'target_loaded' => $tgt !== null,
                'currently_loaded' => $loaded,
            ]);
        }

        return $this->render('compliance/mapping/seeds.html.twig', [
            'seeds' => $rows,
        ]);
    }

    #[Route('/compliance/mappings/seeds/{id}/apply', name: 'app_compliance_mapping_seeds_apply', methods: ['POST'], requirements: ['id' => '[a-z0-9-]+'])]
    public function apply(Request $request, string $id): Response
    {
        if (!$this->isCsrfTokenValid('seed_' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $seed = null;
        foreach ($this->seeds() as $s) {
            if ($s['id'] === $id) {
                $seed = $s;
                break;
            }
        }
        if ($seed === null) {
            throw $this->createNotFoundException(sprintf('Unknown seed "%s".', $id));
        }

        $process = new Process([
            'php',
            'bin/console',
            $seed['command'],
            '--no-interaction',
        ], $this->projectDir);
        $process->setTimeout(120);
        $process->run();

        if ($process->isSuccessful()) {
            $output = $process->getOutput();
            $created = 0;
            if (preg_match('/(\d+)\s+mapping\(s\)\s+created/i', $output, $m)) {
                $created = (int) $m[1];
            }
            $this->addFlash(
                'success',
                $this->translator->trans('compliance.mapping.seeds.flash.applied', [
                    '%source%' => $seed['source_label'],
                    '%target%' => $seed['target_label'],
                    '%count%' => $created,
                ], 'compliance')
            );
        } else {
            $this->addFlash(
                'danger',
                $this->translator->trans('compliance.mapping.seeds.flash.failed', [
                    '%source%' => $seed['source_label'],
                    '%target%' => $seed['target_label'],
                    '%stderr%' => mb_substr($process->getErrorOutput(), 0, 500),
                ], 'compliance')
            );
        }

        return $this->redirectToRoute('app_compliance_mapping_seeds_index');
    }

    private function countLoadedBetween(\App\Entity\ComplianceFramework $src, \App\Entity\ComplianceFramework $tgt): int
    {
        $qb = $this->mappingRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.sourceRequirement', 'sr')
            ->innerJoin('m.targetRequirement', 'tr')
            ->where('sr.complianceFramework = :src')
            ->andWhere('tr.complianceFramework = :tgt')
            ->setParameter('src', $src)
            ->setParameter('tgt', $tgt);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
