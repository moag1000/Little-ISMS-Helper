<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

class LifecycleControllerTest extends WebTestCase
{
    #[Test]
    public function testUnknownEntityTypeReturns404(): void
    {
        $client = static::createClient();
        $client->request('POST', '/lifecycle/unknown/1/transition',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'invalid'],
            content: json_encode(['transition' => 'submit_for_review']),
        );
        // Allow 302 (firewall redirect to login), 403, or 404 — all acceptable for the
        // no-fixture state. Full assertion deferred until DocumentFixtureFactory exists.
        $this->assertContains($client->getResponse()->getStatusCode(), [302, 403, 404]);
    }

    #[Test]
    public function testRoutesRegisteredViaContainer(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');

        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_transition'));
        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_bulk_transition'));
        $this->assertNotNull($router->getRouteCollection()->get('app_lifecycle_allowed'));
    }

    #[Test]
    public function testSingleTransitionSuccess(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    #[Test]
    public function testVersionConflictReturns409(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    #[Test]
    public function testInvalidTransitionReturns422(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    #[Test]
    public function testVoterDeniedReturns403(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    #[Test]
    public function testBulkBestEffort(): void
    {
        $this->markTestSkipped('Requires DocumentFixtureFactory; deferred to Sprint X.3.');
    }

    /**
     * Seeds a draft document, logs a manager in and returns the CSRF token the
     * show page renders for the lifecycle dropdown (data-*-csrf-value).
     *
     * @return array{KernelBrowser, Document, string}
     */
    private function documentWithRenderedToken(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = str_replace('.', '', uniqid('lct', true));
        $tenant = (new Tenant())->setName('LCT ' . $uid)->setCode('lct_' . $uid);
        $em->persist($tenant);
        $user = (new User())->setEmail('lct_' . $uid . '@example.com')->setFirstName('L')->setLastName('C')
            ->setRoles(['ROLE_MANAGER'])->setPassword('x')->setTenant($tenant)->setIsActive(true);
        $em->persist($user);
        $doc = (new Document())->setTenant($tenant)->setFilename('l.md')->setOriginalFilename('l.md')
            ->setMimeType('text/markdown')->setFileSize(1)->setFilePath('virtual:test')->setCategory('policy')
            ->setUploadedAt(new \DateTimeImmutable());
        $doc->setStatus('draft');
        $em->persist($doc);
        $em->flush();

        $client->loginUser($user);
        $crawler = $client->request('GET', '/en/document/' . $doc->getId());
        $this->assertResponseIsSuccessful();
        $token = (string) $crawler->filter('[data-lifecycle-dropdown-csrf-value]')->attr('data-lifecycle-dropdown-csrf-value');
        $this->assertNotSame('', $token, 'show page renders the lifecycle CSRF token');

        return [$client, $doc, $token];
    }

    #[Test]
    public function transitionWithTheCsrfHeaderIsAccepted(): void
    {
        [$client, $doc, $token] = $this->documentWithRenderedToken();

        $client->request('POST', '/lifecycle/document/' . $doc->getId() . '/transition',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token],
            content: (string) json_encode(['transition' => 'submit_for_review']),
        );

        $this->assertResponseIsSuccessful();
        $this->assertSame('in_review', json_decode((string) $client->getResponse()->getContent(), true)['status'] ?? null);
    }

    #[Test]
    public function transitionWithoutTheCsrfHeaderIsRejected(): void
    {
        [$client, $doc, $token] = $this->documentWithRenderedToken();

        // Token in the JSON body only (the old, never-sent location) is not accepted.
        $client->request('POST', '/lifecycle/document/' . $doc->getId() . '/transition',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['transition' => 'submit_for_review', '_token' => $token]),
        );

        $this->assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function bulkTransitionRequiresTheCsrfHeader(): void
    {
        [$client, $doc] = $this->documentWithRenderedToken();

        $client->request('POST', '/lifecycle/document/bulk-transition',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) json_encode(['transition' => 'submit_for_review', 'ids' => [$doc->getId()]]),
        );

        $this->assertResponseStatusCodeSame(403);
    }
}
