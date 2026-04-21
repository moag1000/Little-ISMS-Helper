<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceMappingRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sprint 6 / M6 — Seed-Review-Queue.
 *
 * Auditor-Frage *„wer hat das Mapping gemacht?"* hat für seed-/CSV-
 * importierte Mappings keine menschliche Antwort. Diese Queue zeigt
 * alle Mappings mit maschineller Herkunft (`verifiedBy LIKE 'app:seed-%'`
 * / `consultant_template_import` / `csv_import_ui*`) solange sie
 * `reviewStatus='unreviewed'` sind. Ein Klick genügt für Approve/Reject.
 *
 * Das ergänzt die bestehende `MappingQualityController::reviewQueue`,
 * die auf `requiresReview=true` filtert und die meisten Seed-Einträge
 * nicht zeigt.
 */
#[IsGranted('ROLE_MANAGER')]
class SeedReviewQueueController extends AbstractController
{
    private const SEED_ORIGIN_PATTERNS = [
        'app:seed-',
        'consultant_template_import',
        'csv_import_ui',
        'mapping_wizard',
        'app:migrate-framework-version',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceMappingRepository $mappingRepository,
    ) {
    }

    #[Route('/compliance/mapping/seed-review', name: 'app_compliance_mapping_seed_review', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $filter = (string) $request->query->get('origin', 'all');
        $mappings = $this->fetchSeedOriginUnreviewed($filter);

        $counts = $this->countsByOrigin();

        return $this->render('compliance/mapping/seed_review.html.twig', [
            'mappings' => $mappings,
            'counts' => $counts,
            'filter' => $filter,
        ]);
    }

    #[Route('/compliance/mapping/seed-review/{id}/approve', name: 'app_compliance_mapping_seed_review_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function approve(int $id, Request $request): Response
    {
        return $this->transition($id, $request, 'approved');
    }

    #[Route('/compliance/mapping/seed-review/{id}/reject', name: 'app_compliance_mapping_seed_review_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reject(int $id, Request $request): Response
    {
        return $this->transition($id, $request, 'rejected');
    }

    private function transition(int $id, Request $request, string $status): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('seed_review_' . $id, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $mapping = $this->mappingRepository->find($id);
        if (!$mapping instanceof ComplianceMapping) {
            throw $this->createNotFoundException();
        }

        $mapping->setReviewStatus($status);
        $mapping->setReviewedAt(new DateTimeImmutable());
        $user = $this->getUser();
        $mapping->setReviewedBy($user?->getUserIdentifier());
        $notes = trim((string) $request->request->get('notes', ''));
        if ($notes !== '') {
            $mapping->setReviewNotes($notes);
        }

        $this->entityManager->flush();

        $this->addFlash('success', $status === 'approved'
            ? 'Mapping freigegeben.'
            : 'Mapping abgelehnt.');

        return $this->redirectToRoute('app_compliance_mapping_seed_review', [
            'origin' => $request->request->get('origin', 'all'),
        ]);
    }

    /** @return list<ComplianceMapping> */
    private function fetchSeedOriginUnreviewed(string $filter): array
    {
        $qb = $this->mappingRepository->createQueryBuilder('cm')
            ->where('cm.reviewStatus = :status')
            ->setParameter('status', 'unreviewed')
            ->orderBy('cm.verifiedBy', 'ASC')
            ->addOrderBy('cm.id', 'ASC');

        if ($filter !== 'all') {
            $qb->andWhere('cm.verifiedBy = :origin')
                ->setParameter('origin', $filter);
        } else {
            $orX = $qb->expr()->orX();
            foreach (self::SEED_ORIGIN_PATTERNS as $i => $prefix) {
                $orX->add('cm.verifiedBy LIKE :p' . $i);
                $qb->setParameter('p' . $i, $prefix . '%');
            }
            $qb->andWhere($orX);
        }

        /** @var list<ComplianceMapping> $result */
        $result = $qb->getQuery()->getResult();
        return $result;
    }

    /** @return array<string, int> */
    private function countsByOrigin(): array
    {
        $rows = $this->mappingRepository->createQueryBuilder('cm')
            ->select('cm.verifiedBy AS origin, COUNT(cm.id) AS n')
            ->where('cm.reviewStatus = :status')
            ->andWhere('cm.verifiedBy IS NOT NULL')
            ->setParameter('status', 'unreviewed')
            ->groupBy('cm.verifiedBy')
            ->getQuery()
            ->getArrayResult();

        $out = ['all' => 0];
        foreach ($rows as $row) {
            $origin = (string) $row['origin'];
            $n = (int) $row['n'];
            if (!$this->isSeedOrigin($origin)) {
                continue;
            }
            $out[$origin] = $n;
            $out['all'] += $n;
        }
        return $out;
    }

    private function isSeedOrigin(string $origin): bool
    {
        foreach (self::SEED_ORIGIN_PATTERNS as $prefix) {
            if (str_starts_with($origin, $prefix)) {
                return true;
            }
        }
        return false;
    }
}
