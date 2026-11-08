<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ReadOnlyRoleSubscriber;
use App\Security\Voter\RecordResponsibleVoter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ReadOnlyRoleSubscriberTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function routes(): iterable
    {
        yield 'risk form submit' => ['app_risk_new', 'POST', true];
        yield 'risk form open' => ['app_risk_new', 'GET', true];
        yield 'risk edit open' => ['app_risk_edit', 'GET', true];
        yield 'supplier clone' => ['app_supplier_clone', 'POST', true];
        yield 'lifecycle transition' => ['app_lifecycle_transition', 'POST', true];
        yield 'risk list' => ['app_risk_index', 'GET', false];
        yield 'risk detail' => ['app_risk_show', 'GET', false];
        yield 'audit checklist' => ['app_audit_checklist_save', 'POST', false];
        yield 'audit finding form' => ['app_audit_finding_new', 'GET', false];
        yield 'audit report submit' => ['app_audit_submit_report', 'POST', false];
        yield 'audit record form' => ['app_audit_new', 'GET', true];
        yield 'audit record edit' => ['app_audit_edit', 'POST', true];
        yield 'audit record clone' => ['app_audit_clone', 'POST', true];
        yield 'audit record delete' => ['app_audit_delete', 'POST', true];
        yield 'audit close' => ['app_audit_close', 'POST', true];
        yield 'AVV picker save' => ['app_processing_activity_avv_picker', 'POST', true];
        yield 'supplier form' => ['app_supplier_edit', 'GET', true];
        yield 'supplier list' => ['app_supplier_index', 'GET', false];
        yield 'comment' => ['app_comment_post', 'POST', false];
        yield 'profile' => ['app_profile_edit', 'POST', false];
        yield 'workflow decision' => ['app_workflow_instance_approve', 'POST', false];
        yield 'risk acceptance decision' => ['app_risk_approve_acceptance', 'POST', false];
        yield 'workflow cancel' => ['app_workflow_instance_cancel', 'POST', true];
        yield 'bulk export' => ['app_risk_bulk_export', 'POST', false];
        yield 'export job' => ['app_risk_export_dispatch', 'POST', false];
        yield 'escalation preview' => ['app_incident_escalation_preview', 'POST', false];
        yield 'policy acknowledgement' => ['app_policy_ack_acknowledge', 'POST', false];
        yield 'api write' => ['_api_/risks/{id}{._format}_put', 'PUT', true];
        yield 'api create' => ['_api_/internal_audits{._format}_post', 'POST', true];
        yield 'api delete' => ['_api_/assets/{id}{._format}_delete', 'DELETE', true];
        yield 'api read' => ['_api_/risks/{id}{._format}_get', 'GET', false];
        yield 'api collection' => ['_api_/risks{._format}_get_collection', 'GET', false];
        yield 'profiler' => ['_wdt', 'GET', false];
    }

    #[Test]
    #[DataProvider('routes')]
    public function restrictsWritesAndFormsOutsideTheReviewerAllowList(string $route, string $method, bool $restricted): void
    {
        $subscriber = new ReadOnlyRoleSubscriber($this->createStub(Security::class));

        self::assertSame($restricted, $subscriber->isRestricted($route, $method));
    }

    #[Test]
    public function anApiWriteInASubRequestIsRefusedForReviewers(): void
    {
        // The assistant connection (MCP) runs API operations as sub-requests of POST /mcp.
        $event = $this->event('_api_/risks{._format}_post', 'POST', HttpKernelInterface::SUB_REQUEST);
        $this->subscriber(reviewer: true)->onController($event);

        $response = ($event->getController())();
        self::assertInstanceOf(Response::class, $response);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function otherSubRequestsAndManagersAreLeftAlone(): void
    {
        $fragment = $this->event('app_risk_new', 'POST', HttpKernelInterface::SUB_REQUEST);
        $this->subscriber(reviewer: true)->onController($fragment);
        self::assertSame('original', ($fragment->getController())());

        $manager = $this->event('_api_/risks{._format}_post', 'POST', HttpKernelInterface::SUB_REQUEST);
        $this->subscriber(reviewer: false)->onController($manager);
        self::assertSame('original', ($manager->getController())());
    }

    /** W1: an action guarded by "role OR responsible" lets the responsible reviewer through; the voter decides. */
    #[Test]
    public function aRoleOrResponsibleActionIsLeftToTheVoter(): void
    {
        $responsible = $this->event('app_planning_action_item_edit', 'POST', HttpKernelInterface::MAIN_REQUEST, [new ResponsibleFixture(), 'act']);
        $this->subscriber(reviewer: true)->onController($responsible);
        self::assertSame('acted', ($responsible->getController())());

        $managerOnly = $this->event('app_planning_action_item_edit', 'POST', HttpKernelInterface::MAIN_REQUEST, [new ResponsibleFixture(), 'managerOnly']);
        $this->subscriber(reviewer: true)->onController($managerOnly);
        self::assertNotSame('acted', ($managerOnly->getController())());
    }

    private function subscriber(bool $reviewer): ReadOnlyRoleSubscriber
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new InMemoryUser('reviewer', null));
        $security->method('isGranted')->willReturnCallback(
            static fn (string $attribute): bool => $attribute === 'ROLE_AUDITOR' || ($attribute === 'ROLE_MANAGER' && !$reviewer),
        );

        return new ReadOnlyRoleSubscriber($security);
    }

    private function event(string $route, string $method, int $type, ?callable $controller = null): ControllerEvent
    {
        $request = Request::create('/api/risks', $method);
        $request->attributes->set('_route', $route);
        $request->headers->set('Accept', 'application/ld+json');

        return new ControllerEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller ?? static fn (): string => 'original',
            $request,
            $type,
        );
    }
}

/** Controller fixture: one "role OR responsible" action, one manager-only action. */
final class ResponsibleFixture
{
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'record')]
    public function act(): string
    {
        return 'acted';
    }

    #[IsGranted('ROLE_MANAGER')]
    public function managerOnly(): string
    {
        return 'acted';
    }
}
