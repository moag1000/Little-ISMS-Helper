<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/** Gate 65 fixture: every `expect:` line is a mutation the rule must report. */
final class CsrfController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/x/plain', methods: ['POST'])]
    public function plain(): Response // expect: gate65.csrf
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/attribute', methods: ['POST'])]
    #[IsCsrfTokenValid('x_attribute')]
    public function attribute(): Response
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/decides', methods: ['POST'])]
    public function decides(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/unused', methods: ['POST'])]
    public function unusedResult(Request $request): Response // expect: gate65.csrf
    {
        $ok = $this->isCsrfTokenValid('x', (string) $request->request->get('_token'));
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/used', methods: ['POST'])]
    public function usedResult(Request $request): Response
    {
        $ok = $this->isCsrfTokenValid('x', (string) $request->request->get('_token'));
        if (!$ok) {
            return new Response('', 403);
        }
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/bare', methods: ['POST'])]
    public function bareCall(Request $request): Response // expect: gate65.csrf
    {
        $this->isCsrfTokenValid('x', (string) $request->request->get('_token'));
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/string', methods: 'POST')]
    public function verbAsString(): Response // expect: gate65.csrf
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/pipe', methods: 'GET|DELETE')]
    public function verbsWithPipe(): Response // expect: gate65.csrf
    {
        return new Response();
    }

    #[Route('/x/form-unhandled', methods: ['GET', 'POST'])]
    public function formNeverHandled(): Response // expect: gate65.csrf
    {
        $form = $this->createFormBuilder()->getForm();
        $this->em->flush();

        return new Response((string) $form->getName());
    }

    #[Route('/x/form', methods: ['GET', 'POST'])]
    public function formHandled(Request $request): Response
    {
        $form = $this->createFormBuilder()->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
        }

        return new Response();
    }

    /** T3: the attribute's name in a docblock is no attribute: IsCsrfTokenValid */
    #[Route('/x/docblock', methods: ['POST'])]
    public function nameInDocblock(): Response // expect: gate65.csrf
    {
        // TODO #[IsCsrfTokenValid('x')]
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/constant', methods: [Request::METHOD_POST])]
    public function verbAsConstant(): Response // expect: gate65.csrf
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/form-off', methods: ['POST'])]
    public function formWithoutCsrf(Request $request): Response // expect: gate65.csrf
    {
        $form = $this->createFormBuilder(null, ['csrf_protection' => false])->getForm();
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            $this->em->flush();
        }

        return new Response();
    }

    #[Route('/x/log-only', methods: ['POST'])]
    public function failureOnlyLogged(Request $request): Response // expect: gate65.csrf
    {
        if (!$this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            $this->logger->info('bad token');
        }
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/var-logged', methods: ['POST'])]
    public function resultOnlyLogged(Request $request): Response // expect: gate65.csrf
    {
        $ok = $this->isCsrfTokenValid('x', (string) $request->request->get('_token'));
        $this->logger->info('token', ['ok' => $ok]);
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/or-throw', methods: ['POST'])]
    public function orThrow(Request $request): Response
    {
        $this->isCsrfTokenValid('x', (string) $request->request->get('_token')) || throw $this->createAccessDeniedException();
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/positive', methods: ['POST'])]
    public function positiveGuard(Request $request): Response
    {
        if ($this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            $this->em->flush();
        }

        return new Response();
    }

    #[Route('/x/helper', methods: ['POST'])]
    public function viaHelper(Request $request): Response
    {
        $this->guard($request);
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/bulk', methods: ['POST'])]
    public function bulk(Request $request): Response
    {
        return $this->handleBulkDelete($request);
    }

    // @csrf-exempt: computes a preview, writes nothing
    #[Route('/x/exempt', methods: ['POST'])]
    public function exempt(): Response
    {
        return new Response();
    }

    // @csrf-exempt:
    #[Route('/x/exempt-without-reason', methods: ['POST'])]
    public function exemptWithoutReason(): Response // expect: gate65.csrf
    {
        return new Response();
    }

    #[Route('/x/get-writes', name: 'x_get_writes')]
    public function getWrites(): Response
    {
        $this->em->flush(); // expect: gate65.getWrites

        return new Response();
    }

    #[Route('/x/get-manager', methods: ['GET'])]
    public function getWritesThroughRegistry(): Response
    {
        $this->doctrine->getManager()->flush(); // expect: gate65.getWrites

        return new Response();
    }

    #[Route('/x/get-param', methods: ['GET'])]
    public function getWritesThroughParameter(EntityManagerInterface $manager): Response
    {
        $manager->persist(new \stdClass()); // expect: gate65.getWrites

        return new Response();
    }

    // @get-write-ok: stores the last visit of the person, nothing a link could abuse
    #[Route('/x/get-ok', methods: ['GET'])]
    public function getWriteReviewed(): Response
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/get-session', methods: ['GET'])]
    public function getSessionRemove(Request $request): Response
    {
        $request->getSession()->remove('x');

        return new Response();
    }

    #[Route('/x/{id}/delete', methods: ['POST'])]
    #[IsCsrfTokenValid('x_delete_{id}')] // expect: gate65.literalId
    public function literalId(): Response
    {
        return new Response();
    }

    private function guard(Request $request): void
    {
        if (!$this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }
    }

    private function handleBulkDelete(Request $request): Response
    {
        return new Response((string) $request->getMethod());
    }
}
