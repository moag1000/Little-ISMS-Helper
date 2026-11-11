<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Entity\User;
use App\EventListener\SelfAssignmentGuard;
use App\EventListener\WriteRuleGuard;
use App\Form\Util\ReadOnlyField;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Responsibility\SelfAssignmentCheck;
use App\Service\WriteRule\CorrectionReason;
use App\Service\WriteRule\WriteRuleCheck;
use App\Service\WriteRule\WriteRules;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * S22 in the web form: the write rules of {@see WriteRuleGuard}, read from the
 * same entity attributes, so no FormType carries its own copy.
 *
 * - Editing a stored record, a person without manager scope sees create-only
 *   fields read-only, and classification fields read-only unless they are
 *   responsible for the record. Disabled fields are not submitted; the stored
 *   value stays.
 * - A manager correcting a create-only field gives a reason (S28, F-06) in the
 *   extra field {@see REASON_FIELD}; without one the form shows an error.
 * - Who is responsible: a change to the owner / assignee / approver slots by
 *   somebody who is neither manager nor current holder becomes a form error
 *   instead of the 403 of the guard on save.
 */
final class WriteRuleFormExtension extends AbstractTypeExtension
{
    /** Unmapped field for the reason of a create-only correction (S28). */
    public const string REASON_FIELD = 'createOnlyCorrectionReason';

    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire(lazy: true)]
        private readonly WriteRuleCheck $check,
        #[Autowire(lazy: true)]
        private readonly ResponsibilityResolver $resolver,
        private readonly CorrectionReason $correctionReason,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $dataClass = $options['data_class'] ?? null;
        if (!is_string($dataClass) || ($options['compound'] ?? true) === false) {
            return;
        }
        $rules = WriteRules::of($dataClass);
        if ($rules !== []) {
            $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) use ($rules): void {
                $form = $event->getForm();
                $record = $event->getData();
                if (!$form->isRoot() || !is_object($record) || !self::isStored($record)) {
                    return;
                }
                $user = $this->security->getUser();
                if (!$user instanceof User) {
                    return;
                }
                if ($this->security->isGranted(SelfAssignmentGuard::MANAGER_ROLE)) {
                    $this->addCorrectionReason($form, $rules);

                    return;
                }
                $responsible = null;
                foreach ($form->all() as $name => $child) {
                    $config = $child->getConfig();
                    if (!$config->getMapped() || $config->getDisabled()) {
                        continue;
                    }
                    $field = (string) ($config->getPropertyPath() ?? $name);
                    $rule = $rules[$field] ?? null;
                    if ($rule === null) {
                        continue;
                    }
                    if ($rule === WriteRules::MANAGER_OR_RESPONSIBLE) {
                        $responsible ??= $this->resolver->isResponsibleFor($user, $record);
                        if ($responsible) {
                            continue;
                        }
                    }
                    ReadOnlyField::apply($form, (string) $name, $this->translator->trans($rule === WriteRules::CREATE_ONLY
                        ? 'write_rule.help.create_only'
                        : 'write_rule.help.manager_or_responsible', [], 'validators'));
                }
            }, -100);
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $record = $event->getData();
            if (!$form->isRoot() || !is_object($record) || $this->em->getMetadataFactory()->isTransient($record::class)) {
                return;
            }
            $user = $this->security->getUser();
            if (!$user instanceof User) {
                return;
            }
            $changes = WriteRuleCheck::ruledChanges($this->em, $record);
            if ($changes === []) {
                return;
            }
            if ($this->security->isGranted(SelfAssignmentGuard::MANAGER_ROLE)) {
                $this->takeCorrectionReason($form, $record, $changes);

                return;
            }
            if (SelfAssignmentCheck::selfAssignedReferences($this->em, $record, $user) !== []) {
                return; // S20 names that case itself (SelfAssignmentFormExtension)
            }
            if ($this->check->refusal($record, $user, false, $changes) !== null) {
                $form->addError(new FormError($this->translator->trans('write_rule.refused', [], 'validators')));
            }
        }, -100);
    }

    /**
     * S28 (F-06): a manager editing a record whose form shows a create-only field
     * gets the reason field next to it; it is asked for only when that value changes.
     *
     * @param array<string, string> $rules
     */
    private function addCorrectionReason(FormInterface $form, array $rules): void
    {
        foreach ($form->all() as $name => $child) {
            $config = $child->getConfig();
            $field = (string) ($config->getPropertyPath() ?? $name);
            if ($config->getMapped() && !$config->getDisabled() && ($rules[$field] ?? null) === WriteRules::CREATE_ONLY) {
                $form->add(self::REASON_FIELD, TextareaType::class, [
                    'mapped' => false,
                    'required' => false,
                    'label' => 'write_rule.correction_reason.label',
                    'help' => 'write_rule.correction_reason.help',
                    'translation_domain' => 'validators',
                    'attr' => ['rows' => 2, 'maxlength' => CorrectionReason::MAX_LENGTH],
                ]);

                return;
            }
        }
    }

    /** @param array<string, mixed> $changes */
    private function takeCorrectionReason(FormInterface $form, object $record, array $changes): void
    {
        if (WriteRuleCheck::createOnlyChanges($record, $changes) === []) {
            return;
        }
        $reason = $form->has(self::REASON_FIELD) ? trim((string) $form->get(self::REASON_FIELD)->getData()) : '';
        if ($reason === '') {
            $error = new FormError($this->translator->trans('write_rule.correction_reason.required', [], 'validators'));
            ($form->has(self::REASON_FIELD) ? $form->get(self::REASON_FIELD) : $form)->addError($error);

            return;
        }
        $this->correctionReason->provide($reason);
    }

    /** A record already saved (the clone and new forms carry none yet). */
    public static function isStored(object $record): bool
    {
        return is_callable([$record, 'getId']) && $record->getId() !== null;
    }
}
