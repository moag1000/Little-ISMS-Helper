<?php

declare(strict_types=1);

namespace App\Form\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationRule;
use App\Entity\Tenant;
use App\Form\Trait\ModuleAwareFormTrait;
use App\Service\ModuleConfigurationService;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Form type for creating and editing NotificationRule entities.
 *
 * Channels are filtered to those belonging to the current tenant
 * (passed as form option 'tenant').
 */
final class NotificationRuleType extends AbstractType
{
    use ModuleAwareFormTrait;

    public function __construct(
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {}

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Tenant|null $tenant */
        $tenant = $options['tenant'];

        $builder
            ->add('name', TextType::class, [
                'label'    => 'notification.rule.field.name',
                'required' => true,
                'attr'     => [
                    'maxlength'   => 120,
                    'placeholder' => 'notification.rule.field.name_placeholder',
                ],
                'help'     => 'notification.rule.help.name',
            ])
            ->add('eventType', ChoiceType::class, [
                'label'                     => 'notification.rule.field.event_type',
                'required'                  => true,
                'choices'                   => [
                    'notification.event_type.data_breach_created'         => 'data_breach.created',
                    'notification.event_type.data_breach_severity_changed' => 'data_breach.severity_changed',
                    'notification.event_type.incident_created'            => 'incident.created',
                    'notification.event_type.incident_severity_high'      => 'incident.severity_high',
                    'notification.event_type.risk_created'                => 'risk.created',
                    'notification.event_type.risk_score_critical'         => 'risk.score_critical',
                    'notification.event_type.control_overdue'             => 'control.overdue',
                    'notification.event_type.control_evidence_expired'    => 'control.evidence_expired',
                    'notification.event_type.audit_finding_created'       => 'audit.finding_created',
                    'notification.event_type.document_approval_required'  => 'document.approval_required',
                ],
                'placeholder'               => 'notification.rule.field.event_type_placeholder',
                'choice_translation_domain' => 'notification',
                'help'                      => 'notification.rule.help.event_type',
            ])
            ->add('severityFilter', ChoiceType::class, [
                'label'                     => 'notification.rule.field.severity_filter',
                'required'                  => false,
                'choices'                   => [
                    'notification.severity.all'      => null,
                    'notification.severity.low'      => 'low',
                    'notification.severity.medium'   => 'medium',
                    'notification.severity.high'     => 'high',
                    'notification.severity.critical' => 'critical',
                ],
                'placeholder'               => false,
                'choice_translation_domain' => 'notification',
                'help'                      => 'notification.rule.help.severity_filter',
            ])
            ->add('channels', EntityType::class, [
                'label'         => 'notification.rule.field.channels',
                'class'         => NotificationChannel::class,
                'required'      => false,
                'multiple'      => true,
                'expanded'      => true,
                'choice_label'  => static fn (NotificationChannel $c): string => sprintf('%s (%s)', $c->getName(), $c->getType()),
                'query_builder' => static function ($repo) use ($tenant) {
                    $qb = $repo->createQueryBuilder('c')
                        ->andWhere('c.isActive = true')
                        ->orderBy('c.name', 'ASC');
                    if ($tenant !== null) {
                        $qb->andWhere('c.tenant = :tenant')
                           ->setParameter('tenant', $tenant);
                    }
                    return $qb;
                },
                'help'          => 'notification.rule.help.channels',
            ])
            ->add('conditions', CollectionType::class, [
                'label'         => 'notification.rule.field.conditions',
                'required'      => false,
                'entry_type'    => ConditionRowType::class,
                'allow_add'     => true,
                'allow_delete'  => true,
                'by_reference'  => false,
                'prototype'     => true,
                'prototype_name' => '__condition_index__',
                'attr'          => [
                    'data-controller'                             => 'notification-condition-builder',
                    'data-notification-condition-builder-prototype-value' => '__condition_index__',
                ],
                'help'          => 'notification.rule.help.conditions',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'notification.rule.field.is_active',
                'required' => false,
                'help'     => 'notification.rule.help.is_active',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => NotificationRule::class,
            'translation_domain' => 'notification',
            'tenant'             => null,
        ]);

        $resolver->setAllowedTypes('tenant', ['null', Tenant::class]);
    }
}
