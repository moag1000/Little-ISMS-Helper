<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\IctProviderLibrary;
use App\Repository\IctProviderLibraryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * F-NEU — seed the curated ICT-provider library (DORA Art. 28).
 *
 * Idempotent upsert by `code`. Ships only generic, publicly-known provider
 * metadata (category, HQ country, typical service) so a tenant can pre-fill a
 * Supplier for its Register of Information instead of typing master data.
 */
#[AsCommand(
    name: 'app:load-ict-provider-library',
    description: 'Seed the curated ICT third-party provider library (DORA Art. 28).',
)]
final class LoadIctProviderLibraryCommand extends Command
{
    private const C_IAAS = IctProviderLibrary::CATEGORY_CLOUD_IAAS;
    private const C_SAAS = IctProviderLibrary::CATEGORY_CLOUD_SAAS;
    private const C_NET  = IctProviderLibrary::CATEGORY_NETWORK;
    private const C_ID   = IctProviderLibrary::CATEGORY_IDENTITY;
    private const C_DATA = IctProviderLibrary::CATEGORY_DATA;
    private const C_SEC  = IctProviderLibrary::CATEGORY_SECURITY;
    private const C_PAY  = IctProviderLibrary::CATEGORY_PAYMENT;
    private const C_COMM = IctProviderLibrary::CATEGORY_COMMS;

    /** code => [name, category, hqCountry, serviceType, criticality, eeaHosted] */
    private const PROVIDERS = [
        'aws'            => ['Amazon Web Services', self::C_IAAS, 'US', 'Public cloud IaaS/PaaS (compute, storage, database)', 'critical', true],
        'azure'          => ['Microsoft Azure', self::C_IAAS, 'US', 'Public cloud IaaS/PaaS', 'critical', true],
        'gcp'            => ['Google Cloud Platform', self::C_IAAS, 'US', 'Public cloud IaaS/PaaS', 'critical', true],
        'oracle-cloud'   => ['Oracle Cloud Infrastructure', self::C_IAAS, 'US', 'Public cloud IaaS + managed databases', 'important', true],
        'ovhcloud'       => ['OVHcloud', self::C_IAAS, 'FR', 'European public cloud IaaS', 'important', true],
        'ionos'          => ['IONOS', self::C_IAAS, 'DE', 'European cloud hosting + IaaS', 'important', true],
        't-systems'      => ['T-Systems (Deutsche Telekom)', self::C_IAAS, 'DE', 'Managed cloud + datacentre services', 'important', true],
        'sap'            => ['SAP', self::C_SAAS, 'DE', 'ERP + business application SaaS', 'critical', true],
        'salesforce'     => ['Salesforce', self::C_SAAS, 'US', 'CRM SaaS platform', 'important', true],
        'microsoft-365'  => ['Microsoft 365', self::C_SAAS, 'US', 'Productivity + collaboration SaaS', 'critical', true],
        'cloudflare'     => ['Cloudflare', self::C_NET, 'US', 'CDN, DDoS protection, WAF, DNS', 'important', true],
        'akamai'         => ['Akamai', self::C_NET, 'US', 'CDN + edge security', 'important', true],
        'entra-id'       => ['Microsoft Entra ID', self::C_ID, 'US', 'Cloud identity + access management', 'critical', true],
        'okta'           => ['Okta', self::C_ID, 'US', 'Identity-as-a-Service (SSO/MFA)', 'important', true],
        'mongodb-atlas'  => ['MongoDB Atlas', self::C_DATA, 'US', 'Managed NoSQL database', 'important', true],
        'snowflake'      => ['Snowflake', self::C_DATA, 'US', 'Cloud data warehouse', 'important', true],
        'crowdstrike'    => ['CrowdStrike', self::C_SEC, 'US', 'Endpoint detection + response (EDR)', 'important', true],
        'stripe'         => ['Stripe', self::C_PAY, 'US', 'Payment processing platform', 'critical', true],
        'twilio'         => ['Twilio', self::C_COMM, 'US', 'Programmable messaging + voice', 'important', true],
    ];

    public function __construct(
        private readonly IctProviderLibraryRepository $repository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $created = 0;
        $updated = 0;

        foreach (self::PROVIDERS as $code => [$name, $category, $hq, $service, $criticality, $eea]) {
            $entry = $this->repository->findOneByCode($code);
            if ($entry === null) {
                $entry = new IctProviderLibrary();
                $entry->setCode($code);
                $created++;
            } else {
                $updated++;
            }
            $entry->setName($name)
                ->setCategory($category)
                ->setHeadquartersCountry($hq)
                ->setServiceType($service)
                ->setDefaultCriticality($criticality)
                ->setEeaHosted($eea);
            $this->em->persist($entry);
        }

        $this->em->flush();
        $io->success(sprintf('ICT-provider library: %d created, %d updated (total %d).', $created, $updated, count(self::PROVIDERS)));

        return Command::SUCCESS;
    }
}
