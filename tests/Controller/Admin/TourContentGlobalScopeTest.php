<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\GuidedTourStepOverride;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S25: tour overrides without an organisation are the global texts every organisation
 * sees. An admin whose organisation is gone (User.tenant is SET NULL on delete) wrote them
 * — only a SUPER_ADMIN may.
 */
final class TourContentGlobalScopeTest extends WebTestCase
{
    private const string TOUR = 'junior';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $tenant;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $id = uniqid('s25_', true);
        $this->tenant = (new Tenant())->setName('S25 ' . $id)->setCode('s25_' . str_replace('.', '', $id));
        $this->em->persist($this->tenant);
        $this->admin = new User();
        $this->admin->setEmail('admin_' . $id . '@example.com');
        $this->admin->setFirstName('Tour');
        $this->admin->setLastName('Admin');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->admin->setPassword('hashed_password');
        $this->admin->setTenant($this->tenant);
        $this->admin->setIsActive(true);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    #[Test]
    public function adminOfAnOrganisationWritesOnlyItsOwnTexts(): void
    {
        $this->client->loginUser($this->admin);

        $this->submitTexts();

        self::assertResponseRedirects();
        self::assertSame(0, $this->globalOverrides(), 'nothing global was written');
    }

    #[Test]
    public function adminWithoutOrganisationCannotWriteGlobalTexts(): void
    {
        $this->admin->setTenant(null);
        $this->em->flush();
        $this->client->loginUser($this->admin);

        $this->submitTexts();

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->globalOverrides());
    }

    private function submitTexts(): void
    {
        $crawler = $this->client->request('GET', '/en/admin/tours/content/' . self::TOUR);
        self::assertResponseIsSuccessful();
        $token = $crawler->filter('#tour_content_save_form input[name="_token"]')->attr('value');

        $this->client->request('POST', '/en/admin/tours/content/' . self::TOUR . '/save', [
            '_token' => $token,
            'title' => ['s25_probe_step|de' => 'S25 probe'],
            'body' => ['s25_probe_step|de' => 'S25 probe body'],
        ]);
    }

    private function globalOverrides(): int
    {
        return $this->em->getRepository(GuidedTourStepOverride::class)->count([
            'tenant' => null,
            'tourId' => self::TOUR,
            'stepId' => 's25_probe_step',
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->em->getRepository(GuidedTourStepOverride::class)->findBy(['stepId' => 's25_probe_step']) as $row) {
            $this->em->remove($row);
        }
        $user = $this->em->find(User::class, $this->admin->getId());
        if ($user !== null) {
            $this->em->remove($user);
        }
        $tenant = $this->em->find(Tenant::class, $this->tenant->getId());
        if ($tenant !== null) {
            $this->em->remove($tenant);
        }
        $this->em->flush();
        parent::tearDown();
    }
}
