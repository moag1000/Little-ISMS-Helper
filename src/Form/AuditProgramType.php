<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditProgram;
use App\Entity\User;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * AuditProgramType — S4 Foundation P-2 SectionPolicy.
 *
 * Sections: basic_info, dates, responsibility, status.
 * Status field is disabled + mapped=false (Pattern A — lifecycle-managed).
 */
final class AuditProgramType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'basic_info'     => ['name', 'description', 'objectives', 'scope', 'frequency', 'riskCategories'],
            'dates'          => ['startDate', 'endDate'],
            'responsibility' => ['programmeOwner'],
            'status'         => ['status'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'audit_program.field.name',
                'attr'        => ['maxlength' => 255, 'placeholder' => 'audit_program.placeholder.name'],
                'constraints' => [
                    new NotBlank(message: 'audit_program.validation.name_required'),
                ],
                'help'        => 'audit_program.help.name',
            ])
            ->add('description', TextareaType::class, [
                'label'    => 'audit_program.field.description',
                'required' => false,
                'attr'     => ['rows' => 3, 'placeholder' => 'audit_program.placeholder.description'],
                'help'     => 'audit_program.help.description',
            ])
            ->add('objectives', TextareaType::class, [
                'label'    => 'audit_program.field.objectives',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'audit_program.placeholder.objectives'],
                'help'     => 'audit_program.help.objectives',
            ])
            ->add('scope', TextareaType::class, [
                'label'    => 'audit_program.field.scope',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'audit_program.placeholder.scope'],
                'help'     => 'audit_program.help.scope',
            ])
            ->add('frequency', ChoiceType::class, [
                'label'    => 'audit_program.field.frequency',
                'required' => false,
                'placeholder' => 'audit_program.placeholder.frequency',
                'choices'  => [
                    'audit_program.frequency.annual'    => 'annual',
                    'audit_program.frequency.biennial'  => 'biennial',
                    'audit_program.frequency.quarterly' => 'quarterly',
                    'audit_program.frequency.monthly'   => 'monthly',
                ],
                'help'     => 'audit_program.help.frequency',
                'choice_translation_domain' => 'audit_program',
            ])
            ->add('startDate', DateType::class, [
                'label'   => 'audit_program.field.start_date',
                'widget'  => 'single_text',
                'input'   => 'datetime_immutable',
                'help'    => 'audit_program.help.start_date',
            ])
            ->add('endDate', DateType::class, [
                'label'   => 'audit_program.field.end_date',
                'widget'  => 'single_text',
                'input'   => 'datetime_immutable',
                'help'    => 'audit_program.help.end_date',
            ])
            ->add('programmeOwner', EntityType::class, [
                'label'        => 'audit_program.field.programme_owner',
                'class'        => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder'  => 'audit_program.placeholder.programme_owner',
                'required'     => false,
                'help'         => 'audit_program.help.programme_owner',
            ])
            // ── Status — lifecycle-managed, read-only (Pattern A) ──────────────────
            ->add('status', ChoiceType::class, [
                'label'    => 'audit_program.field.status',
                'choices'  => [
                    'audit_program.status.planning'  => 'planning',
                    'audit_program.status.active'    => 'active',
                    'audit_program.status.completed' => 'completed',
                    'audit_program.status.archived'  => 'archived',
                ],
                'disabled' => true,
                'mapped'   => false,
                'required' => false,
                'help'     => 'audit_program.help.status_readonly',
                'choice_translation_domain' => 'audit_program',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'         => AuditProgram::class,
            'translation_domain' => 'audit_program',
        ]);
    }
}
