<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ScheduledReport;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\ScheduledReportType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * ISB MINOR-4: form-time tenant + role gate.
 *
 * Saving a scheduled report with a recipient that resolves to a plain
 * ROLE_USER (below MANAGER) must produce an invalid form.
 */
class ScheduledReportFormValidationTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private FormFactoryInterface $formFactory;
    private ?Tenant $tenant = null;
    private ?User $managerUser = null;
    private ?User $regularUser = null;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->formFactory = $container->get(FormFactoryInterface::class);

        $this->tenant = new Tenant();
        $this->tenant->setCode('TEST_MINOR4_' . uniqid());
        $this->tenant->setName('Test Tenant MINOR-4');
        $this->em->persist($this->tenant);

        $this->managerUser = $this->makeUser(
            'manager-minor4-' . uniqid() . '@tenant.test',
            $this->tenant,
            ['ROLE_MANAGER'],
        );
        $this->regularUser = $this->makeUser(
            'user-minor4-' . uniqid() . '@tenant.test',
            $this->tenant,
            ['ROLE_USER'],
        );

        $this->em->persist($this->managerUser);
        $this->em->persist($this->regularUser);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->managerUser, $this->regularUser] as $user) {
            if ($user && $user->getId()) {
                $fresh = $this->em->find(User::class, $user->getId());
                if ($fresh) {
                    $this->em->remove($fresh);
                }
            }
        }
        if ($this->tenant && $this->tenant->getId()) {
            $fresh = $this->em->find(Tenant::class, $this->tenant->getId());
            if ($fresh) {
                $this->em->remove($fresh);
            }
        }
        try {
            $this->em->flush();
        } catch (\Throwable) {
            // Ignore cleanup failures between test runs.
        }
        parent::tearDown();
    }

    public function testFormRejectsUserRoleRecipient(): void
    {
        $report = new ScheduledReport();
        $report->setTenantId($this->tenant->getId());

        $form = $this->formFactory->createBuilder(ScheduledReportType::class, $report, ['csrf_protection' => false])
            ->getForm();
        $form->submit([
            'name' => 'Weekly Risk Report',
            'reportType' => ScheduledReport::TYPE_RISK,
            'schedule' => ScheduledReport::SCHEDULE_WEEKLY,
            'format' => ScheduledReport::FORMAT_PDF,
            'recipientsText' => $this->regularUser->getEmail(),
            'locale' => 'de',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertFalse($form->isValid(), 'Form must reject recipient below ROLE_MANAGER.');

        $errors = (string) $form->getErrors(true, true);
        self::assertStringContainsString('role_too_low', $errors);
    }

    public function testFormAcceptsManagerRoleRecipient(): void
    {
        $report = new ScheduledReport();
        $report->setTenantId($this->tenant->getId());

        $form = $this->formFactory->createBuilder(ScheduledReportType::class, $report, ['csrf_protection' => false])
            ->getForm();
        $form->submit([
            'name' => 'Weekly Risk Report',
            'reportType' => ScheduledReport::TYPE_RISK,
            'schedule' => ScheduledReport::SCHEDULE_WEEKLY,
            'format' => ScheduledReport::FORMAT_PDF,
            'recipientsText' => $this->managerUser->getEmail(),
            'locale' => 'de',
        ]);

        self::assertTrue($form->isSubmitted());
        self::assertTrue($form->isValid(), 'Form must accept recipient with ROLE_MANAGER. Errors: ' . $form->getErrors(true, true));
        self::assertSame([$this->managerUser->getEmail()], $report->getRecipients());
    }

    private function makeUser(string $email, Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $user->setPassword('$2y$10$dummyhashdummyhashdummyhashdumm');
        $user->setRoles($roles);
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $user->setIsVerified(true);
        return $user;
    }
}
