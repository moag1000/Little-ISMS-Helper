<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\Compliance\FrameworkLoaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * F35 — EUCS (European Cybersecurity Certification Scheme for Cloud Services).
 *
 * Loads the EUCS control-category catalogue as ComplianceRequirement rows under
 * a dedicated ComplianceFramework. EUCS is the ENISA candidate scheme under the
 * EU Cybersecurity Act (Regulation (EU) 2019/881), assurance levels
 * Basic / Substantial / High.
 *
 * Only the public category codes + short titles are shipped (no copyrighted
 * control text), mirroring the TISAX licensing approach: the operator maps their
 * own evidence against the categories. The 20 control categories follow the
 * EUCS candidate scheme structure (OIS … CCM).
 */
#[AsCommand(
    name: 'app:load-eucs',
    description: 'Load EUCS (EU Cloud Services certification scheme) control categories as ComplianceRequirement rows.',
)]
final class LoadEucsFullCommand extends Command implements FrameworkLoaderInterface
{
    /** EUCS control categories: code => [title, assurance-floor]. */
    private const CATEGORIES = [
        'OIS'  => ['Organisation of Information Security', 'basic'],
        'ISP'  => ['Information Security Policies', 'basic'],
        'RM'   => ['Risk Management', 'basic'],
        'HR'   => ['Human Resources', 'basic'],
        'AM'   => ['Asset Management', 'basic'],
        'PS'   => ['Physical Security', 'basic'],
        'OPS'  => ['Operational Security', 'substantial'],
        'IAM'  => ['Identity, Authentication and Access Control Management', 'substantial'],
        'CKM'  => ['Cryptography and Key Management', 'substantial'],
        'CS'   => ['Communication Security', 'substantial'],
        'PI'   => ['Portability and Interoperability', 'substantial'],
        'IM'   => ['Incident Management', 'substantial'],
        'CO'   => ['Compliance', 'basic'],
        'DOC'  => ['User Documentation', 'basic'],
        'INQ'  => ['Dealing with Investigation Requests from Government Agencies', 'high'],
        'PSS'  => ['Product Safety and Security', 'substantial'],
        'DEV'  => ['Development of Information Systems', 'substantial'],
        'PM'   => ['Procurement Management', 'substantial'],
        'BCM'  => ['Business Continuity Management', 'substantial'],
        'CCM'  => ['Cloud Service Customer Data Management', 'high'],
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    public function getFrameworkCode(): string
    {
        return 'EUCS';
    }

    public function loadRequirements(bool $update = false, ?SymfonyStyle $io = null): int
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'EUCS']);
        $isNew = !$framework instanceof ComplianceFramework;
        if ($isNew) {
            $framework = new ComplianceFramework();
        }
        $framework->setCode('EUCS')
            ->setName('EUCS — EU Cybersecurity Certification Scheme for Cloud Services')
            ->setDescription('ENISA candidate scheme under the EU Cybersecurity Act (Regulation (EU) 2019/881). Assurance levels: Basic, Substantial, High. 20 control categories (OIS … CCM).')
            ->setVersion('candidate v1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('ENISA / European Union')
            ->setMandatory(false)
            ->setScopeDescription('Certification of cloud service providers; assurance level chosen by the CSP and customer risk appetite.')
            ->setActive(true);
        if ($isNew) {
            $this->em->persist($framework);
            $this->em->flush();
        }

        $reqRepo = $this->em->getRepository(ComplianceRequirement::class);
        $created = 0;
        $updated = 0;

        foreach (self::CATEGORIES as $code => [$title, $assurance]) {
            $reqId = 'EUCS-' . $code;
            $req = $reqRepo->findOneBy(['framework' => $framework, 'requirementId' => $reqId]);
            if ($req === null) {
                $req = new ComplianceRequirement();
                $req->setFramework($framework);
                $req->setRequirementId($reqId);
                $req->setRequirementType('core');
                $created++;
            } else {
                $updated++;
            }
            $req->setTitle(mb_substr($title, 0, 250));
            $req->setDescription(sprintf('EUCS control category %s — %s. Minimum assurance level: %s. Source: ENISA EUCS candidate scheme (EU Cybersecurity Act, Reg. (EU) 2019/881).', $code, $title, ucfirst($assurance)));
            $req->setCategory('Assurance: ' . ucfirst($assurance));
            $req->setPriority($assurance === 'high' ? 'high' : ($assurance === 'substantial' ? 'medium' : 'low'));
            $this->em->persist($req);
        }

        $this->em->flush();
        $io?->success(sprintf('EUCS: %d created, %d updated. Total: %d control categories.', $created, $updated, count(self::CATEGORIES)));

        return Command::SUCCESS;
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $update = (bool) $input->getOption('update');

        return $this->loadRequirements($update, $io);
    }

    #[\Override]
    protected function configure(): void
    {
        $this->addOption('update', null, InputOption::VALUE_NONE, 'Update existing requirement rows in place');
    }
}
