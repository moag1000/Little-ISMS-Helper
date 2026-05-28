<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\AuditProgram;
use App\Entity\ComplianceFramework;
use App\Entity\Person;
use App\Entity\User;
use App\Form\SectionMapInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * AuditProgramType — S4 Foundation P-2 SectionPolicy.
 *
 * Sections: basic_info, responsibility, ressourcen, status.
 * Status field is disabled + mapped=false (Pattern A — lifecycle-managed).
 */
final class AuditProgramType extends AbstractType implements SectionMapInterface
{
    public static function getSectionMap(): array
    {
        return [
            'basic_info'     => ['name', 'year', 'scope', 'objectives', 'frameworks'],
            'responsibility' => ['responsiblePerson', 'responsiblePersonRef'],
            'ressourcen'     => ['budget', 'notes'],
            'status'         => ['status'],
        ];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label'       => 'audit_program.field.name',
                'attr'        => ['maxlength' => 200, 'placeholder' => 'audit_program.placeholder.name'],
                'constraints' => [
                    new NotBlank(message: 'audit_program.validation.name_required'),
                ],
                'help'        => 'audit_program.help.name',
            ])
            ->add('year', IntegerType::class, [
                'label'       => 'audit_program.field.year',
                'attr'        => ['min' => 2000, 'max' => 2100, 'placeholder' => 'audit_program.placeholder.year'],
                'constraints' => [
                    new NotBlank(message: 'audit_program.validation.year_required'),
                    new Range(min: 2000, max: 2100),
                ],
                'help'        => 'audit_program.help.year',
            ])
            ->add('scope', TextareaType::class, [
                'label'    => 'audit_program.field.scope',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'audit_program.placeholder.scope'],
                'help'     => 'audit_program.help.scope',
            ])
            ->add('objectives', TextareaType::class, [
                'label'    => 'audit_program.field.objectives',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'audit_program.placeholder.objectives'],
                'help'     => 'audit_program.help.objectives',
            ])
            ->add('frameworks', EntityType::class, [
                'label'          => 'audit_program.field.frameworks',
                'class'          => ComplianceFramework::class,
                'choice_label'   => fn(ComplianceFramework $f): string => $f->getName() ?? '',
                'multiple'       => true,
                'expanded'       => false,
                'required'       => false,
                'by_reference'   => false,
                'attr'           => ['data-controller' => 'tom-select'],
                'help'           => 'audit_program.help.frameworks',
            ])
            // ── Responsibility section (Pattern A dual-state — ISO 19011 §5.4.4) ──
            ->add('responsiblePerson', EntityType::class, [
                'label'        => 'audit_program.field.responsible_person',
                'class'        => User::class,
                'choice_label' => fn(User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
                'placeholder'  => 'audit_program.placeholder.responsible_person',
                'required'     => false,
                'help'         => 'audit_program.help.responsible_person',
            ])
            ->add('responsiblePersonRef', EntityType::class, [
                'label'        => 'audit_program.field.responsible_person_ref',
                'class'        => Person::class,
                'choice_label' => fn(Person $p): string => $p->getFullName() ?? '',
                'placeholder'  => 'audit_program.placeholder.responsible_person_ref',
                'required'     => false,
                'help'         => 'audit_program.help.responsible_person_ref',
            ])
            // ── Resources section (ISO 19011 §5.4.5) ──────────────────────────────
            ->add('budget', MoneyType::class, [
                'label'      => 'audit_program.field.budget',
                'currency'   => 'EUR',
                'required'   => false,
                'scale'      => 2,
                'help'       => 'audit_program.help.budget',
            ])
            ->add('notes', TextareaType::class, [
                'label'    => 'audit_program.field.notes',
                'required' => false,
                'attr'     => ['rows' => 4, 'placeholder' => 'audit_program.placeholder.notes'],
            ])
            // ── Status — lifecycle-managed, read-only (Pattern A) ──────────────────
            ->add('status', ChoiceType::class, [
                'label'    => 'audit_program.field.status',
                'choices'  => [
                    'audit_program.status.draft'     => 'draft',
                    'audit_program.status.approved'  => 'approved',
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
