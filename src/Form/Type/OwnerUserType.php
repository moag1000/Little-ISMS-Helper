<?php

declare(strict_types=1);

namespace App\Form\Type;

use App\Entity\Tenant;
use App\Entity\User;
use App\Form\ChoiceList\TenantUserChoiceLoader;
use App\Security\RecordTenant;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\Options;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Tenant-scoped User select — the ONE place that decides which users a
 * record's user field (owner, assignee, approver, contact, participants ...)
 * offers and accepts. Use it instead of `EntityType` + `User::class`.
 *
 * The Doctrine TenantFilter deliberately skips `User`, so a plain EntityType
 * lists (and accepts) users of every organisation. This type offers only the
 * users of the bound RECORD's organisation (the nearest parent form whose data
 * has getTenant()/getTenantId()); for a record without tenant (new record) the current
 * tenant; without either the list is empty, never "all users". A tenantless
 * super-admin editing a record of tenant B therefore sees tenant-B users.
 *
 * Options: everything EntityType takes (`multiple` included) except
 * class / query_builder / choice_loader, plus `tenant` to pin the scope.
 * Note: `expanded => true` loads the choices while the form is built, i.e.
 * before the record is known — then only the `tenant` option or the current
 * tenant applies.
 */
final class OwnerUserType extends AbstractType
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $loader = $options['choice_loader'];
        if (!$loader instanceof TenantUserChoiceLoader) {
            return;
        }
        // The parent record is already set when a child receives its data
        // (Form::setData maps children after storing its own model data).
        $builder->addEventListener(
            FormEvents::PRE_SET_DATA,
            static function (FormEvent $event) use ($loader): void {
                $loader->setRecordTenant(self::recordTenant($event->getForm()));
            },
        );
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => User::class,
            // Pins the scope to this tenant; null = the bound record's tenant.
            'tenant' => null,
            'choice_loader' => fn (Options $options): TenantUserChoiceLoader => new TenantUserChoiceLoader(
                $this->entityManager,
                $options['id_reader'],
                $this->tenantContext,
                $options['tenant'],
            ),
        ]);
        $resolver->setAllowedTypes('tenant', ['null', Tenant::class]);
        $resolver->setAllowedValues('class', [User::class]);
    }

    public function getParent(): string
    {
        return EntityType::class;
    }

    /**
     * Organisation of the nearest ancestor form whose data is a tenant-owned
     * record (null for a record without tenant yet, e.g. a new one).
     */
    private static function recordTenant(FormInterface $form): Tenant|int|null
    {
        for ($parent = $form->getParent(); $parent !== null; $parent = $parent->getParent()) {
            $data = $parent->getData();
            if (RecordTenant::isTenantOwned($data)) {
                return RecordTenant::of($data);
            }
        }

        return null;
    }
}
