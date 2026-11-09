<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Incident;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\Filter\SQLFilter;

/**
 * The one rule for incidents in holding views (W6): an organisation's own
 * incidents always count, another organisation's only when it released them
 * to the holding (`Incident::$visibleToHolding`, default true; a subsidiary
 * switches it off for a confidential case, e.g. an HR incident).
 *
 * Armed only while {@see \App\Service\Group\HoldingScope} reads across the
 * tree (read(), asTenant()), with `viewer_tenant_id` = the organisation of
 * the person looking. So every query inside a group view — the group report,
 * the organisation comparison's KPIs, the location map's group lens — sees
 * the same set without each caller remembering the flag. The voter path
 * (IncidentVoter) applies the same rule to single records.
 */
final class HoldingIncidentFilter extends SQLFilter
{
    public const string NAME = 'holding_incident_filter';

    public function addFilterConstraint(ClassMetadata $targetEntity, string $targetTableAlias): string
    {
        if ($targetEntity->getName() !== Incident::class) {
            return '';
        }
        $visible = sprintf(
            '%s.%s = %s',
            $targetTableAlias,
            $targetEntity->getColumnName('visibleToHolding'),
            (string) $this->getConnection()->getDatabasePlatform()->convertBooleans(true),
        );
        try {
            $viewer = trim($this->getParameter('viewer_tenant_id'), "'\"");
        } catch (\InvalidArgumentException) {
            return $visible;
        }
        if ($viewer === '' || !ctype_digit($viewer)) {
            return $visible;
        }

        return sprintf(
            '(%s.%s = %d OR %s)',
            $targetTableAlias,
            $targetEntity->getSingleAssociationJoinColumnName('tenant'),
            (int) $viewer,
            $visible,
        );
    }
}
