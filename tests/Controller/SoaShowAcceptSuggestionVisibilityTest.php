<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\MappingSuggestionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The SoA show page offers "accept suggestion" only to users who may edit the
 * control (ControlVoter::EDIT) — the POST route demands the same permission.
 */
final class SoaShowAcceptSuggestionVisibilityTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private Control $control;
    private ComplianceRequirement $requirement;
    private string $uid;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = str_replace('.', '', uniqid('soa', true));

        $this->tenant = (new Tenant())->setName('SOA Tenant ' . $this->uid)->setCode('soat_' . $this->uid);
        $this->em->persist($this->tenant);
        $this->control = (new Control())
            ->setControlId('A.9.' . substr($this->uid, -4))
            ->setName('Ctl ' . $this->uid)
            ->setDescription('d')
            ->setCategory('technological')
            ->setApplicable(true)
            ->setTenant($this->tenant);
        $this->em->persist($this->control);
        $framework = (new ComplianceFramework())->setCode('SOA-' . $this->uid)->setName('SOA FW ' . $this->uid)
            ->setVersion('1.0')->setApplicableIndustry('all')->setRegulatoryBody('Body')->setMandatory(false)->setActive(true);
        $this->em->persist($framework);
        $this->requirement = (new ComplianceRequirement())->setFramework($framework)->setRequirementId('SOA-' . $this->uid)
            ->setTitle('Req ' . $this->uid)->setDescription('d')->setCategory('test')->setPriority('medium')->setRequirementType('core');
        $this->em->persist($this->requirement);
        $this->em->flush();

        $suggestions = [$framework->getCode() => [[
            'requirement' => $this->requirement,
            'confidence' => 0.8,
            'framework' => $framework,
        ]]];
        $service = $this->createStub(MappingSuggestionService::class);
        $service->method('suggestForControl')->willReturn($suggestions);
        $service->method('totalCount')->willReturn(1);
        static::getContainer()->set(MappingSuggestionService::class, $service);
    }

    private function user(string $role): User
    {
        $user = (new User())
            ->setEmail(strtolower($role) . '_' . $this->uid . '@example.com')
            ->setFirstName('S')->setLastName($role . $this->uid)
            ->setRoles([$role])
            ->setPassword('hashed_password')
            ->setTenant($this->tenant)
            ->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function acceptFormCount(User $user): int
    {
        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', sprintf('/en/soa/%d', $this->control->getId()));
        self::assertResponseIsSuccessful();
        // The suggestion block itself must be rendered for both roles.
        self::assertStringContainsString('SOA-' . $this->uid, (string) $this->client->getResponse()->getContent());

        return $crawler->filter(sprintf(
            'form[action*="/accept-suggestion/%d"]',
            $this->requirement->getId(),
        ))->count();
    }

    #[Test]
    public function acceptButtonIsHiddenWithoutControlEdit(): void
    {
        self::assertSame(0, $this->acceptFormCount($this->user('ROLE_USER')));
    }

    #[Test]
    public function acceptButtonIsShownWithControlEdit(): void
    {
        self::assertSame(1, $this->acceptFormCount($this->user('ROLE_MANAGER')));
    }
}
