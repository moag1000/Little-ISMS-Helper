<?php

declare(strict_types=1);

namespace App\Template\Provider;

use App\Entity\Supplier;
use App\Template\SystemTemplate;
use App\Template\TemplateProviderInterface;

/**
 * Hyperscaler / SaaS supplier starter set (5 entries).
 *
 * Foundation P-14. AWS, Azure, GCP, Microsoft 365, Google Workspace —
 * the cloud providers that are present in 90%+ of German SMB ISMS scopes.
 * Each template prefills `name`, `serviceProvided`, `criticality='medium'`,
 * `hasISO27001=true`, `hasDPA=true`, `countryOfHeadOffice='US'`,
 * `isDoraRelevant=true` (so finance-sector tenants don't need to flip the
 * switch manually).
 */
final class CloudProviderTemplateProvider implements TemplateProviderInterface
{
    public function provide(): iterable
    {
        $providers = [
            [
                'key' => 'aws',
                'name' => 'Amazon Web Services (AWS)',
                'service_de' => 'IaaS / PaaS — Compute, Storage, Datenbanken, Lambda, S3, RDS, etc.',
                'service_en' => 'IaaS / PaaS — compute, storage, databases, Lambda, S3, RDS, etc.',
            ],
            [
                'key' => 'azure',
                'name' => 'Microsoft Azure',
                'service_de' => 'IaaS / PaaS — Compute, Storage, AD, AKS, SQL, etc.',
                'service_en' => 'IaaS / PaaS — compute, storage, AD, AKS, SQL, etc.',
            ],
            [
                'key' => 'gcp',
                'name' => 'Google Cloud Platform (GCP)',
                'service_de' => 'IaaS / PaaS — Compute, Storage, BigQuery, GKE, Vertex AI, etc.',
                'service_en' => 'IaaS / PaaS — compute, storage, BigQuery, GKE, Vertex AI, etc.',
            ],
            [
                'key' => 'm365',
                'name' => 'Microsoft 365',
                'service_de' => 'SaaS — E-Mail (Exchange Online), Office Apps, Teams, OneDrive, SharePoint.',
                'service_en' => 'SaaS — email (Exchange Online), Office apps, Teams, OneDrive, SharePoint.',
            ],
            [
                'key' => 'gworkspace',
                'name' => 'Google Workspace',
                'service_de' => 'SaaS — Gmail, Docs, Drive, Meet, Calendar.',
                'service_en' => 'SaaS — Gmail, Docs, Drive, Meet, Calendar.',
            ],
        ];

        foreach (['de', 'en'] as $lang) {
            foreach ($providers as $p) {
                $de = $lang === 'de';
                yield new SystemTemplate(
                    key: 'supplier.cloud.' . $p['key'] . '.' . $lang,
                    entityClass: Supplier::class,
                    module: 'suppliers',
                    language: $lang,
                    name: $p['name'],
                    description: $de ? $p['service_de'] : $p['service_en'],
                    prefill: [
                        'name' => $p['name'],
                        'description' => $de ? $p['service_de'] : $p['service_en'],
                        'serviceProvided' => $de ? $p['service_de'] : $p['service_en'],
                        'criticality' => 'medium',
                        'status' => 'active',
                        'hasISO27001' => true,
                        'hasISO22301' => false,
                        'hasDPA' => true,
                        'isDoraRelevant' => true,
                        'countryOfHeadOffice' => 'US',
                        'certifications' => $de
                            ? 'ISO 27001, ISO 27017, ISO 27018, SOC 2 Type II, C5 Type 2 (BSI), TISAX, PCI-DSS'
                            : 'ISO 27001, ISO 27017, ISO 27018, SOC 2 Type II, C5 Type 2 (BSI), TISAX, PCI-DSS',
                    ],
                );
            }
        }
    }
}
