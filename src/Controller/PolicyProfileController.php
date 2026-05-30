<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\OrganizationSecurityProfile;
use App\Repository\OrganizationSecurityProfileRepository;
use App\Service\PolicyParameter\ParameterRegisterBuilder;
use App\Service\PolicyParameter\PolicyBaselineCatalog;
use App\Service\PolicyParameter\PolicyParameterCatalog;
use App\Service\PolicyParameter\PolicyProfileManager;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Organisation Security Profile — the user-facing entry to the policy-parameter
 * engine: pick an industry sector (pre-fills the profile from a baseline), see
 * the effective value of every parameter + the per-framework coverage ampel,
 * override values, and export the cross-framework parameter register.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/policy-profile')]
#[IsGranted('ROLE_MANAGER')]
class PolicyProfileController extends AbstractController
{
    public function __construct(
        private readonly OrganizationSecurityProfileRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly PolicyProfileManager $profileManager,
        private readonly PolicyParameterCatalog $catalog,
        private readonly PolicyBaselineCatalog $baselines,
        private readonly ParameterRegisterBuilder $registerBuilder,
    ) {
    }

    #[Route('', name: 'app_policy_profile_index', methods: ['GET'])]
    public function index(): Response
    {
        $profile = $this->loadOrCreate();
        $resolved = $this->profileManager->resolveAll($profile);

        return $this->render('policy_profile/index.html.twig', [
            'profile' => $profile,
            'sectors' => $this->baselines->all(),
            'definitions' => $this->catalog->all(),
            'resolved' => $resolved,
            'coverage' => $this->profileManager->coverage($profile),
        ]);
    }

    #[Route('/sector', name: 'app_policy_profile_apply_sector', methods: ['POST'])]
    public function applySector(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('policy_profile_sector', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $sector = (string) $request->request->get('sector', '');
        $profile = $this->loadOrCreate();

        if (\in_array($sector, $this->baselines->sectors(), true)) {
            $this->profileManager->applySector($profile, $sector);
            $this->entityManager->flush();
            $this->addFlash('success', 'Branchen-Baseline angewendet — Profil vorbelegt.');
        } else {
            $this->addFlash('error', 'Unbekannte Branche.');
        }

        return $this->redirectToRoute('app_policy_profile_index', ['_locale' => $request->getLocale()]);
    }

    #[Route('/save', name: 'app_policy_profile_save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('policy_profile_save', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $profile = $this->loadOrCreate();
        /** @var array<string, mixed> $values */
        $values = $request->request->all('param');
        foreach ($this->catalog->keys() as $key) {
            if (\array_key_exists($key, $values) && $values[$key] !== '') {
                $def = $this->catalog->get($key);
                $raw = $values[$key];
                $profile->setValue($key, $def->type === 'int' ? (int) $raw : (string) $raw);
            }
        }
        $this->entityManager->flush();
        $this->addFlash('success', 'Parameter gespeichert.');

        return $this->redirectToRoute('app_policy_profile_index', ['_locale' => $request->getLocale()]);
    }

    #[Route('/register', name: 'app_policy_profile_register', methods: ['GET'])]
    public function register(Request $request): Response
    {
        $profile = $this->loadOrCreate();
        $sector = $profile->getSectorKey();
        $frameworks = $sector !== null ? $this->baselines->get($sector)->frameworks : [];
        $rows = $this->registerBuilder->build($frameworks, $this->profileManager->resolveAll($profile));

        return $this->render('policy_profile/register.html.twig', [
            'profile' => $profile,
            'rows' => $rows,
            'frameworks' => $frameworks,
        ]);
    }

    private function loadOrCreate(): OrganizationSecurityProfile
    {
        $tenantId = $this->tenantContext->getCurrentTenantId();
        $profile = $tenantId !== null ? $this->repository->findForTenant($tenantId) : null;

        if ($profile === null) {
            $profile = (new OrganizationSecurityProfile())->setTenantId($tenantId);
            $this->entityManager->persist($profile);
            $this->entityManager->flush();
        }

        return $profile;
    }
}
