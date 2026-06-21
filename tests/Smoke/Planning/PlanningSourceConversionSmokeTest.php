<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Planning;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Entity\CorrectiveAction;
use App\Entity\SourceConversionConfig;
use App\Entity\Tenant;
use App\Service\Planning\Source\ActionItemConversionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke test for the source->ActionItem conversion intake hub.
 *
 * Seeds a convertible CorrectiveAction + an enabled SourceConversionConfig for
 * slug 'corrective_action', then verifies: (1) one ActionItem is created with
 * the correct origin + provenance reference, (2) a second run is idempotent,
 * (3) a disabled config converts nothing.
 */
final class PlanningSourceConversionSmokeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ActionItemConversionService $conversionService;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->conversionService = $container->get(ActionItemConversionService::class);

        $uniqueId = uniqid('conv_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Conv Tenant ' . $uniqueId);
        $this->tenant->setCode('conv_' . $uniqueId);
        $this->entityManager->persist($this->tenant);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null) {
            $tenantId = $this->tenant->getId();
            try {
                // Remove dependents first (ActionItem cascades its references).
                foreach ($this->entityManager->getRepository(ActionItem::class)->findBy(['tenant' => $tenantId]) as $ai) {
                    $this->entityManager->remove($ai);
                }
                foreach ($this->entityManager->getRepository(SourceConversionConfig::class)->findBy(['tenant' => $tenantId]) as $cfg) {
                    $this->entityManager->remove($cfg);
                }
                foreach ($this->entityManager->getRepository(CorrectiveAction::class)->findBy(['tenant' => $tenantId]) as $ca) {
                    $this->entityManager->remove($ca);
                }
                $this->entityManager->flush();

                $tenant = $this->entityManager->find(Tenant::class, $tenantId);
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                    $this->entityManager->flush();
                }
            } catch (\Exception) {
            }
        }

        parent::tearDown();
    }

    private function seedCorrectiveAction(): CorrectiveAction
    {
        $ca = new CorrectiveAction();
        $ca->setTenant($this->tenant);
        $ca->setTitle('Smoke CAPA');
        $ca->setDescription('Smoke description');
        $ca->setStatus(CorrectiveAction::STATUS_PLANNED);
        $ca->setPlannedCompletionDate(new \DateTimeImmutable('+10 days'));
        $this->entityManager->persist($ca);
        $this->entityManager->flush();

        return $ca;
    }

    private function seedConfig(bool $enabled): SourceConversionConfig
    {
        $config = new SourceConversionConfig();
        $config->setTenant($this->tenant);
        $config->setSourceSlug('corrective_action');
        $config->setEnabled($enabled);
        $this->entityManager->persist($config);
        $this->entityManager->flush();

        return $config;
    }

    #[Test]
    public function convertsCorrectiveActionAndIsIdempotent(): void
    {
        $ca = $this->seedCorrectiveAction();
        $this->seedConfig(true);

        // First run — creates exactly one ActionItem.
        $created = $this->conversionService->convertForTenant($this->tenant);
        self::assertSame(['corrective_action' => 1], $created);

        $items = $this->entityManager->getRepository(ActionItem::class)->findBy(['tenant' => $this->tenant->getId()]);
        self::assertCount(1, $items);

        $item = $items[0];
        self::assertSame('corrective_action', $item->getOrigin());

        $references = $this->entityManager->getRepository(ActionItemReference::class)->findBy(['tenant' => $this->tenant->getId()]);
        self::assertCount(1, $references);
        self::assertSame('corrective_action', $references[0]->getRefType());
        self::assertSame($ca->getId(), $references[0]->getRefId());

        // Second run — idempotent, no new ActionItem.
        $createdAgain = $this->conversionService->convertForTenant($this->tenant);
        self::assertSame([], $createdAgain);

        $itemsAfter = $this->entityManager->getRepository(ActionItem::class)->findBy(['tenant' => $this->tenant->getId()]);
        self::assertCount(1, $itemsAfter);
    }

    #[Test]
    public function disabledConfigConvertsNothing(): void
    {
        $this->seedCorrectiveAction();
        $this->seedConfig(false);

        $created = $this->conversionService->convertForTenant($this->tenant);
        self::assertSame([], $created);

        $items = $this->entityManager->getRepository(ActionItem::class)->findBy(['tenant' => $this->tenant->getId()]);
        self::assertCount(0, $items);
    }
}
