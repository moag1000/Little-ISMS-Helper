<?php

declare(strict_types=1);

namespace App\Controller\Component;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Policy-Wizard W7-E — "Policies you own" dashboard widget.
 *
 * Lists Documents the current user is responsible for, either as
 *   - {@see Document::getUploadedBy()} (direct ownership), or
 *   - function-owner per the source PolicyTemplate's affectedFunctions
 *     metadata (the W2 Risk-Owner slot — the user's `roles` are matched
 *     against that template's affectedFunctions list).
 *
 * The controller is intended to be embedded into the dashboard via
 *   {{ render(controller('App\\Controller\\Component\\PoliciesYouOwnWidgetController::widget')) }}
 *
 * Closes the Risk-Owner review's "What's missing #5" gap from
 * `docs/plans/policy-wizard/07-risk-owner-review.md` (lines 226-230).
 */
final class PoliciesYouOwnWidgetController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Render the widget. Returns an empty (200) response when no user is
     * authenticated, when no tenant is bound, or when the user owns no
     * policies — the empty-state markup is rendered by the template so
     * the dashboard layout stays predictable.
     */
    #[IsGranted('ROLE_USER')]
    public function widget(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $tenant = $user->getTenant();
        if (!$tenant instanceof Tenant) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $documents = $this->ownedDocuments($tenant, $user);

        return $this->render('_components/_policies_you_own_widget.html.twig', [
            'documents' => $documents,
        ]);
    }

    /**
     * Resolve the documents the user owns. Public so tests can verify
     * the resolution logic without exercising the rendering pipeline.
     *
     * @return list<Document>
     */
    public function ownedDocuments(Tenant $tenant, User $user): array
    {
        $userRoles = $user->getRoles();

        // Walk all non-archived documents in the tenant. Matching either
        // by uploadedBy=user OR by intersection between the user's roles
        // and the source template's affectedFunctions.
        $candidates = $this->entityManager->getRepository(Document::class)
            ->createQueryBuilder('d')
            ->leftJoin('d.generatedFromTemplate', 't')
            ->addSelect('t')
            ->where('d.tenant = :tenant')
            ->andWhere('d.isArchived = false')
            ->setParameter('tenant', $tenant)
            ->orderBy('d.updatedAt', 'DESC')
            ->addOrderBy('d.uploadedAt', 'DESC')
            ->getQuery()
            ->getResult();

        $owned = [];
        foreach ($candidates as $document) {
            if (!$document instanceof Document) {
                continue;
            }
            if ($this->isOwnedBy($document, $user, $userRoles)) {
                $owned[] = $document;
            }
        }

        return $owned;
    }

    /**
     * @param list<string> $userRoles
     */
    private function isOwnedBy(Document $document, User $user, array $userRoles): bool
    {
        $uploadedBy = $document->getUploadedBy();
        if ($uploadedBy instanceof User
            && $uploadedBy->getId() !== null
            && $uploadedBy->getId() === $user->getId()
        ) {
            return true;
        }

        $template = $document->getGeneratedFromTemplate();
        if ($template === null) {
            return false;
        }

        $affectedFunctions = $template->getAffectedFunctions();
        if ($affectedFunctions === null || $affectedFunctions === []) {
            return false;
        }

        foreach ($affectedFunctions as $function) {
            if (!is_string($function) || $function === '') {
                continue;
            }
            // Two match patterns: exact role match (e.g. ROLE_HR) and
            // function-name suffix match against any ROLE_* role
            // (e.g. affectedFunction "HR" matches ROLE_HR).
            if (in_array($function, $userRoles, true)) {
                return true;
            }
            $needle = 'ROLE_' . strtoupper($function);
            if (in_array($needle, $userRoles, true)) {
                return true;
            }
        }

        return false;
    }
}
