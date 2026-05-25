<?php

declare(strict_types=1);

namespace App\Form\Admin;

use App\Entity\Department;
use App\Repository\DepartmentRepository;
use App\Service\TenantContext;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin CRUD form for Department master data (S18 B3).
 */
final class DepartmentType extends AbstractType
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Department|null $current */
        $current = $builder->getData();
        $currentId = $current?->getId();

        $builder
            ->add('name', TextType::class, [
                'label' => 'department.field.name',
                'help' => 'department.help.name',
                'required' => true,
            ])
            ->add('code', TextType::class, [
                'label' => 'department.field.code',
                'help' => 'department.help.code',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'department.field.description',
                'required' => false,
                'attr' => ['rows' => 3],
            ])
            ->add('parent', EntityType::class, [
                'class' => Department::class,
                'label' => 'department.field.parent',
                'help' => 'department.help.parent',
                'required' => false,
                'placeholder' => '—',
                'choice_label' => function (Department $d): string {
                    return $d->getCode() !== null && $d->getCode() !== ''
                        ? sprintf('%s (%s)', (string) $d->getName(), $d->getCode())
                        : (string) $d->getName();
                },
                'query_builder' => function (DepartmentRepository $repo) use ($currentId) {
                    $tenant = $this->tenantContext->getCurrentTenant();
                    $queryBuilder = $repo->createQueryBuilder('d')
                        ->orderBy('d.name', 'ASC');
                    if ($tenant !== null) {
                        $queryBuilder->andWhere('d.tenant = :tenant')->setParameter('tenant', $tenant);
                    }
                    if ($currentId !== null) {
                        $queryBuilder->andWhere('d.id != :self')->setParameter('self', $currentId);
                    }
                    return $queryBuilder;
                },
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'department.field.is_active',
                'help' => 'department.help.is_active',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Department::class,
            'translation_domain' => 'department',
        ]);
    }
}
