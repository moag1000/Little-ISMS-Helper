<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * T2 (Gate 72 on console commands): isms:load-annex-a-controls created the 93 controls without an
 * organisation (invisible to everybody) and reset the first control with a matching id of ANY
 * organisation to "not applicable". It now loads per organisation and leaves existing controls alone.
 */
final class LoadAnnexAControlsCommandTest extends KernelTestCase
{
    #[Test]
    public function controlsAreLoadedForTheOrganisationAndOthersStayUntouched(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $id = bin2hex(random_bytes(4));
        $target = (new Tenant())->setName('Annex Target ' . $id)->setCode('ax_' . $id);
        $other = (new Tenant())->setName('Annex Other ' . $id)->setCode('ao_' . $id);
        $em->persist($target);
        $em->persist($other);
        $em->flush();
        $kept = (new Control())->setTenant($other)->setControlId('A.5.1')->setName('Own wording')
            ->setDescription('kept')->setCategory('Organizational')->setApplicable(true)->setImplementationStatus('implemented');
        $em->persist($kept);
        $em->flush();

        $tester = new CommandTester(new Application(self::$kernel)->find('isms:load-annex-a-controls'));
        self::assertSame(0, $tester->execute(['--tenant' => $target->getCode()]), $tester->getDisplay());

        $em->clear();
        $repo = self::getContainer()->get(ControlRepository::class);
        self::assertSame(93, $repo->count(['tenant' => $target->getId()]));
        $kept = $em->find(Control::class, $kept->getId());
        self::assertNotNull($kept);
        self::assertTrue($kept->isApplicable(), 'another organisation\'s control is not reset');
        self::assertSame('implemented', $kept->getImplementationStatus());
        self::assertSame('Own wording', $kept->getName());
        self::assertSame(1, $repo->count(['tenant' => $other->getId()]));
    }
}
