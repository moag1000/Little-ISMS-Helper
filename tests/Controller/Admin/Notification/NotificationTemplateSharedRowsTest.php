<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Notification;

use App\Entity\Notification\NotificationRule;
use App\Entity\Notification\NotificationTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Global notification templates (tenant NULL) belong to every organisation:
 * the tenant filter must not hide them from the gallery or from "apply",
 * while another organisation's own templates stay invisible.
 */
final class NotificationTemplateSharedRowsTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Tenant $own;
    private Tenant $foreign;
    private User $admin;
    private NotificationTemplate $global;
    private NotificationTemplate $foreignTemplate;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $marker = uniqid();

        $this->own = (new Tenant())->setCode('ntpl_own_' . $marker)->setName('Own ' . $marker);
        $this->foreign = (new Tenant())->setCode('ntpl_foreign_' . $marker)->setName('Foreign ' . $marker);
        $this->em->persist($this->own);
        $this->em->persist($this->foreign);
        $this->admin = (new User())->setEmail('ntpl_admin_' . $marker . '@example.com')->setFirstName('Template')
            ->setLastName('Admin')->setRoles(['ROLE_ADMIN'])->setPassword('hashed_password')
            ->setTenant($this->own)->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($this->admin);
        $this->global = $this->template(null, 'Global template ' . $marker);
        $this->foreignTemplate = $this->template($this->foreign, 'Foreign template ' . $marker);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $owned = [[User::class, $this->admin->getId()], [NotificationTemplate::class, $this->global->getId()]];
        foreach ($owned as [$class, $id]) {
            $entity = $this->em->find($class, $id);
            if ($entity !== null) {
                $this->em->remove($entity);
            }
        }
        $this->em->flush();
        // Tenant removal cascades (ON DELETE CASCADE) to their templates, rules and channels.
        foreach ([$this->own->getId(), $this->foreign->getId()] as $tenantId) {
            $this->em->getConnection()->delete('tenant', ['id' => $tenantId]);
        }
        parent::tearDown();
    }

    #[Test]
    public function galleryListsGlobalTemplatesButNotForeignOnes(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/en/admin/notification/template');

        self::assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->global->getName(), $content);
        self::assertStringNotContainsString($this->foreignTemplate->getName(), $content);
    }

    #[Test]
    public function applyingAGlobalTemplateCreatesARuleForTheOwnOrganisation(): void
    {
        $id = (int) $this->global->getId();
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/en/admin/notification/template/' . $id . '/apply', [
            '_token' => $this->seedCsrf('notification_template_apply_' . $id),
        ]);

        self::assertResponseRedirects();
        $rules = $this->em->getRepository(NotificationRule::class)->findBy(['tenant' => $this->own]);
        self::assertCount(1, $rules);
        self::assertSame($this->global->getName(), $rules[0]->getName());
    }

    #[Test]
    public function anotherOrganisationsTemplateCannotBeApplied(): void
    {
        $id = (int) $this->foreignTemplate->getId();
        $this->client->loginUser($this->admin);

        $this->client->request('POST', '/en/admin/notification/template/' . $id . '/apply', [
            '_token' => $this->seedCsrf('notification_template_apply_' . $id),
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    private function template(?Tenant $tenant, string $name): NotificationTemplate
    {
        $template = (new NotificationTemplate())->setTenant($tenant)->setTemplateKey('ntpl_' . uniqid())
            ->setName($name)->setDefaultEventType('incident.created')->setDefaultConditions([])
            ->setDefaultChannels([])->setCategory(NotificationTemplate::CATEGORY_INCIDENT);
        $this->em->persist($template);

        return $template;
    }

    private function seedCsrf(string $tokenId): string
    {
        $this->client->request('GET', '/en/admin/notification/template');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }
}
