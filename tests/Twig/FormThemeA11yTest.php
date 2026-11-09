<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Twig\Environment;

/**
 * W11 (Gate 103): the global form theme (form/fa_cyber_input.html.twig) ties help text and
 * errors to the field, marks an invalid field and names radio/checkbox groups — a screen
 * reader announces the error when the field gets focus (WCAG 1.3.1, 3.3.1, 4.1.2).
 */
final class FormThemeA11yTest extends KernelTestCase
{
    #[Test]
    public function errorAndHelpDescribeTheFieldAndMarkItInvalid(): void
    {
        $html = $this->renderRow('name', TextType::class, ['help' => 'Hilfe', 'attr' => ['data-x' => '1']], 'Zu kurz');

        self::assertStringContainsString('aria-describedby="f_name_help f_name_errors"', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertStringContainsString('id="f_name_errors"', $html);
        self::assertStringContainsString('data-x="1"', $html, 'the field keeps its own attributes');
    }

    #[Test]
    public function validFieldWithoutHelpCarriesNoAriaNoise(): void
    {
        $html = $this->renderRow('plain', TextType::class, ['required' => false]);

        self::assertStringNotContainsString('aria-describedby', $html);
        self::assertStringNotContainsString('aria-invalid', $html);
    }

    #[Test]
    public function expandedChoiceIsANamedGroupDescribedByItsError(): void
    {
        $html = $this->renderRow('kind', ChoiceType::class, ['choices' => ['A' => 'a', 'B' => 'b'], 'expanded' => true], 'Wählen');

        self::assertStringContainsString('role="group"', $html);
        self::assertStringContainsString('aria-labelledby="f_kind_label"', $html);
        self::assertStringContainsString('id="f_kind_label"', $html);
        self::assertStringContainsString('aria-describedby="f_kind_errors"', $html);
    }

    /**
     * @param class-string         $type
     * @param array<string, mixed> $options
     */
    private function renderRow(string $field, string $type, array $options, ?string $error = null): string
    {
        $container = self::getContainer();
        /** @var FormFactoryInterface $factory */
        $factory = $container->get('form.factory');
        $form = $factory->createNamedBuilder('f', FormType::class, null, ['csrf_protection' => false])
            ->add($field, $type, $options)
            ->getForm();
        if (null !== $error) {
            $form->get($field)->addError(new FormError($error));
        }
        /** @var Environment $twig */
        $twig = $container->get('twig');

        return $twig->createTemplate('{{ form_row(form.' . $field . ') }}')->render(['form' => $form->createView()]);
    }
}
