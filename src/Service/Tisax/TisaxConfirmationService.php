<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\Tenant;
use App\Entity\TisaxLicenseConfirmation;
use App\Entity\User;
use App\Repository\TisaxLicenseConfirmationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Encapsulates the persistence of TisaxLicenseConfirmation records.
 *
 * Extracted from TisaxImportWizardController to keep em-write out of controllers.
 * ISO 27001 Clause 7.5.3: each confirmation is recorded with user, tenant,
 * session token (SHA-256 of session ID), IP address, and timestamp.
 */
final class TisaxConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TisaxLicenseConfirmationRepository $repository,
    ) {}

    /**
     * Persist a new licence confirmation and return the saved entity.
     *
     * @param Tenant $tenant    Current tenant (multi-tenancy guard)
     * @param User   $user      The confirming user
     * @param string $sessionId Raw Symfony session ID (will be SHA-256 hashed internally)
     * @param string $ipAddress Client IP from Request::getClientIp()
     */
    public function record(
        Tenant $tenant,
        User $user,
        string $sessionId,
        string $ipAddress,
    ): TisaxLicenseConfirmation {
        $confirmation = new TisaxLicenseConfirmation();
        $confirmation->setTenant($tenant);
        $confirmation->setUser($user);
        $confirmation->setWorkbookFilename('(pending upload)');
        $confirmation->setIpAddress($ipAddress);
        $confirmation->setSessionToken(hash('sha256', $sessionId));

        $this->em->persist($confirmation);
        $this->em->flush();

        return $confirmation;
    }

    /**
     * Update the workbook filename on an existing confirmation after a
     * successful upload (Step 0 → Step 1 filename backfill).
     */
    public function updateFilename(int $confirmationId, string $filename): void
    {
        $confirmation = $this->repository->find($confirmationId);
        if ($confirmation === null) {
            return;
        }

        $confirmation->setWorkbookFilename($filename);
        $this->em->flush();
    }
}
