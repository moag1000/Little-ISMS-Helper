<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\DataBreach;
use App\Entity\DataSubjectRequest;
use App\Entity\Tenant;
use App\Repository\DataBreachRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Security\PersonalDataVisibility;
use App\Service\ModuleConfigurationService;
use App\Util\LikePattern;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Search hits of records about a person as a whole (data breach, data subject request) — S33: only for
 * whom the detail page lets in ({@see PersonalDataVisibility}), so the search never offers a 403.
 */
final readonly class PersonalDataSearch
{
    private const MAX_RESULTS = 5;

    public function __construct(
        private DataBreachRepository $dataBreachRepository,
        private DataSubjectRequestRepository $dataSubjectRequestRepository,
        private UrlGeneratorInterface $urlGenerator,
        private ModuleConfigurationService $moduleConfig,
        private ?PersonalDataVisibility $visibility = null,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function dataBreaches(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataBreachRepository->createQueryBuilder('db')
            ->where('db.title LIKE :query OR db.breachNature LIKE :query')
            ->setParameter('query', LikePattern::contains($query))
            ->setMaxResults(self::MAX_RESULTS);
        if ($tenant !== null) {
            $qb->andWhere('db.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn (DataBreach $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle() ?? $e->getReferenceNumber(),
            'description' => self::truncate($e->getBreachNature()),
            'url' => $this->urlGenerator->generate('app_data_breach_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-breach-octagon',
            'type' => 'data_breach',
            'badge' => $e->getSeverity(),
        ], $this->readable($qb->getQuery()->getResult()));
    }

    /** @return list<array<string, mixed>> */
    public function dataSubjectRequests(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataSubjectRequestRepository->createQueryBuilder('dsr')
            ->where('dsr.dataSubjectName LIKE :query OR dsr.description LIKE :query OR dsr.dataSubjectIdentifier LIKE :query')
            ->setParameter('query', LikePattern::contains($query))
            ->setMaxResults(self::MAX_RESULTS);
        if ($tenant !== null) {
            $qb->andWhere('dsr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn (DataSubjectRequest $e): array => [
            'id' => $e->getId(),
            'title' => $e->getDataSubjectName() ?? $e->getDataSubjectIdentifier(),
            'description' => self::truncate($e->getDescription()),
            'url' => $this->urlGenerator->generate('app_data_subject_request_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-shield-lock',
            'type' => 'data_subject_request',
            'badge' => $e->getRequestType(),
        ], $this->readable($qb->getQuery()->getResult()));
    }

    /**
     * Without the visibility service (unit tests building the search by hand) nothing is shown: fail closed.
     *
     * @template T of object
     * @param array<T> $records
     * @return list<T>
     */
    private function readable(array $records): array
    {
        return $this->visibility?->readable($records) ?? [];
    }

    private static function truncate(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        return mb_strlen($text) <= 100 ? $text : mb_substr($text, 0, 100) . '…';
    }
}
