<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\RoadmapGroup;
use App\Entity\Team;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class RoadmapGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'    => 'planning.group.field.name',
                'required' => true,
                'attr'     => ['maxlength' => 255],
            ])
            ->add('sortOrder', IntegerType::class, [
                'label'    => 'planning.group.field.sort_order',
                'required' => true,
                'attr'     => ['min' => 0],
            ])
            ->add('colorToken', TextType::class, [
                'label'    => 'planning.group.field.color_token',
                'required' => false,
                'attr'     => ['maxlength' => 40],
            ])
            ->add('icon', TextType::class, [
                'label'    => 'planning.group.field.icon',
                'required' => false,
                'attr'     => ['maxlength' => 40],
            ])
            ->add('ismsDomain', TextType::class, [
                'label'    => 'planning.group.field.isms_domain',
                'required' => false,
                'attr'     => ['maxlength' => 60],
            ])
            ->add('defaultTeam', EntityType::class, [
                'label'        => 'planning.group.field.default_team',
                'class'        => Team::class,
                'choice_label' => 'name',
                'required'     => false,
                'placeholder'  => 'planning.group.field.default_team_placeholder',
            ])
            ->add('defaultVisibility', ChoiceType::class, [
                'label'                     => 'planning.group.field.default_visibility',
                'required'                  => true,
                'choices'                   => [
                    'planning.visibility.all'  => 'all',
                    'planning.visibility.team' => 'team',
                ],
                'choice_translation_domain' => 'planning',
            ])
            ->add('isActive', CheckboxType::class, [
                'label'    => 'planning.group.field.is_active',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => RoadmapGroup::class,
            'translation_domain' => 'planning',
        ]);
    }
}
