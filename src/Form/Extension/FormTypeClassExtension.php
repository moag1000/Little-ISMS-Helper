<?php

declare(strict_types=1);

namespace App\Form\Extension;

use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Extension\Core\Type\FormType;

/**
 * FormTypeClassExtension — exposes the resolved FormType FQCN on every
 * FormView via `form.vars.form_type_class`.
 *
 * This is the bridge that lets {@see \App\Twig\SectionPolicyExtension}
 * look up the `getSectionMap()` static method on the FormType at
 * render-time. Without this extension, FormView only carries
 * `block_prefixes` (snake_case strings) and the FQCN is lost.
 *
 * Applies to {@see FormType} (the abstract root) so the var is propagated
 * to every form, including child fields. Child fields will then carry
 * their OWN form_type_class (e.g. TextType, ChoiceType) — only the root
 * form's class matters for SectionPolicy.
 */
class FormTypeClassExtension extends AbstractTypeExtension
{
    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['form_type_class'] = $form->getConfig()->getType()->getInnerType()::class;
    }
}
