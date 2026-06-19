<?php

declare(strict_types=1);

namespace App\Service\Certificate;

use App\Entity\ComplianceCertificate;
use App\Entity\User;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use App\Service\ComplianceRequirementFulfillmentService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies a ComplianceCertificate to every compliance requirement it covers.
 *
 * For each covered requirement the matching fulfillment is marked
 * Verified-via-certificate (100 %), the certificate PDF is attached as shared
 * evidence, the verification fields are stamped, and the next-review date is set
 * to the certificate's expiry. A single bulk audit-log batch records the change.
 *
 * The operation is idempotent: re-applying the same certificate does not create
 * duplicate fulfillment rows nor duplicate evidence links.
 */
final class CertificateBulkFulfillmentService
{
    public function __construct(
        private readonly CertificateCoverageResolver $coverageResolver,
        private readonly ComplianceRequirementFulfillmentService $fulfillmentService,
        private readonly ComplianceRequirementRepository $reqRepo,
        private readonly ComplianceFrameworkRepository $frameworkRepo,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Apply the certificate to all covered requirement fulfillments.
     *
     * @return array{fulfilled: int, isFallback: bool} Number of fulfillments
     *                                                  touched + whether the
     *                                                  framework-wide fallback
     *                                                  coverage was used.
     */
    public function apply(ComplianceCertificate $cert, User $actor): array
    {
        $res = $this->coverageResolver->resolve($cert);

        $framework = $this->frameworkRepo->findOneBy(['code' => $cert->getFrameworkCode()]);
        if ($framework === null) {
            return ['fulfilled' => 0, 'isFallback' => $res->isFallback];
        }

        $tenant = $cert->getTenant();
        if ($tenant === null) {
            return ['fulfilled' => 0, 'isFallback' => $res->isFallback];
        }

        $now = new DateTimeImmutable();
        $document = $cert->getCertificateDocument();
        $validUntil = $cert->getValidUntil();

        $perEntity = [];

        foreach ($res->requirementIds as $rid) {
            $req = $this->reqRepo->findOneBy([
                'framework' => $framework,
                'requirementId' => (string) $rid,
            ]);
            if ($req === null) {
                continue;
            }

            $fulfillment = $this->fulfillmentService->getOrCreateFulfillment($tenant, $req);
            $fulfillment->setFulfillmentPercentage(100)
                ->setStatus(ComplianceRequirementFulfillmentStatus::Verified)
                ->setVerifiedAt($now)
                ->setVerifiedBy($actor)
                ->setEvidenceOutdated(false);

            if ($document !== null) {
                // addEvidenceDocument() is contains()-guarded → idempotent.
                $fulfillment->addEvidenceDocument($document);
            }

            if ($validUntil !== null) {
                $fulfillment->setNextReviewDate($validUntil);
            }

            $this->em->persist($fulfillment);

            $perEntity[] = [
                'entity_id' => $req->getId(),
                'action' => 'update',
                'new_values' => [
                    'requirement_id' => $req->getId(),
                    'fulfillment_percentage' => 100,
                    'status' => ComplianceRequirementFulfillmentStatus::Verified->value,
                ],
            ];
        }

        $this->em->flush();

        if ($perEntity !== []) {
            $this->auditLogger->logBulk(
                'bulk_attach',
                'ComplianceRequirementFulfillment',
                [
                    'source' => 'certificate',
                    'certificate_id' => $cert->getId(),
                ],
                $perEntity,
                'Fulfilled via certificate ' . (string) $cert->getCertNumber(),
            );
        }

        return ['fulfilled' => count($perEntity), 'isFallback' => $res->isFallback];
    }
}
