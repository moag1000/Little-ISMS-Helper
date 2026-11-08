<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\Deadline\Configured\ConfiguredDeadlineSource;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\ModuleConfigurationService;
use App\Util\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Fristtypen: every kind of duty with a deadline the tool reminds people of
 * (read-only). Types declared in config/deadline_types.yaml show their date
 * field and who acts; types written as classes show their profile.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
final class DeadlineTypeController extends AbstractController
{
    public function __construct(
        private readonly DeadlineSourceRegistry $sources,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    #[Route('/admin/deadline-types', name: 'admin_deadline_type_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $active = $this->modules->getActiveModules();
        $rows = array_map(fn (DeadlineSource $source): array => $this->row($source, $active), $this->sources->all());
        usort($rows, static fn (array $a, array $b): int => strcasecmp($a['label'], $b['label']));

        $origin = (string) $request->query->get('origin', '');
        if (in_array($origin, ['config', 'code'], true)) {
            $rows = array_values(array_filter($rows, static fn (array $r): bool => $r['origin'] === $origin));
        }

        return $this->render('admin/deadline_type/index.html.twig', [
            'page' => ListPage::fromRequest($rows, $request, static fn (array $r): array => [
                $r['label'], $r['slug'], $r['norm'], $r['module'], $r['due'],
            ]),
            'origin' => $origin,
            'configuredCount' => count(array_filter($this->sources->all(), static fn (DeadlineSource $s): bool => $s instanceof ConfiguredDeadlineSource)),
            'totalCount' => count($this->sources->all()),
        ]);
    }

    /**
     * @param list<string> $activeModules
     * @return array<string, mixed>
     */
    private function row(DeadlineSource $source, array $activeModules): array
    {
        $profile = $source->profile();
        $module = $source->requiredModule();
        $definition = $source instanceof ConfiguredDeadlineSource ? $source->definition : null;

        return [
            'slug' => $source->slug(),
            'label' => $source->label(),
            'bucket' => $profile->bucket,
            'norm' => $profile->normReference,
            'leadDays' => $profile->leadDays,
            'decision' => $profile->decision,
            'urgentHours' => $profile->urgentHours,
            'module' => $module !== null ? (string) ($this->modules->getModule($module)['name'] ?? $module) : null,
            'moduleActive' => $module === null || in_array($module, $activeModules, true),
            'origin' => $definition !== null ? 'config' : 'code',
            'entity' => $definition !== null ? substr((string) strrchr($definition->entity, '\\'), 1) : null,
            'due' => $definition?->due,
            'holders' => $definition !== null ? [...$definition->holders] : [],
            'deputies' => $definition !== null ? [...$definition->deputies] : [],
            'roles' => array_map(self::roleName(...), $definition !== null ? $definition->roles : []),
            'inform' => array_map(self::roleName(...), $definition !== null ? $definition->inform : []),
        ];
    }

    private static function roleName(string $role): string
    {
        return str_starts_with($role, 'ROLE_') ? str_replace('_', ' ', substr($role, 5)) : $role;
    }
}
