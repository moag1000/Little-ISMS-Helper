<?php

declare(strict_types=1);

namespace App\Form\Extension;

use App\Entity\User;
use App\EventListener\SelfAssignmentGuard;
use App\Service\Responsibility\SelfAssignmentCheck;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * S20 in the web form: a person without manager scope who puts themselves
 * into the owner / assignee / approver slot of a record they do not hold gets
 * a form error instead of the 403 of {@see SelfAssignmentGuard} on save.
 */
final class SelfAssignmentFormExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        #[Autowire(lazy: true)]
        private readonly SelfAssignmentCheck $check,
    ) {
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (($options['data_class'] ?? null) === null) {
            return; // not bound to a record
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
            $originals = SelfAssignmentCheck::selfAssignedReferences($this->em, $record, $user);
            if ($originals === [] || $this->security->isGranted(SelfAssignmentGuard::MANAGER_ROLE)) {
                return;
            }
            if ($this->check->gainsResponsibility($user, $record, $originals)) {
                $form->addError(new FormError($this->translator->trans('responsibility.self_assignment', [], 'validators')));
            }
        }, -100);
    }
}
