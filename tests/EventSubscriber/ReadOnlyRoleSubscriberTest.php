<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\ReadOnlyRoleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

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
        yield 'supplier form' => ['app_supplier_edit', 'GET', true];
        yield 'supplier list' => ['app_supplier_index', 'GET', false];
        yield 'comment' => ['app_comment_post', 'POST', false];
        yield 'profile' => ['app_profile_edit', 'POST', false];
        yield 'workflow decision' => ['app_workflow_instance_approve', 'POST', false];
        yield 'workflow cancel' => ['app_workflow_instance_cancel', 'POST', true];
        yield 'bulk export' => ['app_risk_bulk_export', 'POST', false];
        yield 'export job' => ['app_risk_export_dispatch', 'POST', false];
        yield 'escalation preview' => ['app_incident_escalation_preview', 'POST', false];
        yield 'policy acknowledgement' => ['app_policy_ack_acknowledge', 'POST', false];
    }

    #[Test]
    #[DataProvider('routes')]
    public function restrictsWritesAndFormsOutsideTheReviewerAllowList(string $route, string $method, bool $restricted): void
    {
        $subscriber = new ReadOnlyRoleSubscriber($this->createStub(Security::class));

        self::assertSame($restricted, $subscriber->isRestricted($route, $method));
    }
}
