<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use App\Service\Upload\ProtectedUploads;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S7: training materials used to be linked straight into public/uploads/ — anybody with
 * the URL got the file, no login. They now live below var/uploads and come only through
 * app_training_material_download: same organisation (TrainingVoter), listed file only.
 */
final class TrainingMaterialDownloadTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $file;
    /** @var list<int> */
    private array $userIds = [];
    /** @var list<int> */
    private array $tenantIds = [];
    private ?int $trainingId = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uploads = new ProtectedUploads((string) static::getContainer()->getParameter('kernel.project_dir'));
        $name = 's7_material_' . uniqid() . '.pdf';
        $this->file = $uploads->directory(ProtectedUploads::TRAINING_MATERIALS) . '/' . $name;
        file_put_contents($this->file, '%PDF-1.4 material');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        $connection = $this->em->getConnection();
        if ($this->trainingId !== null) {
            $connection->delete('training_participation', ['training_id' => $this->trainingId]); // RESTRICT (S29)
            $connection->delete('training', ['id' => $this->trainingId]);
        }
        foreach ($this->userIds as $id) {
            $connection->delete('users', ['id' => $id]);
        }
        foreach ($this->tenantIds as $id) {
            $connection->delete('tenant', ['id' => $id]);
        }
        parent::tearDown();
    }

    #[Test]
    public function memberDownloadsAListedMaterial(): void
    {
        $own = $this->tenant('own');
        $training = $this->training($own);
        $this->client->loginUser($this->user($own));

        $this->client->request('GET', $this->url($training, basename($this->file)));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    #[Test]
    public function otherOrganisationIsRefused(): void
    {
        $training = $this->training($this->tenant('own'));
        $this->client->loginUser($this->user($this->tenant('other')));

        $this->client->request('GET', $this->url($training, basename($this->file)));

        // The tenant filter hides the training (404) or the voter refuses it (403) — never the file.
        self::assertContains($this->client->getResponse()->getStatusCode(), [403, 404]);
    }

    #[Test]
    public function aFileNotListedOnTheTrainingIsNotServed(): void
    {
        $own = $this->tenant('own');
        $training = $this->training($own);
        $this->client->loginUser($this->user($own));

        $this->client->request('GET', $this->url($training, 'someone_elses_file.pdf'));

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function anonymousVisitorGetsNoFile(): void
    {
        $training = $this->training($this->tenant('own'));

        $this->client->request('GET', $this->url($training, basename($this->file)));

        self::assertResponseRedirects();
    }

    private function url(Training $training, string $filename): string
    {
        return '/en/training/' . $training->getId() . '/material/' . $filename;
    }

    private function training(Tenant $tenant): Training
    {
        $training = new Training();
        $training->setTenant($tenant);
        $training->setTitle('S7 material ' . uniqid());
        $training->setDescription('S7');
        $training->setTrainingType('security_awareness');
        $training->setStatus('planned');
        $training->setMandatory(false);
        $training->setScheduledDate(new \DateTime('+1 month'));
        $training->setMaterialFiles([[
            'filename' => basename($this->file),
            'originalName' => 'Awareness.pdf',
            'mimeType' => 'application/pdf',
            'size' => 17,
            'uploadedAt' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]]);
        $this->em->persist($training);
        $this->em->flush();
        $this->trainingId = (int) $training->getId();

        return $training;
    }

    private function tenant(string $label): Tenant
    {
        $tenant = (new Tenant())->setCode('s7_' . $label . '_' . uniqid())->setName('S7 ' . $label);
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantIds[] = (int) $tenant->getId();

        return $tenant;
    }

    private function user(Tenant $tenant): User
    {
        $user = (new User())->setEmail('s7_mat_' . uniqid() . '@example.com')->setFirstName('S7')
            ->setLastName('Material')->setRoles(['ROLE_USER'])->setPassword('hashed_password')
            ->setTenant($tenant)->setAuthProvider('local')->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->userIds[] = (int) $user->getId();

        return $user;
    }
}
