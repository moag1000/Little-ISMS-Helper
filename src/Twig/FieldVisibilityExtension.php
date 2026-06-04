<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\FieldVisibilityResolver;
use Twig\Attribute\AsTwigFunction;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * F7 Twig helper — exposes can_view_field('EntityClass', 'fieldKey').
 *
 * Usage in templates:
 *   {% if can_view_field('Risk', 'owner') %}
 *       {{ risk.effectiveRiskOwner }}
 *   {% else %}
 *       <span class="text-muted fst-italic">{{ 'field.access.restricted'|trans({}, 'risk') }}</span>
 *   {% endif %}
 *
 * The A.8.15 deny-log is handled inside FieldVisibilityResolver — logged
 * at most once per (entityClass, fieldKey) per request, so repeated calls
 * from list templates do not flood the audit log.
 *
 * Default behaviour (field not in MAP): returns true — additive,
 * non-breaking. Internal roles continue to see all fields unchanged.
 */
final class FieldVisibilityExtension
{
    public function __construct(
        private readonly FieldVisibilityResolver $resolver,
        private readonly Security $security,
    ) {
    }

    /**
     * Returns true when the currently authenticated user may view the field.
     *
     * @param string $entityClass Short class name, e.g. 'Risk'
     * @param string $fieldKey    Field key matching FieldVisibilityResolver MAP, e.g. 'owner'
     */
    #[AsTwigFunction('can_view_field')]
    public function canViewField(string $entityClass, string $fieldKey): bool
    {
        return $this->resolver->canViewField(
            $entityClass,
            $fieldKey,
            $this->security->getUser(),
        );
    }
}
