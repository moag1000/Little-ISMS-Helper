<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RoadmapGroup;
use App\Entity\RoadmapTask;
use App\Entity\Team;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RoadmapTaskType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'overview'   => ['name', 'group', 'ismsDomain'],
            'effort'     => ['defaultPtPerWeek', 'recurring', 'isReactiveReservation'],
            'visibility' => ['visibility', 'visibleTeams', 'defaultTeam'],
            'meta'       => ['isActive'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'planning.task.field.name',
                'required' => true,
                'attr'     => ['maxlength' => 255],
            ])
            ->add('group', EntityType::class, [
                'label'        => 'planning.task.field.group',
                'class'        => RoadmapGroup::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'planning.task.field.group_placeholder',
            ])
            ->add('ismsDomain', TextType::class, [
                'label'    => 'planning.task.field.isms_domain',
                'required' => false,
                'attr'     => ['maxlength' => 60],
            ])
            ->add('defaultPtPerWeek', NumberType::class, [
                'label'    => 'planning.task.field.default_pt_per_week',
                'scale'    => 1,
                'required' => false,
                'attr'     => ['min' => 0, 'step' => '0.1'],
            ])
            ->add('recurring', CheckboxType::class, [
                'label'    => 'planning.task.field.recurring',
                'required' => false,
            ])
            ->add('isReactiveReservation', CheckboxType::class, [
                'label'    => 'planning.task.field.is_reactive_reservation',
                'required' => false,
            ])
            ->add('visibility', ChoiceType::class, [
                'label'                     => 'planning.task.field.visibility',
                'required'                  => true,
                'choices'                   => [
                    'planning.visibility.all'  => 'all',
                    'planning.visibility.team' => 'team',
                ],
                'choice_translation_domain' => 'planning',
            ])
            ->add('visibleTeams', EntityType::class, [
                'label'        => 'planning.task.field.visible_teams',
                'class'        => Team::class,
                'choice_label' => 'name',
                'multiple'     => true,
                'required'     => false,
                'attr'         => ['data-controller' => 'tom-select'],
            ])
            ->add('defaultTeam', EntityType::class, [
                'label'        => 'planning.task.field.default_team',
                'class'        => Team::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'planning.task.field.default_team_placeholder',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'planning.task.field.is_active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RoadmapTask::class,
            'translation_domain' => 'planning',
        ]);
    }
}
