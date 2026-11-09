<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ComplianceMapping;
use App\Entity\MappingGapItem;
use App\Security\Voter\GlobalCatalogueVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The screens that write the global compliance mappings, in one list: on an instance with
 * several organisations they answer 403 unless {@see GlobalCatalogueVoter} grants WRITE
 * (super admin). Routes with an `{id}` of a mapping pass the mapping as subject, so an
 * organisation keeps editing the mappings of its own uploaded requirements.
 *
 * Whatever this list misses, the Doctrine backstop GlobalMappingWriteGuard refuses on flush;
 * the list is here so that nobody fills a form first and then fails.
 */
final class GlobalCatalogueWriteSubscriber implements EventSubscriberInterface
{
    /** Routes whose `{id}` is a ComplianceMapping. */
    private const array MAPPING_ROUTES = [
        'app_compliance_mapping_edit',
        'app_compliance_mapping_delete',
        'app_compliance_mapping_analyze',
        'app_mapping_quality_review_update',
        'app_mapping_quality_analyze',
    ];

    /** Routes that create or change global mappings in bulk. */
    private const array CATALOGUE_ROUTES = [
        'admin_compliance_import_upload',     // CSV / BSI XML mapping import wizard
        'admin_compliance_import_preview',
        'admin_compliance_import_commit',
        'app_compliance_mapping_import',      // consultant CSV import
        'app_compliance_mapping_gspp_supplement',
        'app_compliance_create_mappings',
        'app_compliance_create_comparison_mappings',
        'app_compliance_framework_migrate',
        'app_mapping_quality_batch_analyze',
        'app_compliance_wizard_import_mapping_library',
        // Seed commands run as their own `bin/console` process, past the Doctrine backstop.
        'app_compliance_mapping_seeds_apply',
        // Loader fixer adds requirements to the shared framework catalogue.
        'admin_loader_fixer_run_one',
        'admin_loader_fixer_run_all',
    ];

    /** Routes whose `{id}` is a MappingGapItem: the gap belongs to its mapping. */
    private const array GAP_ROUTES = [
        'app_mapping_quality_gap_update',
    ];

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::CONTROLLER => ['onController', 0]];
    }

    /** @return list<string> every route this subscriber guards (tests, docs). */
    public static function guardedRoutes(): array
    {
        return [...self::MAPPING_ROUTES, ...self::CATALOGUE_ROUTES, ...self::GAP_ROUTES];
    }

    public function onController(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route', '');
        if (in_array($route, self::CATALOGUE_ROUTES, true)) {
            $subject = null;
        } elseif (in_array($route, self::MAPPING_ROUTES, true)) {
            $id = $request->attributes->get('id');
            $subject = is_numeric($id) ? $this->entityManager->find(ComplianceMapping::class, (int) $id) : null;
            if ($subject === null) {
                return; // the controller answers 404
            }
        } elseif (in_array($route, self::GAP_ROUTES, true)) {
            $id = $request->attributes->get('id');
            $gap = is_numeric($id) ? $this->entityManager->find(MappingGapItem::class, (int) $id) : null;
            $subject = $gap?->getMapping();
            if ($subject === null) {
                return; // the controller answers 404
            }
        } else {
            return;
        }

        if (!$this->security->isGranted(GlobalCatalogueVoter::WRITE, $subject)) {
            throw new AccessDeniedException(
                'Global compliance mappings are shared by all organisations; only the super admin changes them.',
            );
        }
    }
}
