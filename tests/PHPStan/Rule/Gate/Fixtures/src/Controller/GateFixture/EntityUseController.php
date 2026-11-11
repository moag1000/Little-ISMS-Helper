<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use App\Attribute\RequiresModule;
use App\Entity\ComplianceFramework;
use App\Entity\DataBreach;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Gate 106 fixture: every `expect:` line is a mutation the rule must report (owners: DataBreach => privacy). */
final class EntityUseController extends AbstractController
{
    #[Route('/x/ungated', name: 'x_ungated')]
    public function ungated(): Response // expect: gate106.entityUse
    {
        return new Response((string) DataBreach::class);
    }

    #[Route('/x/attr', name: 'x_attr')]
    #[RequiresModule('privacy')]
    public function attribute(): Response
    {
        return new Response((string) DataBreach::class);
    }

    #[Route('/x/wrong', name: 'x_wrong')]
    #[RequiresModule('bcm')]
    public function otherModule(): Response // expect: gate106.entityUse
    {
        return new Response((string) DataBreach::class);
    }

    #[Route('/x/checked', name: 'x_checked')]
    public function checked(): Response
    {
        if (!$this->isModuleActive('privacy')) {
            return new Response('', 403);
        }

        return new Response((string) DataBreach::class);
    }

    #[Route('/x/marker', name: 'x_marker')]
    // @no-module-gate-required: the part about breaches hides itself
    public function marker(): Response
    {
        return new Response((string) DataBreach::class);
    }

    // A private helper that mentions the record counts for every action of the class, this one included.
    #[Route('/x/other-record', name: 'x_other')]
    public function noOwnedRecord(): Response // expect: gate106.entityUse
    {
        return new Response((string) ComplianceFramework::class);
    }

    #[Route('/x/helper', name: 'x_helper')]
    public function viaHelper(): Response // expect: gate106.entityUse
    {
        return $this->breach();
    }

    private function breach(): Response
    {
        return new Response(DataBreach::class);
    }

    private function isModuleActive(string $key): bool
    {
        return $key === 'never';
    }
}
