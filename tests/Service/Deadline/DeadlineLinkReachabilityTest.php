<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Service\Deadline\Configured\ConfiguredDeadlineSource;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\RecordLink;
use App\Service\Deadline\Source\ChangeApprovalSource;
use App\Service\Deadline\Source\EntityDeadlineSource;
use App\Service\Deadline\Source\ManagementTrainee;
use App\Service\Deadline\Source\UpcomingDeletion;
use App\Service\ManagementDecision\BoardUnassigned;
use App\Service\ManagementDecision\StandingApproval;
use App\Service\ModuleConfigurationService;
use App\Service\Register\CompetenceGap;
use App\Service\Risk\RiskAcceptanceRequest;
use App\Entity\ActionItemReference;
use App\Entity\Incident;
use App\Entity\RecurringDuty;
use App\Entity\Suggestion;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Enum\IncidentSeverity;
use App\Enum\SuggestionOrigin;
use App\Service\Suggestion\SuggestionDeadlineSource;
use App\Service\Suggestion\SuggestionQueue;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementReview;
use App\Entity\Risk;
use App\Enum\ManagementDecisionType;
use App\Enum\ManagementReviewStatus;
use App\Repository\ActionItemRepository;
use App\Service\Planning\Source\Adapter\RecurringDutyAdapter;
use App\Service\Planning\Source\Adapter\WorkflowInstanceAdapter;
use App\Service\RecurringDuty\RecurringDutyCatalog;
use App\Service\RecurringDuty\RecurringDutyService;
use App\Service\Register\RegisterReviewSource;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\RiskApprovalConfigView;
use App\Service\Risk\RiskAcceptanceRequestSource;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * W1 "act where reminded", T (wave 7): whoever a deadline source reminds can
 * open the page the reminder links to — tested end to end, not by reading
 * attributes. For every source (classes and config/deadline_types.yaml):
 *
 *   1. a record the source supports() is stored in an organisation of its own,
 *   2. for each person it reminds — every fallback role of responsibleOf(), and
 *      a holder named on the record (a plain ROLE_USER account and a member of
 *      top management, a read-only reviewer role) — an account is created and
 *      ResponsibilityResolver must name exactly that account as responsible
 *      (otherwise the test setup, not the app, is wrong and the test says so),
 *   3. that account opens linkOf(record) with GET; redirects are followed and the
 *      page must answer 200 at the link's own path (not a "module not active" page),
 *   4. for review duties (MAIN_ACTION) it submits the page's main form as that
 *      person — the reminder is about doing the review, not about reading it.
 *
 * So every gate counts as it does in production: #[IsGranted] with the real
 * voters (record voters, RECORD_RESPONSIBLE, PERSONA_*), checks inside the
 * action (denyAccessUnlessGranted, checkModuleActive, redirects for
 * non-managers), the /admin access rule, ReadOnlyRoleSubscriber and the
 * module listener. Before T the test parsed #[IsGranted] and let every
 * non-role attribute pass, never looked inside the action and skipped sources
 * whose link or recipients it could not parse.
 *
 * Roles are read from responsibleOf() (literal role strings, class constants);
 * roles computed at runtime are listed in DYNAMIC_ROLES.
 */
final class DeadlineLinkReachabilityTest extends WebTestCase
{
    /** Holder accounts every source with record holders must reach. */
    private const array HOLDER_ACTORS = ['ROLE_USER', 'ROLE_TOP_MGMT'];

    /**
     * Sources whose holder field can only name an account with a given role,
     * because only that role creates the record (access review campaigns:
     * createdBy, the campaign form is ROLE_MANAGER).
     */
    private const array HOLDER_MIN_ROLE = [
        'access_review' => 'ROLE_MANAGER',
    ];

    /** Sources whose fallback role is computed: every value it can take. */
    private const array DYNAMIC_ROLES = [
        RegisterReviewSource::class => 'registerRoles',
        RecurringDutyAdapter::class => 'recurringDutyRoles',
        RiskAcceptanceRequestSource::class => 'approvalLevelRoles',
        WorkflowInstanceAdapter::class => 'workflowApproverRoles',
        SuggestionDeadlineSource::class => 'suggestionRoles',
    ];

    /**
     * Sources whose items have no page of their own: slug => reason. Empty since
     * W6 (the TLPT page); a new entry needs a reason.
     *
     * @var array<string, string>
     */
    private const array WITHOUT_LINK = [];

    /**
     * W6: the action that settles the duty, on the page the reminder opens —
     * slug => POST route. The reminded person must find its form there (the
     * button is not hidden from them) and submitting it must not be refused.
     * Duties settled by editing the record or by a lifecycle step (menu built
     * in JS) are not listed; the GET above covers them.
     */
    private const array MAIN_ACTION = [
        'processing_activity_review' => 'app_processing_activity_complete_review',
        'dpia_review' => 'app_dpia_complete_review',
        'tia_review' => 'app_tia_complete_review',
        'risk_review' => 'app_risk_review_confirm',
        'control_review' => 'app_soa_review_confirm',
    ];

    /** Routes a refused or redirected-away person lands on. */
    private const string REFUSED_LANDING = '/^(app_dashboard|app_home|app_login|app_mfa_|mfa_|app_setup|app_persona_dashboard)/';

    /** Part of messages:common.module_not_active (DE), the soft "module off" page of show templates. */
    private const string MODULE_OFF_MARKER = 'ist nicht aktiviert. Bitte Setup';

    private KernelBrowser $client;
    private UrlGeneratorInterface $router;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->router = static::getContainer()->get('router');
    }

    #[Test]
    public function everyReminderLinkOpensForWhoIsReminded(): void
    {
        $registry = static::getContainer()->get(DeadlineSourceRegistry::class);
        $failures = [];
        $checked = 0;
        $slugs = [];
        foreach ($registry->all() as $source) {
            $slugs[] = $source->slug();
            [$roles, $holderPaths] = $this->describe($source);
            $holders = isset(self::HOLDER_MIN_ROLE[$source->slug()]) ? [self::HOLDER_MIN_ROLE[$source->slug()]] : self::HOLDER_ACTORS;
            $actors = [];
            foreach ($roles as $role) {
                $actors[] = [$role, false];
            }
            foreach ($holderPaths !== [] ? $holders : [] as $role) {
                $actors[] = [$role, true];
            }
            if ($actors === []) {
                $failures[] = $source->slug() . ': no reminded person found (no literal role, no holder path) — list its roles in DYNAMIC_ROLES';
                continue;
            }
            foreach ($actors as [$actor, $asHolder]) {
                $why = $this->unreachableBecause($source, $actor, $holderPaths, $asHolder);
                ++$checked;
                if ($why !== null) {
                    $failures[] = sprintf('%s for %s%s: %s', $source->slug(), $actor, $asHolder ? ' (holder)' : '', $why);
                }
            }
        }

        self::assertGreaterThan(150, $checked, 'too few source/role combinations found — did the parsing break?');
        self::assertSame([], array_values(array_diff(array_keys(self::MAIN_ACTION), $slugs)), 'MAIN_ACTION names a source that does not exist');
        self::assertSame([], $failures, "Reminder links the reminded person cannot open:\n" . implode("\n", $failures));
    }

    /**
     * The checker itself, against routes whose answer is known: it sees role
     * gates, the /admin rule, record voters with the record's organisation and
     * inline denyAccessUnlessGranted — none of which the old static version did.
     */
    #[Test]
    public function checkerSeesEveryKindOfGate(): void
    {
        $tenant = $this->tenant();
        $risk = $this->factory()->create(Risk::class, $tenant, 'dlr');
        $this->em()->flush();

        self::assertNull($this->opens($this->user('ROLE_USER', $tenant), new RecordLink('app_risk_show', ['id' => (int) $risk->getId()])));
        self::assertNotNull($this->opens($this->user('ROLE_USER', $tenant), new RecordLink('app_data_subject_request_new')), 'ROLE_MANAGER form');
        self::assertNotNull($this->opens($this->user('ROLE_TOP_MGMT', $tenant), new RecordLink('app_risk_new')), 'form route for a read-only role');
        self::assertNotNull($this->opens($this->user('ROLE_DPO', $tenant), new RecordLink('admin_settings_data_retention')), '/admin');

        // A record of another organisation: the record voter refuses, which the old check let pass.
        $foreign = $this->factory()->create(Risk::class, $this->tenant(), 'dlr');
        $this->em()->flush();
        self::assertNotNull($this->opens($this->user('ROLE_ISB', $tenant), new RecordLink('app_risk_show', ['id' => (int) $foreign->getId()])), 'record voter');
    }

    /** Null when the reminded person opens the link, else the reason. */
    /** @param list<string> $holderPaths */
    private function unreachableBecause(DeadlineSource $source, string $actor, array $holderPaths, bool $asHolder): ?string
    {
        $tenant = $this->tenant($source);
        $user = $this->user($actor, $tenant);
        $connection = $this->em()->getConnection();
        $connection->beginTransaction(); // savepoint: a failed insert must not abort the test's transaction
        try {
            $item = $this->item($source, $tenant, $actor, $user, $asHolder);
            $connection->commit();
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            static::getContainer()->get('doctrine')->resetManager();

            $root = $e;
            while ($root->getPrevious() !== null) {
                $root = $root->getPrevious();
            }

            return 'cannot build a record (' . strtok($root->getMessage(), "\n") . ')';
        }
        if (!$source->supports($item)) {
            return 'the built ' . $item::class . ' is not supported by the source — prepare it in item()';
        }
        // Role actors: nobody named on the record, so the fallback roles answer. Holders: the account named.
        foreach ($holderPaths as $path) {
            $this->clear($item, $path);
        }
        if ($asHolder && !$this->place($item, $holderPaths[0], $user, $tenant)) {
            return 'cannot name the holder on ' . $holderPaths[0];
        }
        $this->em()->flush();

        $resolver = static::getContainer()->get(ResponsibilityResolver::class);
        // Forget answers of earlier records (a test-wide service; called by name, the gate takes it for an action).
        call_user_func([$resolver, 'reset']);
        if (!in_array($user, $resolver->recipients($source->responsibleOf($item), $this->managed($tenant)), true)) {
            return 'test setup: the reminder does not go to this account (fix the role/holder list, not the app)';
        }

        $link = $source->linkOf($item);
        if ($link === null) {
            return isset(self::WITHOUT_LINK[$source->slug()]) ? null : 'linkOf() is null: the reminder has no page — give it one or list it in WITHOUT_LINK';
        }

        return $this->opens($user, $link) ?? $this->acts($source);
    }

    /**
     * After opens(): the page the person landed on carries the form of the
     * source's main action, and submitting it is not refused (403, or a
     * redirect to where refused people land).
     */
    private function acts(DeadlineSource $source): ?string
    {
        $route = self::MAIN_ACTION[$source->slug()] ?? null;
        if ($route === null) {
            return null;
        }
        $crawler = $this->client->getCrawler();
        $form = null;
        // Action routes are POST-only: match them in a POST context.
        $context = clone $this->router->getContext();
        $context->setMethod('POST');
        $matcher = new \Symfony\Component\Routing\Matcher\UrlMatcher($this->router->getRouteCollection(), $context);
        foreach ($crawler->filter('form') as $node) {
            $action = (string) parse_url((string) $node->getAttribute('action'), PHP_URL_PATH);
            try {
                $matched = $matcher->match($action)['_route'] ?? null;
            } catch (\Throwable) {
                continue;
            }
            if ($matched === $route && strtolower($node->getAttribute('method')) === 'post') {
                $form = (new \Symfony\Component\DomCrawler\Crawler($node, $this->client->getRequest()->getUri()))->form();
                break;
            }
        }
        if ($form === null) {
            return 'the page has no form for its main action ' . $route . ' (button hidden from the reminded person?)';
        }

        $this->client->submit($form);
        $hops = [];
        for ($i = 0; $i < 5 && $this->client->getResponse()->isRedirection(); ++$i) {
            $hops[] = (string) $this->client->getResponse()->headers->get('Location');
            $this->client->followRedirect();
        }
        $status = $this->client->getResponse()->getStatusCode();
        $landed = (string) parse_url((string) $this->client->getRequest()->getUri(), PHP_URL_PATH);
        $landedRoute = '';
        try {
            $landedRoute = (string) ($this->router->match($landed)['_route'] ?? '');
        } catch (\Throwable) {
        }
        if ($status === 200 && preg_match(self::REFUSED_LANDING, $landedRoute) !== 1) {
            return null;
        }

        return sprintf('POST %s → %d%s', $route, $status, $hops !== [] ? ' via ' . implode(' → ', $hops) : '');
    }

    private function opens(User $user, RecordLink $link): ?string
    {
        $parameters = $link->parameters;
        $route = static::getContainer()->get('router')->getRouteCollection()->get($link->route);
        if ($route === null) {
            return 'route ' . $link->route . ' does not exist';
        }
        if (str_contains($route->getPath(), '{_locale}')) {
            $parameters['_locale'] ??= 'de';
        }
        $url = $this->router->generate($link->route, $parameters);
        $target = (string) parse_url($url, PHP_URL_PATH);

        $this->client->loginUser($user);
        $this->client->request('GET', $url);
        $hops = [];
        for ($i = 0; $i < 5 && $this->client->getResponse()->isRedirection(); ++$i) {
            $flashes = $this->client->getRequest()->hasSession() ? $this->client->getRequest()->getSession()->getFlashBag()->peekAll() : [];
            $hops[] = (string) $this->client->getResponse()->headers->get('Location') . ($flashes !== [] ? ' [' . json_encode($flashes, JSON_UNESCAPED_UNICODE) . ']' : '');
            $this->client->followRedirect();
        }
        $status = $this->client->getResponse()->getStatusCode();
        $landed = (string) parse_url((string) $this->client->getRequest()->getUri(), PHP_URL_PATH);
        // A page may forward to where the record is shown (TIA → filtered list); the dashboard,
        // login and MFA pages are where a refused person lands.
        $landedRoute = '';
        try {
            $landedRoute = (string) ($this->router->match($landed)['_route'] ?? '');
        } catch (\Throwable) {
        }
        // W6: a 200 that only says "module not active" is no page to act on — the source needs the
        // page's module (module: in config/deadline_types.yaml, requiredModule() in a class).
        if ($status === 200 && str_contains((string) $this->client->getResponse()->getContent(), self::MODULE_OFF_MARKER)) {
            return sprintf('GET %s → 200 but the page says its module is off', $target);
        }
        if ($status === 200 && ($landed === $target || preg_match(self::REFUSED_LANDING, $landedRoute) !== 1)) {
            return null;
        }

        $detail = '';
        if ($status >= 500) {
            preg_match('/<title>(.*?)<\/title>/s', (string) $this->client->getResponse()->getContent(), $t);
            $detail = ' ' . trim(html_entity_decode($t[1] ?? ''));
        }

        return sprintf('GET %s → %d%s%s', $target, $status, $hops !== [] ? ' via ' . implode(' → ', $hops) : '', $detail);
    }

    /** A record of the source in the organisation. */
    private function item(DeadlineSource $source, Tenant $tenant, string $actor, User $user, bool $holderActor): object
    {
        $tenant = $this->managed($tenant);
        $factory = $this->factory();
        $item = match ($source->slug()) {
            // Items that are not entities: built from the records they stand for.
            'competence_gap' => new CompetenceGap($factory->create(IsmsRegisterEntry::class, $tenant, 'dlr'), ['dlr']),
            'retention_deletion' => new UpcomingDeletion('audit_log', 1, 1, new \DateTimeImmutable('+3 days')),
            'nis2_management_training' => new ManagementTrainee($user, null, new \DateTimeImmutable('+3 days')),
            'board_unassigned' => new BoardUnassigned($tenant),
            'standing_approval' => new StandingApproval(ManagementDecisionType::cases()[0]),
            'risk_acceptance_request' => new RiskAcceptanceRequest($this->riskForApprover($factory->create(Risk::class, $tenant, 'dlr'), $actor)),
            // Duty keys, register types, suggestion kinds and workflow steps decide who is reminded: pick the actor's.
            'recurring_duty' => new RecurringDuty($tenant, $this->dutyKeyFor($actor, $tenant, $holderActor), 12, new \DateTimeImmutable('+3 days')),
            'suggestions' => $this->suggestionFor($actor, $tenant),
            'workflow' => $this->workflowInstanceFor($actor, $tenant, $factory),
            default => $factory->create($this->itemClass($source), $tenant, 'dlr'),
        };
        // Records the source takes only in a given state.
        match ($source->slug()) {
            'change_approval' => $item->setStatus(ChangeApprovalSource::PENDING[0]),
            'management_review_follow_up' => $item->setStatus(ManagementReviewStatus::Completed->value),
            'management_review_action' => $item->addReference((new ActionItemReference())->setActionItem($item)->setTenant($tenant)
                ->setRefType(ActionItemRepository::MANAGEMENT_REVIEW_REF)->setRefId((int) $this->flushed($factory->create(ManagementReview::class, $tenant, 'dlr'))->getId())),
            'fulfillment_override_review' => $item->markOverride('manual', 'dlr', new \DateTimeImmutable('-1 day'), null, new \DateTimeImmutable('-30 days')),
            'reporting_duty' => $item->setEntityType('Incident')->setEntityId((int) $this->flushed($this->complete($factory->create(Incident::class, $tenant, 'dlr')))->getId()),
            'register_review' => $item->setType($this->registerTypeFor($actor)),
            // Reviews are due only on records in force (the entries' done: expressions).
            'processing_activity_review' => $item->setStatus('published')->setNextReviewDate(new \DateTime('-1 day')),
            'dpia_review' => $item->setStatus('approved')->setNextReviewDate(new \DateTime('-1 day')),
            // Control duties are done for controls that do not apply (their done: expressions).
            'control_implementation', 'control_review', 'control_effectiveness' => $item->setApplicable(true),
            // A TIA is created under its processing activity (its page lives there).
            'tia_review' => $item->setStatus('assessed')->setNextReviewDate(new \DateTime('-1 day'))
                ->setProcessingActivity($this->flushed($factory->create(\App\Entity\ProcessingActivity::class, $tenant, 'dlr'))),
            default => null,
        };
        $this->complete($item);
        foreach ($this->em()->getUnitOfWork()->getScheduledEntityInsertions() as $new) {
            if ($new instanceof ActionItemReference) {
                $new->setTenant($tenant);
            }
        }
        if ($this->em()->getMetadataFactory()->isTransient($item::class) === false) {
            $this->em()->persist($item);
        }
        $this->em()->flush();

        return $item;
    }

    /** Fields every form and the API require (NotNull) that the mapping-based factory leaves empty. */
    private function complete(object $record): object
    {
        if ($record instanceof Incident && $record->getSeverity() === null) {
            $record->setSeverity(IncidentSeverity::cases()[0]);
        }

        return $record;
    }

    private function riskForApprover(Risk $risk, string $actor): Risk
    {
        // Default thresholds 3 / 7: residual 9 needs top management, 4 the manager-level roles.
        $high = $actor === 'ROLE_TOP_MGMT';

        return $risk->setResidualProbability($high ? 3 : 2)->setResidualImpact($high ? 3 : 2);
    }

    /** A duty the actor answers for (any duty for a holder), with its module switched on. */
    private function dutyKeyFor(string $actor, Tenant $tenant, bool $holderActor): string
    {
        // An organisation the scoped duties apply to (NIS-2 essential, DORA financial entity).
        $tenant->setNis2Classification(Tenant::NIS2_ESSENTIAL)->setDoraEntityCategory(Tenant::DORA_FINANCIAL_ENTITY);
        foreach (RecurringDutyCatalog::DUTIES as $key => $duty) {
            if (($holderActor || in_array($actor, $duty['roles'] ?? ['ROLE_ISB'], true)) && RecurringDutyCatalog::inScope($key, $tenant)) {
                $modules = static::getContainer()->get(ModuleConfigurationService::class);
                $tenant->setActiveModules(array_values($modules->resolveModuleDependencies([...($tenant->getActiveModules() ?? []), $duty['module']])['modules']));
                if (static::getContainer()->get(RecurringDutyService::class)->applies($key, $tenant)) {
                    return $key;
                }
            }
        }
        throw new \RuntimeException('no recurring duty applies with ' . $actor);
    }

    private function registerTypeFor(string $actor): RegisterType
    {
        foreach (RegisterType::cases() as $type) {
            if ($type->maintainerRole() === $actor) {
                return $type;
            }
        }

        return RegisterType::cases()[0];
    }

    private function suggestionFor(string $actor, Tenant $tenant): Suggestion
    {
        foreach (static::getContainer()->get(SuggestionQueue::class)->kinds() as $kind) {
            if (in_array($actor, $kind->decisionRoles(), true)) {
                return new Suggestion($tenant, $kind->key(), SuggestionOrigin::Import, 'dlr', 'dlr', 'dlr-' . bin2hex(random_bytes(4)));
            }
        }
        throw new \RuntimeException('no suggestion kind is decided by ' . $actor);
    }

    private function workflowInstanceFor(string $actor, Tenant $tenant, ContractRecordFactory $factory): WorkflowInstance
    {
        $workflow = $factory->create(Workflow::class, $tenant, 'dlr');
        $step = $factory->create(WorkflowStep::class, $tenant, 'dlr');
        $step->setWorkflow($workflow)->setApproverRole($actor);
        $instance = $factory->create(WorkflowInstance::class, $tenant, 'dlr');
        $instance->setWorkflow($workflow)->setCurrentStep($step);

        return $instance;
    }

    private function flushed(object $record): object
    {
        $this->em()->flush();

        return $record;
    }

    /** @return class-string */
    private function itemClass(DeadlineSource $source): string
    {
        if ($source instanceof ConfiguredDeadlineSource) {
            return $source->definition->entity;
        }
        $reflection = new \ReflectionClass($source);
        if ($source instanceof EntityDeadlineSource) {
            return (string) $reflection->getMethod('entityClass')->invoke($source);
        }
        $method = $reflection->getMethod('supports');
        if (preg_match('/instanceof\s+\\\\?([A-Za-z_\\\\]+)/', $this->body($method), $m) !== 1) {
            throw new \RuntimeException('supports() names no class');
        }
        foreach ($this->imports($reflection) as $alias => $fqcn) {
            if ($alias === $m[1]) {
                return $fqcn;
            }
        }

        return str_contains($m[1], '\\') ? $m[1] : $reflection->getNamespaceName() . '\\' . $m[1];
    }

    /** The current manager: a request resets it, so entities of earlier steps are fetched again. */
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function factory(): ContractRecordFactory
    {
        return new ContractRecordFactory($this->em());
    }

    private function managed(Tenant $tenant): Tenant
    {
        return $this->em()->contains($tenant) ? $tenant : $this->em()->find(Tenant::class, $tenant->getId());
    }

    /**
     * A fresh organisation; with a source, only the modules that source needs
     * are on — the reminder shows up exactly then, so its link must work then.
     */
    private function tenant(?DeadlineSource $source = null): Tenant
    {
        $id = bin2hex(random_bytes(5));
        $tenant = (new Tenant())->setName('DLR ' . $id)->setCode('dlr_' . $id);
        if ($source !== null) {
            $modules = static::getContainer()->get(ModuleConfigurationService::class);
            $tenant->setActiveModules(array_values($modules->resolveModuleDependencies(array_filter([$source->requiredModule()]))['modules']));
        }
        $this->em()->persist($tenant);
        $this->em()->flush();

        return $tenant;
    }

    private function user(string $role, Tenant $tenant): User
    {
        $tenant = $this->managed($tenant);
        $id = bin2hex(random_bytes(5));
        $user = (new User())
            ->setEmail('dlr-' . $id . '@example.test')
            ->setFirstName('Dora')
            ->setLastName('Reminded')
            ->setRoles([$role])
            ->setPassword('hashed')
            ->setTenant($tenant)
            ->setIsActive(true);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Names the account on the record at the path (User, or a Person linked to it). */
    private function place(object $item, string $path, User $user, Tenant $tenant): bool
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        if ($accessor->isReadable($item, $path) && $accessor->getValue($item, $path) === $user) {
            return true; // built with the account already (value objects)
        }
        foreach ([fn (): User => $user, fn (): Person => $this->person($user, $tenant)] as $value) {
            try {
                $accessor->setValue($item, $path, $value());

                return true;
            } catch (\Throwable) {
                // wrong type: try the Person
            }
        }

        return false;
    }

    private function clear(object $item, string $path): void
    {
        if (!PropertyAccess::createPropertyAccessor()->isWritable($item, $path)) {
            return;
        }
        try {
            PropertyAccess::createPropertyAccessor()->setValue($item, $path, null);
        } catch (\Throwable) {
            // required or collection: left as built
        }
    }

    private function person(User $user, Tenant $tenant): Person
    {
        $person = (new Person())->setFullName('Dora Reminded')->setLinkedUser($user)->setTenant($this->managed($tenant));
        $this->em()->persist($person);

        return $person;
    }

    /**
     * @return array{0: list<string>, 1: list<string>} fallback roles, holder property paths
     */
    private function describe(DeadlineSource $source): array
    {
        if ($source instanceof ConfiguredDeadlineSource) {
            $d = $source->definition;

            return [
                array_values(array_filter($d->roles, static fn (string $r): bool => str_starts_with($r, 'ROLE_'))),
                array_values($d->holders),
            ];
        }

        $class = new \ReflectionClass($source);
        $responsible = $this->body($class->getMethod('responsibleOf'));

        // Role literals compared against (`$role === 'ROLE_MANAGER'`) name no recipient.
        preg_match_all("/'(ROLE_[A-Z_]+)'/", (string) preg_replace("/[!=]==\\s*'ROLE_[A-Z_]+'/", '', $responsible), $roles);
        $roleList = $roles[1];
        // Roles kept in a class constant the method names (self::FOO).
        preg_match_all('/self::([A-Z_]+)/', $responsible, $constants);
        foreach ($constants[1] as $constant) {
            $value = $class->hasConstant($constant) ? $class->getConstant($constant) : null;
            foreach (is_array($value) ? $value : [$value] as $role) {
                if (is_string($role) && str_starts_with($role, 'ROLE_')) {
                    $roleList[] = $role;
                }
            }
        }
        if (isset(self::DYNAMIC_ROLES[$class->getName()])) {
            $roleList = [...$roleList, ...$this->{self::DYNAMIC_ROLES[$class->getName()]}()];
        }

        // Holders: the record paths passed to Responsibility::holders(…) — $item->getFoo(), $item->foo, $item->role->getOwnerUser().
        $holderPaths = [];
        if (preg_match('/holders\((.*?)\)\s*(->|;)/s', $responsible, $h) === 1) {
            preg_match_all('/\$item((?:->\w+(?:\(\))?)+)/', $h[1], $chains);
            foreach ($chains[1] as $chain) {
                preg_match_all('/->(\w+)(\(\))?/', $chain, $parts, PREG_SET_ORDER);
                $holderPaths[] = implode('.', array_map(
                    static fn (array $p): string => isset($p[2]) && str_starts_with($p[1], 'get') ? lcfirst(substr($p[1], 3)) : $p[1],
                    $parts,
                ));
            }
        }

        return [array_values(array_unique($roleList)), $holderPaths];
    }

    private function body(\ReflectionMethod $method): string
    {
        $lines = file((string) $method->getFileName()) ?: [];

        return implode('', array_slice($lines, (int) $method->getStartLine() - 1, (int) $method->getEndLine() - (int) $method->getStartLine() + 1));
    }

    /** @return array<string, string> alias => FQCN of the file's use statements */
    private function imports(\ReflectionClass $class): array
    {
        preg_match_all('/^use\s+([A-Za-z_\\\\]+)(?:\s+as\s+(\w+))?;/m', (string) file_get_contents((string) $class->getFileName()), $m, PREG_SET_ORDER);
        $imports = [];
        foreach ($m as $use) {
            $imports[$use[2] ?? substr((string) strrchr('\\' . $use[1], '\\'), 1)] = $use[1];
        }

        return $imports;
    }

    /** @return list<string> */
    private function registerRoles(): array
    {
        return array_values(array_unique(array_map(static fn (RegisterType $t): string => $t->maintainerRole(), RegisterType::cases())));
    }

    /**
     * The manager level goes to MANAGER_LEVEL_ROLES (read from the constant), the
     * executive level to its approver role.
     *
     * @return list<string>
     */
    private function approvalLevelRoles(): array
    {
        return [RiskApprovalConfigView::approverRole('executive')];
    }

    /** @return list<string> */
    private function suggestionRoles(): array
    {
        $roles = [];
        foreach (static::getContainer()->get(SuggestionQueue::class)->kinds() as $kind) {
            $roles = [...$roles, ...$kind->decisionRoles()];
        }

        return array_values(array_unique($roles));
    }

    /** @return list<string> */
    private function recurringDutyRoles(): array
    {
        $roles = [];
        foreach (RecurringDutyCatalog::DUTIES as $duty) {
            $roles = [...$roles, ...($duty['roles'] ?? [])];
        }

        return array_values(array_unique($roles));
    }

    /** @return list<string> approver roles of the regulatory workflow steps */
    private function workflowApproverRoles(): array
    {
        $roles = [];
        foreach (glob(dirname(__DIR__, 3) . '/config/workflows/regulatory/*.yaml') ?: [] as $file) {
            preg_match_all('/approver_role:\s*[\'"]?(ROLE_[A-Z_]+)/', (string) file_get_contents($file), $m);
            $roles = [...$roles, ...$m[1]];
        }

        return array_values(array_unique($roles));
    }
}
