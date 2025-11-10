<?php

namespace App\Service;

use App\Command\LoadTisaxRequirementsCommand;
use App\Command\LoadDoraRequirementsCommand;
use App\Command\LoadNis2RequirementsCommand;
use App\Command\LoadBsiItGrundschutzRequirementsCommand;
use App\Command\LoadGdprRequirementsCommand;
use App\Repository\ComplianceFrameworkRepository;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Service to manage and load compliance frameworks via UI
 */
class ComplianceFrameworkLoaderService
{
    public function __construct(
        private ComplianceFrameworkRepository $frameworkRepository,
        private LoadTisaxRequirementsCommand $tisaxCommand,
        private LoadDoraRequirementsCommand $doraCommand,
        private LoadNis2RequirementsCommand $nis2Command,
        private LoadBsiItGrundschutzRequirementsCommand $bsiCommand,
        private LoadGdprRequirementsCommand $gdprCommand,
    ) {}

    /**
     * Get list of all available frameworks with their metadata and load status
     */
    public function getAvailableFrameworks(): array
    {
        $loadedFrameworks = $this->frameworkRepository->findAll();
        $loadedCodes = array_map(fn($f) => $f->getCode(), $loadedFrameworks);

        return [
            [
                'code' => 'TISAX',
                'name' => 'TISAX (Trusted Information Security Assessment Exchange)',
                'description' => 'Information security assessment standard for the automotive industry based on VDA ISA',
                'industry' => 'automotive',
                'regulatory_body' => 'VDA (Verband der Automobilindustrie)',
                'mandatory' => false,
                'version' => '6.0.2',
                'loaded' => in_array('TISAX', $loadedCodes),
                'icon' => '🚗',
            ],
            [
                'code' => 'DORA',
                'name' => 'EU-DORA (Digital Operational Resilience Act)',
                'description' => 'Regulation on digital operational resilience for the financial sector',
                'industry' => 'financial_services',
                'regulatory_body' => 'European Union',
                'mandatory' => true,
                'version' => '2022/2554',
                'loaded' => in_array('DORA', $loadedCodes),
                'icon' => '🏦',
            ],
            [
                'code' => 'NIS2',
                'name' => 'NIS2 (Network and Information Security Directive 2)',
                'description' => 'EU directive on measures for a high common level of cybersecurity',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => true,
                'version' => '2022/2555',
                'loaded' => in_array('NIS2', $loadedCodes),
                'icon' => '🛡️',
            ],
            [
                'code' => 'BSI_GRUNDSCHUTZ',
                'name' => 'BSI IT-Grundschutz',
                'description' => 'German information security standard by the Federal Office for Information Security',
                'industry' => 'all_sectors',
                'regulatory_body' => 'BSI (Bundesamt für Sicherheit in der Informationstechnik)',
                'mandatory' => false,
                'version' => 'Edition 2023',
                'loaded' => in_array('BSI_GRUNDSCHUTZ', $loadedCodes),
                'icon' => '🇩🇪',
            ],
            [
                'code' => 'GDPR',
                'name' => 'GDPR (General Data Protection Regulation)',
                'description' => 'EU regulation on data protection and privacy',
                'industry' => 'all_sectors',
                'regulatory_body' => 'European Union',
                'mandatory' => true,
                'version' => '2016/679',
                'loaded' => in_array('GDPR', $loadedCodes),
                'icon' => '🔒',
            ],
        ];
    }

    /**
     * Load a specific framework by code
     */
    public function loadFramework(string $code): array
    {
        $command = match($code) {
            'TISAX' => $this->tisaxCommand,
            'DORA' => $this->doraCommand,
            'NIS2' => $this->nis2Command,
            'BSI_GRUNDSCHUTZ' => $this->bsiCommand,
            'GDPR' => $this->gdprCommand,
            default => null,
        };

        if (!$command) {
            return [
                'success' => false,
                'message' => 'Framework not found',
            ];
        }

        // Check if already loaded
        $existingFramework = $this->frameworkRepository->findOneBy(['code' => $code]);
        if ($existingFramework) {
            return [
                'success' => false,
                'message' => 'Framework already loaded',
            ];
        }

        // Execute the command
        $input = new ArrayInput([]);
        $output = new BufferedOutput();

        try {
            $returnCode = $command->run($input, $output);

            if ($returnCode === 0) {
                return [
                    'success' => true,
                    'message' => sprintf('Successfully loaded %s framework', $code),
                    'output' => $output->fetch(),
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Failed to load framework',
                    'output' => $output->fetch(),
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Error loading framework: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Get statistics about loaded frameworks
     */
    public function getFrameworkStatistics(): array
    {
        $available = $this->getAvailableFrameworks();
        $loaded = array_filter($available, fn($f) => $f['loaded']);
        $mandatory = array_filter($available, fn($f) => $f['mandatory']);
        $mandatoryLoaded = array_filter($mandatory, fn($f) => $f['loaded']);

        return [
            'total_available' => count($available),
            'total_loaded' => count($loaded),
            'total_not_loaded' => count($available) - count($loaded),
            'mandatory_frameworks' => count($mandatory),
            'mandatory_loaded' => count($mandatoryLoaded),
            'mandatory_not_loaded' => count($mandatory) - count($mandatoryLoaded),
            'compliance_percentage' => count($available) > 0
                ? round((count($loaded) / count($available)) * 100, 1)
                : 0,
        ];
    }
}
