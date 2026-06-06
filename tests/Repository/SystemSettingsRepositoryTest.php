<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SystemSettings;
use App\Repository\SystemSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies that "encrypted" settings are actually encrypted at rest — the
 * previous placeholder stored the plaintext verbatim in encrypted_value.
 */
class SystemSettingsRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private SystemSettingsRepository $repo;
    private string $category;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repo = static::getContainer()->get(SystemSettingsRepository::class);
        $this->category = 'test_enc_' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->repo->findBy(['category' => $this->category]) as $s) {
            $this->em->remove($s);
        }
        $this->em->flush();
        parent::tearDown();
    }

    #[Test]
    public function testEncryptedSettingIsCipheredAtRestAndRoundTrips(): void
    {
        $secret = 'super-secret-api-token-42';

        $this->repo->setSetting($this->category, 'api_token', $secret, isEncrypted: true);

        /** @var SystemSettings $stored */
        $stored = $this->repo->findOneBy(['category' => $this->category, 'key' => 'api_token']);

        // Stored ciphertext is NOT the plaintext (sodium secretbox, "enc:" prefix).
        self::assertTrue($stored->isEncrypted());
        self::assertNotSame($secret, $stored->getEncryptedValue());
        self::assertStringStartsWith('enc:', (string) $stored->getEncryptedValue());
        self::assertNull($stored->getValue());

        // Round-trips back to the plaintext on read.
        self::assertSame($secret, $this->repo->getSetting($this->category, 'api_token'));
    }

    #[Test]
    public function testPlainSettingStaysPlain(): void
    {
        $this->repo->setSetting($this->category, 'feature_flag', 'on', isEncrypted: false);

        $stored = $this->repo->findOneBy(['category' => $this->category, 'key' => 'feature_flag']);
        self::assertFalse($stored->isEncrypted());
        self::assertNull($stored->getEncryptedValue());
        self::assertSame('on', $this->repo->getSetting($this->category, 'feature_flag'));
    }
}
