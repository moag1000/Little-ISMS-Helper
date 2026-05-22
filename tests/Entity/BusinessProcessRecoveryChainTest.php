<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\BusinessProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Junior-ISB-Audit-2026-05-22 M-01: ISO 22301 Cl. 8.2.2 / 8.3.2 —
 * server-side enforcement of the BIA recovery chain RPO ≤ RTO ≤ MTPD.
 *
 * Previously the chain was a tooltip-only hint; the server happily accepted
 * impossible orderings (e.g. mtpd=2h, rto=8h). This test pins the new
 * Assert\Callback validator on BusinessProcess::validateRecoveryChain.
 */
final class BusinessProcessRecoveryChainTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    #[Test]
    public function validChainProducesNoViolation(): void
    {
        $process = $this->makeProcess(rpo: 1, rto: 4, mtpd: 8);

        $violations = $this->filterRecoveryChainViolations(
            $this->validator->validate($process)
        );

        $this->assertCount(
            0,
            $violations,
            'rpo=1 ≤ rto=4 ≤ mtpd=8 must not raise a recovery-chain violation'
        );
    }

    #[Test]
    public function rpoGreaterThanRtoProducesViolationOnRpoPath(): void
    {
        $process = $this->makeProcess(rpo: 4, rto: 2, mtpd: 8);

        $violations = $this->filterRecoveryChainViolations(
            $this->validator->validate($process)
        );

        $this->assertCount(
            1,
            $violations,
            'rpo=4 > rto=2 must raise exactly one recovery-chain violation'
        );
        $this->assertSame('rpo', $violations[0]['path']);
        $this->assertSame('business_process.validator.rpo_greater_than_rto', $violations[0]['message']);
    }

    #[Test]
    public function rtoGreaterThanMtpdProducesViolationOnMtpdPath(): void
    {
        $process = $this->makeProcess(rpo: 1, rto: 8, mtpd: 4);

        $violations = $this->filterRecoveryChainViolations(
            $this->validator->validate($process)
        );

        $this->assertCount(
            1,
            $violations,
            'rto=8 > mtpd=4 must raise exactly one recovery-chain violation'
        );
        $this->assertSame('mtpd', $violations[0]['path']);
        $this->assertSame('business_process.validator.rto_greater_than_mtpd', $violations[0]['message']);
    }

    #[Test]
    public function allNullValuesProduceNoViolation(): void
    {
        // Guards must short-circuit when fields are not yet populated (e.g. partial
        // form submission before RTO/RPO/MTPD have been filled in).
        $process = new BusinessProcess();

        $violations = $this->filterRecoveryChainViolations(
            $this->validator->validate($process)
        );

        $this->assertCount(
            0,
            $violations,
            'null guards must prevent spurious recovery-chain violations on empty entity'
        );
    }

    private function makeProcess(int $rpo, int $rto, int $mtpd): BusinessProcess
    {
        $process = new BusinessProcess();
        $process->setRpo($rpo);
        $process->setRto($rto);
        $process->setMtpd($mtpd);

        return $process;
    }

    /**
     * Isolate only the two recovery-chain message keys so unrelated NotBlank /
     * Range violations from other fields cannot pollute the assertions.
     *
     * @return list<array{path: string, message: string}>
     */
    private function filterRecoveryChainViolations(iterable $violations): array
    {
        $allowed = [
            'business_process.validator.rpo_greater_than_rto',
            'business_process.validator.rto_greater_than_mtpd',
        ];

        $out = [];
        foreach ($violations as $violation) {
            $message = $violation->getMessageTemplate();
            if (in_array($message, $allowed, true)) {
                $out[] = [
                    'path'    => $violation->getPropertyPath(),
                    'message' => $message,
                ];
            }
        }

        return $out;
    }
}
