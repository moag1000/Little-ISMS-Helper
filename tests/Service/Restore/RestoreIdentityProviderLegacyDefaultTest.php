<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\IdentityProvider;
use App\Service\Restore\RestoreEntityWriter;
use App\Service\Restore\RestoreOptions;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059: a backup taken before identity_provider.allow_email_linking existed
 * must restore its providers with linking ON (what the upgrade migration gave
 * existing rows), not with the property default meant for new providers.
 * A backup that carries the field keeps its value.
 */
final class RestoreIdentityProviderLegacyDefaultTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    /** @var list<string> */
    private array $slugs = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        foreach ($this->slugs as $slug) {
            $this->em->getConnection()->executeStatement('DELETE FROM identity_provider WHERE slug = ?', [$slug]);
        }
        parent::tearDown();
    }

    #[Test]
    public function providerFromOldBackupKeepsLinkingByEmail(): void
    {
        self::assertTrue($this->restore(null));
    }

    #[Test]
    public function explicitValueFromBackupIsKept(): void
    {
        self::assertFalse($this->restore(false));
    }

    private function restore(?bool $allowEmailLinking): bool
    {
        $slug = 'rst-' . substr(md5(uniqid('', true)), 0, 10);
        $this->slugs[] = $slug;
        $row = [
            'slug' => $slug,
            'name' => 'Restored ' . $slug,
            'type' => 'oidc',
            'clientId' => 'client',
            'enabled' => true,
            'scopes' => ['openid'],
            'attributeMap' => ['email' => 'email'],
            'domainBindings' => [],
            'createdAt' => '2026-01-01T00:00:00+00:00',
        ];
        if ($allowEmailLinking !== null) {
            $row['allowEmailLinking'] = $allowEmailLinking;
        }

        $failures = [];
        $statistics = [];
        $warnings = [];
        static::getContainer()->get(RestoreEntityWriter::class)->restoreEntity(
            IdentityProvider::class,
            'IdentityProvider',
            [$row],
            [
                'best_effort' => false,
                'existing_data_strategy' => RestoreOptions::EXISTING_SKIP,
                'missing_field_strategy' => RestoreOptions::STRATEGY_USE_DEFAULT,
                'clear_before_restore' => false,
                'dry_run' => false,
            ],
            $failures,
            $statistics,
            $warnings,
        );
        $this->em->flush();
        self::assertSame([], $failures, print_r($warnings, true));

        $value = $this->em->getConnection()->fetchOne('SELECT allow_email_linking FROM identity_provider WHERE slug = ?', [$slug]);
        self::assertNotFalse($value, 'provider not restored: ' . print_r($warnings, true));

        return (bool) $value;
    }
}
