<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\ActionItem;
use App\Entity\Document;
use App\Entity\Person;
use App\Entity\RoadmapTask;
use App\Entity\Team;
use App\Entity\User;
use App\Repository\PlanningSettingsRepository;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * ActionItem form (Maßnahmenplanung).
 *
 * Status is intentionally excluded — status transitions are managed exclusively
 * via ActionItemStatusService::transition(). Origin is system-managed. The scope
 * (Geltungsbereich) choices are drawn from the tenant's PlanningSettings list.
 * Implements SectionMapInterface per S4 Foundation P-2.
 */
final class ActionItemType extends AbstractType implements SectionMapInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PlanningSettingsRepository $settingsRepository,
    ) {
    }

    public static function getSectionMap(): array
    {
        return [
            'overview'       => ['title', 'roadmapTask', 'scopes'],
            'responsibility' => ['responsibleUser', 'responsiblePerson', 'teams'],
            'schedule'       => ['dueDate', 'plannedEffortPt', 'recurrenceMonths'],
            'evidence'       => ['evidenceDocument'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $scopeChoices = [];
        if ($tenant !== null) {
            $settings = $this->settingsRepository->findForTenant($tenant);
            foreach ($settings?->getScopes() ?? [] as $scope) {
                $scopeChoices[$scope] = $scope;
            }
        }

        $builder
            ->add('title', TextType::class, [
                'label'    => 'planning.action_item.field.title',
                'required' => true,
                'attr'     => ['maxlength' => 255],
            ])
            ->add('roadmapTask', EntityType::class, [
                'label'        => 'planning.action_item.field.roadmap_task',
                'class'        => RoadmapTask::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'planning.action_item.field.roadmap_task_placeholder',
            ])
            ->add('responsibleUser', EntityType::class, [
                'label'        => 'planning.action_item.field.responsible_user',
                'class'        => User::class,
                'choice_label' => 'email',
                'required'     => false,
                'placeholder'  => 'planning.action_item.field.responsible_user_placeholder',
            ])
            ->add('responsiblePerson', EntityType::class, [
                'label'        => 'planning.action_item.field.responsible_person',
                'class'        => Person::class,
                'choice_label' => 'fullName',
                'required'     => false,
                'placeholder'  => 'planning.action_item.field.responsible_person_placeholder',
            ])
            ->add('teams', EntityType::class, [
                'label'        => 'planning.action_item.field.teams',
                'class'        => Team::class,
                'choice_label' => 'name',
                'multiple'     => true,
                'required'     => false,
                'attr'         => ['data-controller' => 'tom-select'],
            ])
            ->add('dueDate', DateType::class, [
                'label'    => 'planning.action_item.field.due_date',
                'widget'   => 'single_text',
                'input'    => 'datetime_immutable',
                'required' => true,
            ])
            ->add('plannedEffortPt', NumberType::class, [
                'label'    => 'planning.action_item.field.planned_effort_pt',
                'scale'    => 1,
                'required' => false,
                'attr'     => ['min' => 0, 'step' => '0.1'],
            ])
            ->add('evidenceDocument', EntityType::class, [
                'label'        => 'planning.action_item.field.evidence_document',
                'class'        => Document::class,
                'choice_label' => 'originalFilename',
                'required'     => false,
                'placeholder'  => 'planning.action_item.field.evidence_document_placeholder',
            ])
            ->add('recurrenceMonths', IntegerType::class, [
                'label'    => 'planning.action_item.field.recurrence_months',
                'required' => false,
                'attr'     => ['min' => 1],
            ])
            ->add('scopes', ChoiceType::class, [
                'label'        => 'planning.action_item.field.scopes',
                'choices'      => $scopeChoices,
                'multiple'     => true,
                'required'     => false,
                'placeholder'  => false,
                'attr'         => ['data-controller' => 'tom-select'],
                'help'         => 'planning.action_item.field.scopes_help',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => ActionItem::class,
            'translation_domain' => 'planning',
        ]);
    }
}
