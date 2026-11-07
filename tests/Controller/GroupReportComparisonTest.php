<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Service\Group\OrganisationComparison;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Group reports read the whole holding subtree (the request's tenant filter
 * used to hide every subsidiary row), the organisation comparison shows each
 * organisation's own KPI values, exports are POST + CSRF + audit-logged, the
 * Konzern-Auditor reads but cannot derive, and subsidiary-controlled names
 * are escaped.
 *
 * Fixture:  Holding ── Child (subsidiary)        Other (unrelated)
 */
final class GroupReportComparisonTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $uid;
    private Tenant $holding;
    private Tenant $child;
    private Tenant $other;
    private User $groupCiso;
    private User $konzernAuditor;
    private User $manager;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->uid = substr(md5(uniqid('', true)), 0, 10);

        $this->holding = $this->tenant('Holding');
        $this->child = $this->tenant('Child <b>x</b>', $this->holding);
        $this->other = $this->tenant('Other');

        $this->risk($this->holding, 'HoldingRisk');
        $this->risk($this->child, 'ChildRisk');
        $this->risk($this->child, 'ChildRiskTwo');
        $this->risk($this->other, 'OtherRisk');

        $supplier = (new Supplier())
            ->setName('<script>alert(1)</script>Lieferant ' . $this->uid)
            ->setServiceProvided('Hosting')
            ->setTenant($this->child);
        $this->em->persist($supplier);

        $this->groupCiso = $this->user('ciso', ['ROLE_GROUP_CISO'], $this->holding);
        $this->konzernAuditor = $this->user('kaud', ['ROLE_KONZERN_AUDITOR'], $this->holding);
        $this->manager = $this->user('mgr', ['ROLE_MANAGER'], $this->holding);
        $this->em->flush();
        // Fresh entities, as in production: the holding's subsidiaries
        // collection is only populated on load.
        $this->em->clear();
        $this->holding = $this->em->find(Tenant::class, $this->holding->getId());
        $this->child = $this->em->find(Tenant::class, $this->child->getId());
        $this->other = $this->em->find(Tenant::class, $this->other->getId());
        $this->groupCiso = $this->em->find(User::class, $this->groupCiso->getId());
        $this->konzernAuditor = $this->em->find(User::class, $this->konzernAuditor->getId());
        $this->manager = $this->em->find(User::class, $this->manager->getId());
    }

    protected function tearDown(): void
    {
        if ($this->em->isOpen()) {
            $this->em->clear();
            $conn = $this->em->getConnection();
            $ids = array_filter([$this->child->getId(), $this->holding->getId(), $this->other->getId()]);
            foreach (['risk', 'supplier', 'incident', 'document', 'audit_log', 'users'] as $table) {
                try {
                    $conn->executeStatement('DELETE FROM ' . $table . ' WHERE tenant_id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
                } catch (\Throwable) {
                    // table without tenant_id in this schema
                }
            }
            $conn->executeStatement('UPDATE tenant SET parent_id = NULL WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
            $conn->executeStatement('DELETE FROM tenant WHERE id IN (?)', [$ids], [\Doctrine\DBAL\ArrayParameterType::INTEGER]);
        }
        parent::tearDown();
    }

    #[Test]
    public function groupRisksReportShowsSubsidiaryRisks(): void
    {
        $this->client->loginUser($this->groupCiso);
        $this->client->request('GET', '/de/group-report/risks');

        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('ChildRisk' . $this->uid, $html);
        self::assertStringContainsString('HoldingRisk' . $this->uid, $html);
        self::assertStringNotContainsString('OtherRisk' . $this->uid, $html);
    }

    #[Test]
    public function subsidiaryNamesAreEscaped(): void
    {
        $this->client->loginUser($this->groupCiso);

        $this->client->request('GET', '/de/group-report/suppliers');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Lieferant ' . $this->uid, $html);
        self::assertStringNotContainsString('<script>alert(1)</script>Lieferant', $html);

        $this->client->request('GET', '/de/group-report/comparison');
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Child &lt;b&gt;x&lt;/b&gt;', $html);
        self::assertStringNotContainsString('Child <b>x</b>', $html);
    }

    #[Test]
    public function comparisonComputesEachOrganisationAndRestoresTheScope(): void
    {
        // A request arms the tenant filter with the user's organisation; reproduce that.
        $this->client->loginUser($this->groupCiso);
        $this->client->request('GET', '/de/group-report/comparison');
        self::assertResponseIsSuccessful();

        $container = static::getContainer();
        $context = $container->get(TenantContext::class);
        $context->setCurrentTenant($this->holding);

        $data = $container->get(OrganisationComparison::class)->build([$this->holding, $this->child]);

        $byCode = [];
        foreach ($data['rows'] as $row) {
            $byCode[$row['tenant']->getCode()] = $row['kpis'];
        }
        self::assertSame(1, (int) $byCode[$this->holding->getCode()]['critical_risks']['value']);
        self::assertSame(2, (int) $byCode[$this->child->getCode()]['critical_risks']['value']);
        self::assertSame('danger', $byCode[$this->child->getCode()]['critical_risks']['status']);
        self::assertSame(3.0, (float) $data['group']['critical_risks']['value']);
        self::assertSame('sum', $data['group']['critical_risks']['mode']);
        self::assertSame($this->holding->getId(), $context->getCurrentTenant()?->getId());
    }

    #[Test]
    public function oldMatrixUrlRedirectsToComparison(): void
    {
        $this->client->loginUser($this->groupCiso);
        $this->client->request('GET', '/de/group-report/kpi-matrix');

        self::assertResponseRedirects('/de/group-report/comparison', Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Test]
    public function exportIsPostOnlyWithToken(): void
    {
        $this->client->loginUser($this->groupCiso);

        $this->client->request('GET', '/de/group-report/comparison/export.xlsx');
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->client->request('POST', '/de/group-report/comparison/export.pdf', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function pdfExportIsDeliveredAndAuditLogged(): void
    {
        $this->client->loginUser($this->groupCiso);
        $this->client->request('GET', '/de/group-report/comparison');
        $token = $this->client->getCrawler()
            ->filter('button[formaction$="/comparison/export.pdf"]')->closest('form')
            ->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/de/group-report/comparison/export.pdf', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('application/pdf', $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('%PDF', (string) $this->client->getResponse()->getContent());

        $this->em->clear();
        $log = $this->em->getRepository(AuditLog::class)->findOneBy(
            ['action' => 'group_report.comparison.export', 'entityId' => $this->holding->getId()],
            ['id' => 'DESC'],
        );
        self::assertNotNull($log);
        self::assertSame(['format' => 'pdf', 'organisations' => 2], $log->getNewValuesArray());
    }

    #[Test]
    public function excelExportIsDelivered(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip missing (xlsx writer)');
        }
        $this->client->loginUser($this->konzernAuditor);
        $this->client->request('GET', '/de/group-report/comparison');
        $token = $this->client->getCrawler()
            ->filter('button[formaction$="/comparison/export.xlsx"]')->closest('form')
            ->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/de/group-report/comparison/export.xlsx', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('spreadsheetml', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringStartsWith('PK', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString(
            'organisationsvergleich-' . $this->holding->getCode(),
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    #[Test]
    public function konzernAuditorReadsButCannotDerive(): void
    {
        $this->client->loginUser($this->konzernAuditor);

        $this->client->request('GET', '/de/group-report/comparison');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('ChildRisk' . $this->uid, (string) $this->client->request('GET', '/de/group-report/risks')->html());

        $this->client->request('GET', '/de/group-report/audit-program');
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/derive', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/de/group-report/audit-program/1/derive', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function konzernRollupCountsSubsidiaryPolicies(): void
    {
        $doc = new Document();
        $doc->setTenant($this->child);
        $doc->setFilename('dora.md');
        $doc->setOriginalFilename('dora.md');
        $doc->setMimeType('text/markdown');
        $doc->setFileSize(1);
        $doc->setFilePath('virtual:test');
        $doc->setCategory('dora_policy');
        $doc->setStatus('draft');
        $doc->setUploadedAt(new \DateTimeImmutable());
        $this->em->persist($doc);
        $this->em->flush();

        $this->client->loginUser($this->groupCiso);
        $this->client->request('GET', '/de/policy-wizard/konzern-rollup');
        self::assertResponseIsSuccessful();

        // Holding without policies shows the "—" placeholder; the child's DORA policy is counted.
        $placeholders = $this->client->getCrawler()->filter('#tab-coverage p.text-muted.small')->count();
        self::assertSame(1, $placeholders);
    }

    #[Test]
    public function managerWithoutGroupRoleIsDenied(): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', '/de/group-report/comparison');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function tenant(string $label, ?Tenant $parent = null): Tenant
    {
        $tenant = (new Tenant())
            ->setName($label . ' ' . $this->uid)
            ->setCode('grc_' . strtolower(preg_replace('/\W+/', '_', $label)) . '_' . $this->uid)
            ->setIsActive(true);
        if ($parent !== null) {
            $tenant->setParent($parent);
        }
        $this->em->persist($tenant);

        return $tenant;
    }

    private function risk(Tenant $tenant, string $title): void
    {
        $risk = new Risk();
        $risk->setTenant($tenant);
        $risk->setTitle($title . $this->uid);
        $risk->setCategory('operational');
        $risk->setDescription('Group comparison fixture.');
        $risk->setProbability(5);
        $risk->setImpact(5);
        $risk->setStatus(RiskStatus::Identified);
        $risk->setCreatedAt(new \DateTimeImmutable());
        $this->em->persist($risk);
    }

    /** @param list<string> $roles */
    private function user(string $label, array $roles, Tenant $tenant): User
    {
        $user = (new User())
            ->setEmail($label . '_' . $this->uid . '@example.com')
            ->setFirstName(ucfirst($label))
            ->setLastName('Group')
            ->setRoles($roles)
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em->persist($user);

        return $user;
    }
}
