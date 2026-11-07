<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Workflow approvers on roles that exist.
 *
 * 1. Steps waited on roles nobody can hold (ROLE_RISK_OWNER, ROLE_CEO,
 *    ROLE_CFO, ROLE_CRISIS_MANAGER, ROLE_DATA_OWNER, ROLE_IT_MANAGER,
 *    ROLE_SECURITY_ANALYST, ROLE_SECURITY_MANAGER, ROLE_INCIDENT_MANAGER):
 *    nobody was notified and only admins could approve. They now name the
 *    persona role that does the work; executive sign-off goes to
 *    ROLE_TOP_MGMT (the management role).
 * 2. Approval steps on ROLE_USER let every signed-in user approve (CAPA root
 *    cause, document review, DSR processing, …). They now need the
 *    responsible persona.
 *
 * Only rows that still carry the shipped value are changed; a step an admin
 * re-assigned keeps its role. Same mapping as config/workflows/regulatory/.
 */
final class Version20261107110000_WorkflowApproverRolesExist extends AbstractMigration
{
    /** @var array<string, string> */
    private const array ROLE_RENAMES = [
        'ROLE_RISK_OWNER' => 'ROLE_RISK_MANAGER',
        'ROLE_CEO' => 'ROLE_TOP_MGMT',
        'ROLE_CFO' => 'ROLE_TOP_MGMT',
        'ROLE_CRISIS_MANAGER' => 'ROLE_BCM_OFFICER',
        'ROLE_DATA_OWNER' => 'ROLE_MANAGER',
        'ROLE_IT_MANAGER' => 'ROLE_MANAGER',
        'ROLE_SECURITY_ANALYST' => 'ROLE_ISB',
        'ROLE_SECURITY_MANAGER' => 'ROLE_ISB',
        'ROLE_INCIDENT_MANAGER' => 'ROLE_ISB',
    ];

    /** @var list<array{string, string}> [step name, role] for steps shipped with ROLE_USER */
    private const array ROLE_USER_STEPS = [
        ['Crisis Team Notification', 'ROLE_BCM_OFFICER'],
        ['Implementation', 'ROLE_MANAGER'],
        ['Initial Security Assessment', 'ROLE_MANAGER'],
        ['Request Processing', 'ROLE_DPO'],
        ['Review Required', 'ROLE_MANAGER'],
        ['Revision Draft', 'ROLE_MANAGER'],
        ['Risk Owner Certification', 'ROLE_RISK_MANAGER'],
        ['Root Cause Analysis', 'ROLE_MANAGER'],
        ['Training Completion', 'ROLE_MANAGER'],
        ['prepared', 'ROLE_MANAGER'],
    ];

    public function getDescription(): string
    {
        return 'Workflow steps: approver roles that exist, no approval step open to every user';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ROLE_RENAMES as $old => $new) {
            $this->addSql(
                'UPDATE workflow_steps SET approver_role = :new WHERE approver_role = :old',
                ['new' => $new, 'old' => $old],
            );
        }
        foreach (self::ROLE_USER_STEPS as [$name, $role]) {
            $this->addSql(
                "UPDATE workflow_steps SET approver_role = :role WHERE name = :name AND approver_role = 'ROLE_USER'",
                ['role' => $role, 'name' => $name],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('The old values pointed at roles nobody can hold.');
    }
}
