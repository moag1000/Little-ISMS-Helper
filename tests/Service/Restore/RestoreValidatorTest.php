<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\Tenant;
use App\Exception\Io\IoException;
use App\Service\Restore\RestoreValidator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[AllowMockObjectsWithoutExpectations]
final class RestoreValidatorTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $logger;
    private RestoreValidator $validator;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->logger        = $this->createMock(LoggerInterface::class);
        $this->validator     = new RestoreValidator($this->entityManager, $this->logger);
    }

    // ────────────────────────────────────────────────────────────────────────
    // validateBackup — structural checks
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_backup_returns_valid_for_minimal_well_formed_backup(): void
    {
        $backup = [
            'metadata' => ['version' => '2.0'],
            'data'     => [],
        ];

        $result = $this->validator->validateBackup(
            $backup,
            null,
            fn() => [],
            fn() => null,
        );

        self::assertTrue($result['valid']);
        self::assertSame([], $result['errors']);
    }

    #[Test]
    public function validate_backup_fails_when_metadata_missing(): void
    {
        $backup = ['data' => []];

        $result = $this->validator->validateBackup($backup, null, fn() => [], fn() => null);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('Missing metadata', $result['errors'][0]);
    }

    #[Test]
    public function validate_backup_fails_when_data_section_missing(): void
    {
        $backup = ['metadata' => ['version' => '2.0']];

        $result = $this->validator->validateBackup($backup, null, fn() => [], fn() => null);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('data section', $result['errors'][0]);
    }

    #[Test]
    public function validate_backup_adds_warning_for_unsupported_version(): void
    {
        $backup = [
            'metadata' => ['version' => '99.0'],
            'data'     => [],
        ];

        $result = $this->validator->validateBackup($backup, null, fn() => [], fn() => null);

        self::assertFalse($result['valid']);
        self::assertStringContainsString('Unsupported backup version', $result['errors'][0]);
    }

    #[Test]
    public function validate_backup_adds_warning_for_unknown_entity_class(): void
    {
        $backup = [
            'metadata' => ['version' => '2.0'],
            'data'     => ['NonExistentEntity123' => []],
        ];

        $result = $this->validator->validateBackup($backup, null, fn() => [], fn() => null);

        self::assertTrue($result['valid']);
        $warningText = implode(' ', $result['warnings']);
        self::assertStringContainsString('NonExistentEntity123', $warningText);
    }

    // ────────────────────────────────────────────────────────────────────────
    // validateBackup — tenant-scope guard
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_backup_throws_when_backup_has_no_tenant_scope(): void
    {
        $caller = new Tenant();

        $backup = [
            'metadata' => ['version' => '2.0'],
            'data'     => [],
        ];

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('no recorded tenant_scope');

        $this->validator->validateBackup(
            $backup,
            $caller,
            fn($t) => [1],
            fn() => null,
        );
    }

    #[Test]
    public function validate_backup_throws_when_scope_does_not_overlap(): void
    {
        $caller = new Tenant();

        $backup = [
            'metadata' => ['version' => '2.0', 'tenant_scope' => [99]],
            'data'     => [],
        ];

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('does not overlap');

        $this->validator->validateBackup(
            $backup,
            $caller,
            fn($t) => [1, 2],  // caller has IDs 1 and 2
            fn() => null,
        );
    }

    #[Test]
    public function validate_backup_succeeds_when_scope_overlaps(): void
    {
        $caller = new Tenant();

        $backup = [
            'metadata' => ['version' => '2.0', 'tenant_scope' => [1, 2]],
            'data'     => [],
        ];

        $result = $this->validator->validateBackup(
            $backup,
            $caller,
            fn($t) => [1, 3],  // 1 overlaps
            fn() => null,
        );

        self::assertTrue($result['valid']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // verifyIntegrity
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function verify_integrity_passes_for_matching_hash(): void
    {
        $data   = ['key' => 'value'];
        $hash   = hash('sha256', (string) json_encode($data));
        $backup = ['metadata' => ['sha256' => $hash], 'data' => $data];

        $result = $this->validator->verifyIntegrity($backup);
        self::assertNull($result);
    }

    #[Test]
    public function verify_integrity_throws_on_hash_mismatch(): void
    {
        $backup = [
            'metadata' => ['sha256' => 'deadbeef0000000000000000000000000000000000000000000000000000dead'],
            'data'     => ['key' => 'tampered'],
        ];

        $this->expectException(IoException::class);
        $this->expectExceptionMessage('integrity check failed');

        $this->validator->verifyIntegrity($backup);
    }

    #[Test]
    public function verify_integrity_returns_warning_for_legacy_backup_without_hash(): void
    {
        $backup = ['metadata' => [], 'data' => ['key' => 'value']];

        $warning = $this->validator->verifyIntegrity($backup);

        self::assertNotNull($warning);
        self::assertStringContainsString('Legacy backup', $warning);
    }
}
