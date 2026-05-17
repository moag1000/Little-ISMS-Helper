<?php

declare(strict_types=1);

namespace App\Tests\Form\Type;

use App\Form\Type\JsonTagsType;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\Test\TypeTestCase;

final class JsonTagsTypeTest extends TypeTestCase
{
    #[Test]
    public function emptyValueProducesEmptyArray(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $form->submit('');
        self::assertTrue($form->isSynchronized());
        self::assertSame([], $form->getData());
    }

    #[Test]
    public function csvBecomesArray(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $form->submit('alpha,beta,gamma');
        self::assertTrue($form->isSynchronized());
        self::assertSame(['alpha', 'beta', 'gamma'], $form->getData());
    }

    #[Test]
    public function whitespaceAndDuplicatesAreNormalized(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $form->submit('  alpha , beta,alpha,  ');
        self::assertTrue($form->isSynchronized());
        self::assertSame(['alpha', 'beta'], $form->getData());
    }

    #[Test]
    public function arrayModelRendersAsCsv(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $form->setData(['iso27001', 'pci-dss']);
        self::assertSame('iso27001,pci-dss', $form->getViewData());
    }

    #[Test]
    public function nullModelRendersAsEmptyString(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $form->setData(null);
        self::assertSame('', $form->getViewData());
    }

    #[Test]
    public function attrsAreWiredForTomSelectTagMode(): void
    {
        $form = $this->factory->create(JsonTagsType::class);
        $view = $form->createView();
        self::assertSame('tom-select', $view->vars['attr']['data-controller']);
        self::assertSame('true', $view->vars['attr']['data-tom-select-create-value']);
        self::assertSame(',', $view->vars['attr']['data-tom-select-delimiter-value']);
    }
}
