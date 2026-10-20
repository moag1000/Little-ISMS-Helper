<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\CrisisTeam;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CrossTenantUserLinksCommandTest extends KernelTestCase
{
    #[Test]
    public function listsCrossTenantLinksOnlyAndExitsZero(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = str_replace('.', '', uniqid('ctl', true));
        $tenant = (new Tenant())->setName('CTL ' . $uid)->setCode('ctl_' . $uid);
        $foreign = (new Tenant())->setName('CTL F ' . $uid)->setCode('ctlf_' . $uid);
        $em->persist($tenant);
        $em->persist($foreign);
        $own = $this->user($em, 'own_' . $uid, $tenant);
        $alien = $this->user($em, 'alien_' . $uid, $foreign);
        $team = (new CrisisTeam())->setTenant($tenant)->setTeamName('T')->setTeamType('primary')
            ->setIsActive(true)->setTeamLeader($alien)->setDeputyLeader($own);
        $em->persist($team);
        $em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:data:cross-tenant-user-links'));
        $exit = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $exit);
        self::assertStringContainsString('alien_' . $uid, $display, 'cross-tenant leader listed');
        self::assertStringNotContainsString('own_' . $uid, $display, 'same-tenant deputy not listed');
        self::assertMatchesRegularExpression('/CrisisTeam\s+' . $team->getId() . '\s+teamLeader/', $display);
    }

    #[Test]
    public function finderKeepsAnEnabledTenantFilterAndItsParameter(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $filters = $em->getFilters();
        $filter = $filters->isEnabled('tenant_filter')
            ? $filters->getFilter('tenant_filter')
            : $filters->enable('tenant_filter');
        $filter->setParameter('tenant_id', '987654');

        static::getContainer()->get(\App\Service\DataIntegrity\CrossTenantUserLinkFinder::class)->find();

        self::assertTrue($filters->isEnabled('tenant_filter'));
        self::assertSame($filter, $filters->getFilter('tenant_filter'), 'filter instance replaced');
        self::assertSame("'987654'", $filters->getFilter('tenant_filter')->getParameter('tenant_id'));
    }

    private function user(EntityManagerInterface $em, string $name, Tenant $tenant): User
    {
        $user = (new User())->setEmail($name . '@example.com')->setFirstName('F')->setLastName($name)
            ->setRoles(['ROLE_USER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $em->persist($user);

        return $user;
    }
}
