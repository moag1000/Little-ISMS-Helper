<?php

namespace App\Service;

use App\Command\LoadTisaxRequirementsCommand;
use App\Command\LoadDoraRequirementsCommand;
use App\Command\LoadNis2RequirementsCommand;
use App\Command\LoadBsiItGrundschutzRequirementsCommand;
use App\Command\LoadGdprRequirementsCommand;
use App\Command\LoadIso27001RequirementsCommand;
use App\Command\LoadIso27701RequirementsCommand;
use App\Command\LoadIso27701v2025RequirementsCommand;
use App\Command\LoadC5RequirementsCommand;
use App\Command\LoadC52025RequirementsCommand;
use App\Command\LoadKritisRequirementsCommand;
use App\Command\LoadKritisHealthRequirementsCommand;
use App\Command\LoadDigavRequirementsCommand;
use App\Command\LoadTkgRequirementsCommand;
use App\Command\LoadGxpRequirementsCommand;
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
        private LoadIso27001RequirementsCommand $iso27001Command,
        private LoadIso27701RequirementsCommand $iso27701Command,
        private LoadIso27701v2025RequirementsCommand $iso27701v2025Command,
        private LoadC5RequirementsCommand $c5Command,
        private LoadC52025RequirementsCommand $c52025Command,
        private LoadKritisRequirementsCommand $kritisCommand,
        private LoadKritisHealthRequirementsCommand $kritisHealthCommand,
        private LoadDigavRequirementsCommand $digavCommand,
        private LoadTkgRequirementsCommand $tkgCommand,
        private LoadGxpRequirementsCommand $gxpCommand,
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
            [
                'code' => 'ISO27001',
                'name' => 'ISO/IEC 27001:2022 - ISMS',
                'description' => 'Information Security Management System - International standard for information security management',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'version' => '2022',
                'loaded' => in_array('ISO27001', $loadedCodes),
                'icon' => '📋',
            ],
            [
                'code' => 'ISO27701',
                'name' => 'ISO/IEC 27701:2019 - PIMS',
                'description' => 'Privacy Information Management System extension to ISO 27001/27002',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'version' => '2019',
                'loaded' => in_array('ISO27701', $loadedCodes),
                'icon' => '🔐',
            ],
            [
                'code' => 'ISO27701_2025',
                'name' => 'ISO/IEC 27701:2025 - PIMS',
                'description' => 'Privacy Information Management System - Standalone standard with AI governance (Latest)',
                'industry' => 'all_sectors',
                'regulatory_body' => 'ISO/IEC',
                'mandatory' => false,
                'version' => '2025',
                'loaded' => in_array('ISO27701_2025', $loadedCodes),
                'icon' => '🤖',
            ],
            [
                'code' => 'BSI-C5',
                'name' => 'BSI C5:2020 - Cloud Computing Compliance Criteria Catalogue',
                'description' => 'German Federal Office for Information Security criteria catalogue for secure cloud computing (121 criteria in 17 categories)',
                'industry' => 'cloud_services',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'version' => '2020',
                'loaded' => in_array('BSI-C5', $loadedCodes),
                'icon' => '☁️',
            ],
            [
                'code' => 'BSI-C5-2025',
                'name' => 'BSI C5:2025 Community Draft - Cloud Computing Compliance',
                'description' => 'Next-generation C5 with container management, supply chain security, post-quantum cryptography, and confidential computing (43 new requirements, mandatory from Jan 2027)',
                'industry' => 'cloud_services',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => false,
                'version' => '2025 Community Draft',
                'loaded' => in_array('BSI-C5-2025', $loadedCodes),
                'icon' => '🔮',
            ],
            [
                'code' => 'KRITIS',
                'name' => 'KRITIS - Critical Infrastructure Protection (§8a BSIG)',
                'description' => 'German security requirements for operators of critical infrastructure based on IT Security Act 2.0 (135 controls)',
                'industry' => 'critical_infrastructure',
                'regulatory_body' => 'BSI - Bundesamt für Sicherheit in der Informationstechnik',
                'mandatory' => true,
                'version' => '§8a BSIG (IT-SiG 2.0)',
                'loaded' => in_array('KRITIS', $loadedCodes),
                'icon' => '⚡',
            ],
            [
                'code' => 'KRITIS-HEALTH',
                'name' => 'KRITIS Healthcare - Hospital IT Security',
                'description' => 'IT security requirements for hospitals and healthcare facilities (KHPatSiG, KHZG, §75c SGB V, BSI B3S, 37 requirements)',
                'industry' => 'healthcare',
                'regulatory_body' => 'BSI / Bundesgesundheitsministerium',
                'mandatory' => true,
                'version' => 'KHPatSiG/KHZG 2024',
                'loaded' => in_array('KRITIS-HEALTH', $loadedCodes),
                'icon' => '🏥',
            ],
            [
                'code' => 'DIGAV',
                'name' => 'DiGAV - Digital Health Applications Regulation',
                'description' => 'Requirements for digital health applications (DiGA) - apps on prescription in Germany (38 requirements)',
                'industry' => 'healthcare',
                'regulatory_body' => 'BfArM - Bundesinstitut für Arzneimittel und Medizinprodukte',
                'mandatory' => true,
                'version' => 'DiGAV 2024',
                'loaded' => in_array('DIGAV', $loadedCodes),
                'icon' => '📱',
            ],
            [
                'code' => 'TKG-2024',
                'name' => 'TKG 2024 - Telecommunications Security',
                'description' => 'Security requirements for telecommunications providers (§164-167 TKG, TK-SiV, BNetzA Security Catalog 2.0, 43 requirements)',
                'industry' => 'telecommunications',
                'regulatory_body' => 'Bundesnetzagentur / BSI',
                'mandatory' => true,
                'version' => 'TKG 2024',
                'loaded' => in_array('TKG-2024', $loadedCodes),
                'icon' => '📡',
            ],
            [
                'code' => 'GXP',
                'name' => 'GxP - Good Practice (Pharmaceutical & Life Sciences)',
                'description' => 'Regulatory requirements for pharmaceutical and life sciences (EU GMP Annex 11, FDA 21 CFR Part 11, GAMP 5, 65+ requirements)',
                'industry' => 'pharmaceutical',
                'regulatory_body' => 'EMA / FDA',
                'mandatory' => true,
                'version' => 'EU GMP Annex 11 (2024) / FDA 21 CFR Part 11',
                'loaded' => in_array('GXP', $loadedCodes),
                'icon' => '💊',
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
            'ISO27001' => $this->iso27001Command,
            'ISO27701' => $this->iso27701Command,
            'ISO27701_2025' => $this->iso27701v2025Command,
            'BSI-C5' => $this->c5Command,
            'BSI-C5-2025' => $this->c52025Command,
            'KRITIS' => $this->kritisCommand,
            'KRITIS-HEALTH' => $this->kritisHealthCommand,
            'DIGAV' => $this->digavCommand,
            'TKG-2024' => $this->tkgCommand,
            'GXP' => $this->gxpCommand,
            default => null,
        };

        if (!$command) {
            return [
                'success' => false,
                'message' => 'Framework not found',
            ];
        }

        // Check if already loaded with requirements
        $existingFramework = $this->frameworkRepository->findOneBy(['code' => $code]);
        if ($existingFramework) {
            $requirementsCount = $existingFramework->getRequirements()->count();
            if ($requirementsCount > 0) {
                return [
                    'success' => false,
                    'message' => sprintf('Framework already loaded with %d requirements', $requirementsCount),
                ];
            }
            // Framework exists but has no requirements - allow loading
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
        } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException $e) {
            return [
                'success' => false,
                'message' => 'Framework or requirements already exist in database',
            ];
        } catch (\Doctrine\ORM\Exception\ORMException $e) {
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage(),
            ];
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
