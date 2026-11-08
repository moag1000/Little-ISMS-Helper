<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * The `record_completeness` compliance-wizard check: do the organisation's
 * records carry what the article asks for? One generic rule for duties that
 * are a set of fields on records the tool already holds, e.g.
 *
 *  - DORA Art. 17 (2): incidents are classified (event / incident);
 *  - DORA Art. 18 (1): incidents carry the classification criteria;
 *  - DORA Art. 14 (1): BC plans carry a communication plan;
 *  - DORA Art. 30 (2): ICT supplier contracts carry the key provisions.
 *
 * Config (from the category provider, never from input): `entity` (class),
 * `where` (field => value), `since` ({field, months}: only recent records),
 * `fields` (all must be filled: text not empty, flag true, other not null).
 * Score = share of complete records. No record is "no data".
 */
final class RecordCompletenessCheck
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $check
     * @return array{score: float, details: array<string, mixed>, gap: array<string, mixed>|null}
     */
    public function check(array $check, ?Tenant $tenant, ?DateTimeImmutable $today = null): array
    {
        $class = (string) ($check['entity'] ?? '');
        $fields = array_values(array_filter((array) ($check['fields'] ?? []), 'is_string'));
        $details = ['type' => 'record_completeness', 'entity' => $class, 'fields' => $fields];
        if (!$tenant instanceof Tenant || !class_exists($class) || $fields === []) {
            return NoDataResult::build('wizard.gap.no_records', (string) ($check['route'] ?? 'app_dashboard'), $check, $details + ['total' => 0]);
        }

        $total = $this->count($this->base($class, $check, $tenant, $today ?? new DateTimeImmutable('today')));
        if ($total === 0) {
            return NoDataResult::build('wizard.gap.no_records', (string) ($check['route'] ?? 'app_dashboard'), $check, $details + ['total' => 0]);
        }

        $complete = $this->base($class, $check, $tenant, $today ?? new DateTimeImmutable('today'));
        $metadata = $this->entityManager->getClassMetadata($class);
        foreach ($fields as $field) {
            $type = $metadata->hasField($field) ? $metadata->getTypeOfField($field) : null;
            $complete->andWhere(match ($type) {
                Types::BOOLEAN => sprintf('r.%s = true', $field),
                Types::STRING, Types::TEXT => sprintf("r.%s IS NOT NULL AND r.%s <> ''", $field, $field),
                default => sprintf('r.%s IS NOT NULL', $field),
            });
        }
        $filled = $this->count($complete);

        $score = round($filled / $total * 100, 1);
        $gap = null;
        if ($filled < $total) {
            $gap = [
                'title' => 'wizard.gap.records_incomplete',
                'description' => 'wizard.gap.records_incomplete_desc',
                'description_params' => ['%open%' => $total - $filled, '%total%' => $total],
                'priority' => $check['priority'] ?? 'high',
                'route' => $check['route'] ?? null,
            ];
        }

        return [
            'score' => $score,
            'details' => $details + ['total' => $total, 'complete' => $filled],
            'gap' => $gap,
        ];
    }

    /**
     * @param class-string $class
     * @param array<string, mixed> $check
     */
    private function base(string $class, array $check, Tenant $tenant, DateTimeImmutable $today): QueryBuilder
    {
        $qb = $this->entityManager->createQueryBuilder()
            ->select('COUNT(r)')
            ->from($class, 'r')
            ->where('r.tenant = :tenant')
            ->setParameter('tenant', $tenant);
        $i = 0;
        foreach ((array) ($check['where'] ?? []) as $field => $value) {
            $qb->andWhere(sprintf('r.%s = :w%d', $field, $i))->setParameter('w' . $i, $value);
            $i++;
        }
        $since = $check['since'] ?? null;
        if (is_array($since) && isset($since['field'], $since['months'])) {
            $qb->andWhere(sprintf('r.%s >= :since', (string) $since['field']))
                ->setParameter('since', $today->modify(sprintf('-%d months', (int) $since['months'])));
        }

        return $qb;
    }

    private function count(QueryBuilder $qb): int
    {
        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
