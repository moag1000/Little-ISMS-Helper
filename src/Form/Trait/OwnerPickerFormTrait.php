<?php

declare(strict_types=1);

namespace App\Form\Trait;

use App\Entity\Person;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * OwnerPickerFormTrait — audit-s4 P-1.
 *
 * Unifies the recurring "Pattern A Dual-State Owner" cluster (User +
 * Person + optional Deputies + optional Legacy-Freetext) into a single
 * helper-call so that 9+ FormTypes stop hand-rolling the same 3-5
 * EntityType/TextType permutations.
 *
 * Pattern A Dual-State lives on the Entity layer (effective<Owner>
 * accessor + Backfill-Migration, already shipped). This trait stays
 * UI-only — no schema change, no entity touch.
 *
 * Property-names differ per entity (Asset uses ownerUser/ownerPerson,
 * Risk uses riskOwner/riskOwnerPerson, AuditFinding uses
 * assignedTo/assignedPerson, Incident has reportedByUser/...). The
 * trait therefore parametrises the property-paths.
 *
 * Usage:
 *
 *   class AssetType extends AbstractType {
 *       use OwnerPickerFormTrait;
 *
 *       public function buildForm(...): void {
 *           $this->addOwnerPicker($builder, [
 *               'user_field'      => 'ownerUser',
 *               'person_field'    => 'ownerPerson',
 *               'deputies_field'  => 'ownerDeputyPersons',
 *               'legacy_field'    => 'owner',
 *               'translation_prefix' => 'asset',
 *           ]);
 *       }
 *   }
 *
 * The companion Twig macro
 * `templates/_components/_fa_owner_picker.html.twig` renders the
 * children compactly with Aurora framing + cross-disable hint.
 */
trait OwnerPickerFormTrait
{
    /**
     * Adds the 2-4 owner-cluster form children in one call.
     *
     * Translation keys default to `<prefix>.field.owner_*` /
     * `<prefix>.help.owner_*` / `<prefix>.placeholder.owner_*` so the
     * trait works out-of-the-box for Asset-style domains. Each label /
     * help / placeholder can be overridden via the matching
     * `*_label` / `*_help` / `*_placeholder` config-key for entities
     * that already ship divergent keys (e.g. Risk → `risk.field.risk_owner`).
     *
     * @param array{
     *     user_field?: string,
     *     person_field?: string,
     *     deputies_field?: string|null,
     *     legacy_field?: string|null,
     *     translation_prefix?: string,
     *     required?: bool,
     *     default_to_current_user?: bool,
     *     user_label?: string,
     *     user_help?: string,
     *     user_placeholder?: string,
     *     person_label?: string,
     *     person_help?: string,
     *     person_placeholder?: string,
     *     deputies_label?: string,
     *     deputies_help?: string,
     *     legacy_label?: string,
     *     legacy_help?: string,
     *     legacy_placeholder?: string,
     * } $config
     */
    protected function addOwnerPicker(FormBuilderInterface $builder, array $config): void
    {
        $userField     = $config['user_field']     ?? 'ownerUser';
        $personField   = $config['person_field']   ?? 'ownerPerson';
        $deputiesField = $config['deputies_field'] ?? null;
        $legacyField   = $config['legacy_field']   ?? null;
        $prefix        = $config['translation_prefix'] ?? 'common';
        // The default `common.*` owner keys live in the `messages` domain, but a
        // privacy/training/bcm form's translation_domain is its own — so without
        // pinning, those forms show raw `common.field.owner_user` strings. Pin
        // `messages` only for the default prefix; a custom prefix keeps the
        // host-form domain (translation_domain => null = inherit), which is the
        // existing, working behaviour for asset/risk/incident/... forms.
        $domain        = $config['translation_domain'] ?? ($prefix === 'common' ? 'messages' : null);
        $required      = $config['required'] ?? false;
        $defaultToCurrentUser = $config['default_to_current_user'] ?? false;

        $builder->add($userField, EntityType::class, [
            'label'        => $config['user_label']       ?? $prefix . '.field.owner_user',
                'translation_domain' => $domain,
            'class'        => User::class,
            'choice_label' => static fn (User $u): string => $u->getFullName() . ' (' . $u->getEmail() . ')',
            'required'     => $required,
            'placeholder'  => $config['user_placeholder'] ?? $prefix . '.placeholder.owner_user',
            'help'         => $config['user_help']        ?? $prefix . '.help.owner_user',
            'attr'         => [
                'data-controller'          => 'tom-select owner-picker',
                'data-owner-picker-target' => 'user',
                'data-action'              => 'change->owner-picker#toggle',
            ],
        ]);

        $builder->add($personField, EntityType::class, [
            'label'        => $config['person_label']       ?? $prefix . '.field.owner_person',
                'translation_domain' => $domain,
            'class'        => Person::class,
            'choice_label' => static fn (Person $p): string => $p->getFullName() ?? '',
            'required'     => false,
            'placeholder'  => $config['person_placeholder'] ?? $prefix . '.placeholder.owner_person',
            'help'         => $config['person_help']        ?? $prefix . '.help.owner_person',
            'attr'         => [
                'data-controller'          => 'tom-select owner-picker',
                'data-owner-picker-target' => 'person',
                'data-action'              => 'change->owner-picker#toggle',
            ],
        ]);

        if ($deputiesField !== null) {
            $builder->add($deputiesField, EntityType::class, [
                'label'        => $config['deputies_label'] ?? $prefix . '.field.owner_deputies',
                'translation_domain' => $domain,
                'class'        => Person::class,
                'choice_label' => static fn (Person $p): string => $p->getFullName() ?? '',
                'required'     => false,
                'multiple'     => true,
                'expanded'     => false,
                'attr'         => [
                    'data-controller'          => 'tom-select owner-picker',
                    'data-owner-picker-target' => 'deputies',
                ],
                'help'         => $config['deputies_help'] ?? $prefix . '.help.owner_deputies',
            ]);
        }

        if ($legacyField !== null) {
            // Legacy free-text owner — kept for backwards-compat but
            // surfaced with an Alva-style hint in the template macro.
            // Form-Type still exposes it as TextType so existing values
            // round-trip cleanly during the migration window.
            $builder->add($legacyField, TextType::class, [
                'label'    => $config['legacy_label'] ?? $prefix . '.field.owner_legacy',
                'translation_domain' => $domain,
                'required' => false,
                'attr'     => [
                    'maxlength'                => 100,
                    'placeholder'              => $config['legacy_placeholder'] ?? $prefix . '.placeholder.owner_legacy',
                    'data-owner-picker-target' => 'legacy',
                ],
                'help'     => $config['legacy_help'] ?? $prefix . '.help.owner_legacy',
            ]);
        }

        // Junior-ISB-Audit-2026-05-22 4.11: Owner pre-fill — UX-Polish.
        // When opt-in and the form binds to a NEW entity (no existing owner),
        // pre-fill the User-slot of the owner-picker with the currently
        // authenticated user. Existing values are never overwritten. The
        // listener silently no-ops when no Security service is available
        // (consumer did not implement getSecurityForOwnerPicker), so legacy
        // FormTypes remain untouched.
        if ($defaultToCurrentUser) {
            $security = $this->getSecurityForOwnerPicker();
            if ($security !== null) {
                $currentUser = $security->getUser();
                if ($currentUser instanceof User) {
                    $builder->addEventListener(
                        FormEvents::PRE_SET_DATA,
                        static function (FormEvent $event) use ($userField, $currentUser): void {
                            $entity = $event->getData();
                            // Only act on entities — never overwrite a
                            // persisted owner during edit. A new entity is
                            // either null (no data_class binding) or has no
                            // owner-user value yet.
                            if ($entity === null) {
                                return;
                            }
                            $accessor = PropertyAccess::createPropertyAccessor();
                            if (!$accessor->isReadable($entity, $userField)) {
                                return;
                            }
                            $existing = $accessor->getValue($entity, $userField);
                            if ($existing !== null) {
                                return;
                            }
                            if (!$accessor->isWritable($entity, $userField)) {
                                return;
                            }
                            $accessor->setValue($entity, $userField, $currentUser);
                        },
                    );
                }
            }
        }
    }

    /**
     * Override hook for owner-picker pre-fill (Junior-ISB-Audit-2026-05-22 4.11).
     *
     * Consumers that opt-in via `default_to_current_user => true` MUST also
     * implement this method to expose their injected Security service. The
     * default returns null so backward-compat is preserved — legacy FormTypes
     * that do not opt-in are not required to wire up Security.
     *
     * Typical implementation:
     *
     *   protected function getSecurityForOwnerPicker(): ?Security
     *   {
     *       return $this->security;
     *   }
     */
    protected function getSecurityForOwnerPicker(): ?Security
    {
        return null;
    }
}
