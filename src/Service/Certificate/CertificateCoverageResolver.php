<?php

declare(strict_types=1);

namespace App\Service\Certificate;

use App\Entity\ComplianceCertificate;
use App\Repository\CertificateCoverageRuleRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * Resolves which compliance requirement-ids a ComplianceCertificate covers.
 *
 * Resolution strategy:
 *  1. Load all active CertificateCoverageRules for the certificate's frameworkCode.
 *  2. Filter rules via CertificateCoverageRule::matches() using the cert's class and scope tags.
 *  3. If at least one rule matches → union of all matched rules' requirementIds (deduplicated).
 *  4. If no rule matches (fallback) → ALL requirementIds of the framework, isFallback=true.
 *  5. If the framework is unknown → empty list, isFallback=true.
 */
final class CertificateCoverageResolver
{
    public function __construct(
        private readonly CertificateCoverageRuleRepository $ruleRepo,
        private readonly ComplianceFrameworkRepository $frameworkRepo,
        private readonly ComplianceRequirementRepository $requirementRepo,
    ) {
    }

    public function resolve(ComplianceCertificate $cert): CoverageResult
    {
        $rules = $this->ruleRepo->findActiveByFramework($cert->getFrameworkCode());

        $matched = array_filter(
            $rules,
            static fn ($rule) => $rule->matches($cert->getCertClass(), $cert->getScopeTags()),
        );

        if ($matched !== []) {
            $ids = [];
            foreach ($matched as $rule) {
                foreach ($rule->getRequirementIds() as $id) {
                    $ids[(string) $id] = true;
                }
            }
            return new CoverageResult(array_keys($ids), false);
        }

        // Fallback: all requirement business-ids of the framework
        $framework = $this->frameworkRepo->findOneBy(['code' => $cert->getFrameworkCode()]);

        if ($framework === null) {
            return new CoverageResult([], true);
        }

        $requirements = $this->requirementRepo->findByFramework($framework);
        $ids = array_values(
            array_unique(
                array_map(
                    static fn ($req) => (string) $req->getRequirementId(),
                    $requirements,
                ),
            ),
        );

        return new CoverageResult($ids, true);
    }
}
