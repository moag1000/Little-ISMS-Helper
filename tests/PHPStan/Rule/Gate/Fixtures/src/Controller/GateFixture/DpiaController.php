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

    /** T4 M1: the module flag only travels into the page. */
    #[Route('/flag', name: 'app_dpia_flag')]
    public function flagIntoRender(): Response // expect: gate11.route
    {
        return $this->render('x.html.twig', ['active' => $this->isModuleActive('privacy')]);
    }

    /** T4 M2: polarity inverted, the page renders with the module off. */
    #[Route('/inverted', name: 'app_dpia_inverted')]
    public function invertedPolarity(): Response // expect: gate11.route
    {
        if ($this->isModuleActive('privacy')) {
            return $this->redirectToRoute('app_dashboard');
        }

        return new Response();
    }

    /** T4 M3: off ends the action only together with another condition. */
    #[Route('/and', name: 'app_dpia_and')]
    public function combinedWithAnd(bool $x = false): Response // expect: gate11.route
    {
        if (!$this->isModuleActive('privacy') && $x) {
            return $this->redirectToRoute('app_dashboard');
        }

        return new Response();
    }

    #[Route('/negated', name: 'app_dpia_negated')]
    public function negatedEndsTheAction(): Response
    {
        if (!$this->isModuleActive('privacy')) {
            throw $this->createNotFoundException();
        }

        return new Response();
    }

    #[Route('/identical', name: 'app_dpia_identical')]
    public function comparedWithFalse(): Response
    {
        if (false === $this->isModuleActive('privacy') || !$this->isModuleActive('core')) {
            return $this->redirectToRoute('app_dashboard');
        }

        return new Response();
    }

    #[Route('/else', name: 'app_dpia_else')]
    public function elseRefuses(): Response
    {
        if ($this->isModuleActive('privacy')) {
            $page = new Response();
        } else {
            return $this->redirectToRoute('app_dashboard');
        }

        return $page;
    }

    #[Route('/ternary', name: 'app_dpia_ternary')]
    public function ternaryDecides(): Response
    {
        return $this->isModuleActive('privacy') ? new Response() : $this->redirectToRoute('app_dashboard');
    }

    #[Route('/coalesce', name: 'app_dpia_coalesce')]
    public function coalesceDecides(): Response
    {
        return $this->checkModuleActive('privacy') ?? new Response();
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
