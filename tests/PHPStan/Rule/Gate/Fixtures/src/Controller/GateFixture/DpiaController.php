<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use App\Attribute\RequiresModule;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Gate 11 fixture (controller pass): every `expect:` line is a mutation the rule must report. */
#[Route('/dpia')]
final class DpiaController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('', name: 'app_dpia_index')]
    public function index(): Response // expect: gate11.route
    {
        return new Response();
    }

    #[Route('/attr', name: 'app_dpia_attr')]
    #[RequiresModule('privacy')]
    public function attribute(): Response
    {
        return new Response();
    }

    // #[RequiresModule('privacy')]
    #[Route('/commented', name: 'app_dpia_commented')]
    public function commentedAttribute(): Response // expect: gate11.route
    {
        return new Response();
    }

    #[Route('/wrong', name: 'app_dpia_wrong')]
    #[RequiresModule('bcm')]
    public function otherModule(): Response // expect: gate11.route
    {
        return new Response();
    }

    #[Route('/used', name: 'app_dpia_used')]
    public function checkUsed(): Response
    {
        if ($r = $this->checkModuleActive('privacy')) {
            return $r;
        }

        return new Response();
    }

    #[Route('/bare', name: 'app_dpia_bare')]
    public function checkDropped(): Response // expect: gate11.route
    {
        $this->checkModuleActive('privacy');

        return new Response();
    }

    #[Route('/false', name: 'app_dpia_false')]
    public function checkBehindFalse(): Response // expect: gate11.route
    {
        if (false && $r = $this->checkModuleActive('privacy')) {
            return $r;
        }

        return new Response();
    }

    #[Route('/logged', name: 'app_dpia_logged')]
    public function checkOnlyLogged(): Response // expect: gate11.route
    {
        $active = $this->isModuleActive('privacy');
        $this->logger->info('privacy', ['active' => $active]);

        return new Response();
    }

    // @no-module-gate-required: redirect to app_dpia_attr, which is gated itself
    #[Route('/go', name: 'app_dpia_go')]
    public function reviewed(): Response
    {
        return new Response();
    }

    private function checkModuleActive(string $module): ?Response
    {
        return $module === '' ? new Response() : null;
    }

    private function isModuleActive(string $module): bool
    {
        return $module !== '';
    }
}
