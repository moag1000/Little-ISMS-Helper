<?php

declare(strict_types=1);

namespace App\Tests\Smoke\Planning;

use App\AlvaHint\Rule\Global\PlanningDueActionItemsRule;
use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke test for the Tier-2 "open action items due in <= 14 days" Alva hint.
 *
 * Verifies the rule fires (with correct count + deep-link params) only when an
 * open ActionItem is due within the 14-day window, and stays silent otherwise.
 */
final class PlanningDueActionItemsRuleSmokeTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PlanningDueActionItemsRule $rule;
    private ?Tenant $tenant = null;
    private ?User $user = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->rule = $container->get(PlanningDueActionItemsRule::class);

        $uniqueId = uniqid('rule_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Rule Tenant ' . $uniqueId);
        $this->tenant->setCode('rule_' . $uniqueId);
        $this->entityManager->persist($this->tenant);

        $this->user = new User();
        $this->user->setEmail('ruleuser_' . $uniqueId . '@example.com');
        $this->user->setFirstName('Rule');
        $this->user->setLastName('User');
        $this->user->setRoles(['ROLE_USER']);
        $this->user->setPassword('hashed');
        $this->user->setTenant($this->tenant);
        $this->user->setIsActive(true);
        $this->entityManager->persist($this->user);

        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        if ($this->tenant !== null) {
            $tenantId = $this->tenant->getId();
            try {
                foreach ($this->entityManager->getRepository(ActionItem::class)->findBy(['tenant' => $tenantId]) as $ai) {
                    $this->entityManager->remove($ai);
                }
                if ($this->user) {
                    $user = $this->entityManager->find(User::class, $this->user->getId());
                    if ($user) {
                        $this->entityManager->remove($user);
                    }
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

    private function seedActionItem(string $dueModifier): ActionItem
    {
        $item = new ActionItem();
        $item->setTitle('Due Item');
        $item->setStatus(ActionItem::STATUS_OPEN);
        $item->setDueDate(new \DateTimeImmutable($dueModifier));
        $item->setTenant($this->tenant);
        $this->entityManager->persist($item);
        $this->entityManager->flush();

        return $item;
    }

    #[Test]
    public function firesForItemDueWithin14Days(): void
    {
        $this->seedActionItem('+7 days');

        $hint = $this->rule->evaluate($this->tenant, $this->user);

        self::assertNotNull($hint);
        self::assertSame(['filter' => 'due'], $hint->actionRouteParams);
        self::assertSame('1', $hint->bodyTranslationParams['%count%']);
    }

    #[Test]
    public function silentWithNoDueItems(): void
    {
        // No action items at all.
        $hint = $this->rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }

    #[Test]
    public function silentForItemDueIn90Days(): void
    {
        $this->seedActionItem('+90 days');

        $hint = $this->rule->evaluate($this->tenant, $this->user);

        self::assertNull($hint);
    }
}
