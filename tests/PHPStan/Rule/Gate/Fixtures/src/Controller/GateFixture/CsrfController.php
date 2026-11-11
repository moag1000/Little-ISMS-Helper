<?php

declare(strict_types=1);

namespace App\Controller\GateFixture;

use App\Controller\Trait\BulkActionTrait;
use App\Lifecycle\LifecycleService;
use App\Service\BulkDeleteService;
use Doctrine\DBAL\Connection;
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
        private readonly Connection $connection,
        private readonly LifecycleService $lifecycle,
    ) {
    }

    /** T4 C1: the attribute checks only GET, the POST goes through. */
    #[Route('/x/attr-get', methods: ['POST'])]
    #[IsCsrfTokenValid('x', methods: ['GET'])]
    public function attributeOnlyForGet(): Response // expect: gate65.attributeMethods
    {
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/attr-post', methods: ['POST', 'DELETE'])]
    #[IsCsrfTokenValid('x', methods: ['POST', 'DELETE'])]
    public function attributeListsTheVerbs(): Response
    {
        $this->em->flush();

        return new Response();
    }

    /** T4 C3: the helper returns the check, the action drops the result. */
    #[Route('/x/helper-ignored', methods: ['POST'])]
    public function helperResultIgnored(Request $request): Response // expect: gate65.csrf
    {
        $this->tokenOk($request);
        $this->em->flush();

        return new Response();
    }

    #[Route('/x/helper-used', methods: ['POST'])]
    public function helperResultUsed(Request $request): Response
    {
        if (!$this->tokenOk($request)) {
            throw $this->createAccessDeniedException();
        }
        $this->em->flush();

        return new Response();
    }

    /** T4 C3: a helper whose failure branch only returns from itself. */
    #[Route('/x/helper-returns', methods: ['POST'])]
    public function helperOnlyReturns(Request $request): Response // expect: gate65.csrf
    {
        $this->softGuard($request);
        $this->em->flush();

        return new Response();
    }

    /** T4 C4: the positive guard holds nothing, the write sits after it. */
    #[Route('/x/partial-guard', methods: ['POST'])]
    public function partialGuard(Request $request): Response // expect: gate65.csrf
    {
        $done = false;
        if ($this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            $done = true;
        }
        $this->em->flush();

        return new Response($done ? 'y' : 'n');
    }

    /** T4 C5: the failure branch logs, the `else` returns. */
    #[Route('/x/else-returns', methods: ['POST'])]
    public function elseReturns(Request $request): Response // expect: gate65.csrf
    {
        if (!$this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            $this->logger->info('bad token');
        } else {
            return new Response('early');
        }
        $this->em->flush();

        return new Response();
    }

    /** T4 C9: GET writes through a local alias, DBAL, DQL and the lifecycle. */
    #[Route('/x/get-alias', methods: ['GET'])]
    public function getWritesThroughAlias(): Response
    {
        $em = $this->doctrine->getManager();
        $em->flush(); // expect: gate65.getWrites

        return new Response();
    }

    #[Route('/x/get-dbal', methods: ['GET'])]
    public function getWritesThroughDbal(): Response
    {
        $this->connection->executeStatement('DELETE FROM asset'); // expect: gate65.getWrites

        return new Response();
    }

    #[Route('/x/get-dql', methods: ['GET'])]
    public function getWritesThroughDql(): Response
    {
        $this->em->createQuery('DELETE FROM App\Entity\Asset a')->execute(); // expect: gate65.getWrites

        return new Response();
    }

    #[Route('/x/get-select', methods: ['GET'])]
    public function getReadsThroughDql(): Response
    {
        $this->em->createQuery('SELECT a FROM App\Entity\Asset a')->getResult();

        return new Response();
    }

    #[Route('/x/get-lifecycle', methods: ['GET'])]
    public function getTransitions(): Response
    {
        $this->lifecycle->transition(new \stdClass(), 'asset_lifecycle', 'retire'); // expect: gate65.getWrites

        return new Response();
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

    /** T4 C2: a look-alike handleBulkDelete() of the class itself checks no token. */
    #[Route('/x/bulk', methods: ['POST'])]
    public function bulk(Request $request): Response // expect: gate65.csrf
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

    private function tokenOk(Request $request): bool
    {
        return $this->isCsrfTokenValid('x', (string) $request->request->get('_token'));
    }

    private function softGuard(Request $request): ?Response
    {
        if (!$this->isCsrfTokenValid('x', (string) $request->request->get('_token'))) {
            return new Response('', 403);
        }

        return null;
    }

    private function handleBulkDelete(Request $request): Response
    {
        return new Response((string) $request->getMethod());
    }
}

/** T4 C2 control: BulkActionTrait's handleBulkDelete() checks the shared bulk token. */
final class BulkCsrfController extends AbstractController
{
    use BulkActionTrait;

    #[Route('/x/trait-bulk', methods: ['POST'])]
    public function bulk(Request $request, BulkDeleteService $service): Response
    {
        return $this->handleBulkDelete($request, $service, 'asset', null, static fn (int $id): ?object => null);
    }
}

/** T4 C7: the verbs come from the class route, the action route names none. */
#[Route('/c', methods: ['POST'])]
final class ClassVerbController extends AbstractController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    #[Route('/x', name: 'c_x')]
    public function write(): Response // expect: gate65.csrf
    {
        $this->em->flush();

        return new Response();
    }
}

/** T4 C6: a route on an abstract parent is served for every child and was checked by nobody. */
abstract class AbstractRouteController extends AbstractController
{
    #[Route('/abstract/delete', methods: ['POST'])] // expect: gate65.routeOutsideController
    public function delete(): Response
    {
        return new Response();
    }
}
