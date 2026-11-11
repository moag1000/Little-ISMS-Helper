<?php

declare(strict_types=1);

namespace App\Service\GateFixture;

use App\Entity\Tenant;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;

/** Gate 97 fixture (T4: B11–B14 of the counter-check). Every `expect:` line is a mutation the rule must report. */
final class TenantPins
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly LoggerInterface $logger)
    {
    }

    public function bare(Tenant $t): void
    {
        $this->tenantContext->setCurrentTenant($t); // expect: gate97.pinNotRestored
    }

    public function restored(Tenant $t, ?Tenant $previous): void
    {
        try {
            $this->tenantContext->setCurrentTenant($t);
        } finally {
            $this->tenantContext->setCurrentTenant($previous);
        }
    }

    public function runAs(Tenant $t): void
    {
        $this->tenantContext->runAs($t, static fn (): int => 1);
    }

    /** B11: the finally only reads the context. */
    public function finallyOnlyReads(Tenant $t): void
    {
        try {
            $this->tenantContext->setCurrentTenant($t); // expect: gate97.pinNotRestored
        } finally {
            $this->logger->info('x', ['t' => $this->tenantContext->getCurrentTenantId()]);
        }
    }

    /** B12: a line break after the arrow. */
    public function lineBreak(Tenant $t): void
    {
        $this->tenantContext-> // expect: gate97.pinNotRestored
            setCurrentTenant($t);
    }

    /** B13: the name as a string. */
    public function byName(Tenant $t): void
    {
        call_user_func([$this->tenantContext, 'setCurrentTenant'], $t); // expect: gate97.pinNotRestored
        $this->tenantContext->{'setCurrent' . 'Tenant'}($t); // expect: gate97.pinNotRestored
        $m = 'setNoOrganisation'; // expect: gate97.pinNotRestored
        $this->tenantContext->$m();
        [$this->tenantContext, 'setNoOrganisation'](); // expect: gate97.pinNotRestored
    }

    /** B14: the pin runs in a closure that may run after the finally. */
    public function inClosure(Tenant $t, ?Tenant $previous): \Closure
    {
        try {
            $later = fn () => $this->tenantContext->setCurrentTenant($t); // expect: gate97.pinNotRestored
        } finally {
            $this->tenantContext->setCurrentTenant($previous);
        }

        return $later;
    }

    public function restoreCallable(Tenant $t): void
    {
        $restore = $this->tenantContext->capture();
        try {
            $this->tenantContext->setNoOrganisation();
        } finally {
            $restore();
        }
    }

    public function reviewed(Tenant $t): void
    {
        // @tenant-pin-ok: fixture of a reviewed pin
        $this->tenantContext->setCurrentTenant($t);
    }
}
