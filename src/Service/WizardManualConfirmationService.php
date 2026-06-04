<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardManualConfirmation;
use App\Repository\WizardManualConfirmationRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Owns persistence of compliance-wizard manual-check sign-offs, keeping the
 * controller thin (no direct EntityManager writes) and the audit trail in one
 * place. Backs the genuinely-manual clauses (no entity to auto-detect from).
 */
final class WizardManualConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WizardManualConfirmationRepository $repository,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    public function confirm(
        Tenant $tenant,
        string $wizardKey,
        string $checkKey,
        ?User $user,
        ?string $note = null,
    ): WizardManualConfirmation {
        $confirmation = $this->repository->findOneForCheck($tenant, $wizardKey, $checkKey)
            ?? (new WizardManualConfirmation())
                ->setTenant($tenant)
                ->setWizardKey($wizardKey)
                ->setCheckKey($checkKey);

        $confirmation->setConfirmed(true);
        $confirmation->setConfirmedAt(new \DateTimeImmutable());
        $confirmation->setConfirmedBy($user);
        $confirmation->setNote($note !== null && trim($note) !== '' ? trim($note) : null);

        $this->entityManager->persist($confirmation);
        $this->entityManager->flush();

        $this->auditLogger?->log(
            'wizard_manual_check_confirmed',
            WizardManualConfirmation::class,
            $confirmation->getId(),
            null,
            ['wizard' => $wizardKey, 'check' => $checkKey],
            sprintf('Manual wizard check "%s" (%s) marked addressed', $checkKey, $wizardKey),
        );

        return $confirmation;
    }

    public function unconfirm(Tenant $tenant, string $wizardKey, string $checkKey): void
    {
        $existing = $this->repository->findOneForCheck($tenant, $wizardKey, $checkKey);
        if ($existing === null) {
            return;
        }

        $id = $existing->getId();
        $this->entityManager->remove($existing);
        $this->entityManager->flush();

        $this->auditLogger?->log(
            'wizard_manual_check_unconfirmed',
            WizardManualConfirmation::class,
            $id,
            null,
            ['wizard' => $wizardKey, 'check' => $checkKey],
            sprintf('Manual wizard check "%s" (%s) reopened', $checkKey, $wizardKey),
        );
    }
}
