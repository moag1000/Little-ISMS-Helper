<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\User;
use App\Service\ComplianceLoaderFixerService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin UI for re-running framework requirement loaders idempotently.
 * Picks up requirements that were added to loader code after the initial seed,
 * without touching existing records.
 */
#[Route('/admin/loader-fixer', name: 'admin_loader_fixer_')]
#[IsGranted('ROLE_ADMIN')]
final class LoaderFixerController extends AbstractController
{
    public function __construct(
        private readonly ComplianceLoaderFixerService $fixer,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/loader_fixer/index.html.twig', [
            'rows' => $this->fixer->getStatus(),
        ]);
    }

    #[Route('/run/{code}', name: 'run_one', methods: ['POST'], requirements: ['code' => '[A-Z0-9_\-]+'])]
    public function runOne(string $code, Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('loader_fixer_run_' . $code, $token))) {
            $this->addFlash('danger', 'loader_fixer.flash.invalid_csrf');
            return $this->redirectToRoute('admin_loader_fixer_index');
        }

        $result = $this->fixer->fixOne($code, $this->actorDescription());

        $this->addFlash(
            $result['success'] ? ($result['added'] > 0 ? 'success' : 'info') : 'danger',
            $this->t('loader_fixer.flash.single', [
                '%code%' => $code,
                '%added%' => $result['added'],
                '%after%' => $result['after'],
            ]),
        );

        return $this->render('admin/loader_fixer/result.html.twig', [
            'single' => $result,
            'results' => [$code => $result],
            'total_added' => $result['added'],
        ]);
    }

    #[Route('/run-all', name: 'run_all', methods: ['POST'])]
    public function runAll(Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('loader_fixer_run_all', $token))) {
            $this->addFlash('danger', 'loader_fixer.flash.invalid_csrf');
            return $this->redirectToRoute('admin_loader_fixer_index');
        }

        $results = $this->fixer->fixAll($this->actorDescription());
        $totalAdded = array_sum(array_map(fn(array $r): int => $r['added'], $results));

        $this->addFlash(
            $totalAdded > 0 ? 'success' : 'info',
            $this->t('loader_fixer.flash.bulk', ['%total%' => $totalAdded, '%frameworks%' => count($results)]),
        );

        return $this->render('admin/loader_fixer/result.html.twig', [
            'results' => $results,
            'total_added' => $totalAdded,
            'single' => null,
        ]);
    }

    private function actorDescription(): string
    {
        $user = $this->getUser();
        if ($user instanceof User) {
            return sprintf('user=%d email=%s', $user->getId() ?? 0, $user->getEmail() ?? '?');
        }
        return 'anonymous';
    }

    /**
     * @param array<string, string|int> $params
     */
    private function t(string $key, array $params = []): string
    {
        $normalised = [];
        foreach ($params as $k => $v) {
            $normalised[$k] = (string) $v;
        }
        return $this->translator->trans($key, $normalised, 'loader_fixer');
    }
}
