<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\CrisisTeam;
use App\Entity\IdentityProvider;
use App\Entity\ManagementReview;
use App\Entity\Notification\NotificationChannel;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Form\Admin\TenantComplianceSettingsType;
use App\Form\Admin\WorkflowStepOverlayType;
use App\Form\CrisisTeamType;
use App\Form\IdentityProviderType;
use App\Form\ManagementReviewType;
use App\Form\Notification\NotificationChannelType;
use App\Form\ProcessingActivityType;
use App\Form\Type\JsonBuilderType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Every JSON entity column that used to be a raw JSON textarea is now a
 * structured JsonBuilderType. Existing data (including keys the builder does
 * not know) must survive render → submit unchanged.
 */
final class JsonBuilderFieldsRoundTripTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, object, string, array<mixed>}>
     */
    public static function mappedFields(): iterable
    {
        yield 'management review action items' => [ManagementReviewType::class, new ManagementReview(), 'actionItemsWithDeadlines', [
            ['action' => 'Update risk assessment', 'owner' => 'CISO', 'deadline' => '2026-06-30', 'status' => 'open'],
            ['action' => 'Legacy item', 'priority' => 'high'],
        ]];
        yield 'management review framework status' => [ManagementReviewType::class, new ManagementReview(), 'frameworkComplianceStatus', [
            ['framework' => 'ISO 27001:2022', 'status' => 'compliant', 'last_review' => '2025-01-15'],
            ['framework' => 'NIS2', 'status' => 'in_progress', 'open_items' => 3],
        ]];
        yield 'crisis team escalation matrix' => [CrisisTeamType::class, new CrisisTeam(), 'escalationMatrix', [
            ['trigger' => 'Cyberattack', 'externalPartner' => 'CERT-Bund', 'contactInfo' => 'https://www.bsi.bund.de', 'escalateAfter' => '1h'],
        ]];
        yield 'processing activity retention per category' => [ProcessingActivityType::class, new ProcessingActivity(), 'retentionPerCategory', [
            'applicant_data' => '6 months', 'payroll' => '10 years',
        ]];
        yield 'processing activity joint controllers' => [ProcessingActivityType::class, new ProcessingActivity(), 'jointControllerDetails', [
            ['name' => 'Partner GmbH', 'contact' => 'dpo@partner.example', 'responsibilities' => 'Art. 15-22 requests', 'arrangement_reference' => 'Agreement 2026-01-15'],
        ]];
        yield 'tenant supervisory authorities' => [TenantComplianceSettingsType::class, new Tenant(), 'supervisoryAuthorities', [
            'BSI' => ['name' => 'Bundesamt für Sicherheit in der Informationstechnik', 'email' => 'meldestelle@bsi.bund.de', 'phone' => '+49 228 9582 0'],
        ]];
        yield 'tenant retention policies (legacy keys kept)' => [TenantComplianceSettingsType::class, new Tenant(), 'dataRetentionPolicies', [
            'incident' => ['retention_days' => 1825, 'auto_delete' => true],
            'crm' => ['retention_days' => 730, 'auto_delete' => false, 'basis' => 'HGB §257'],
        ]];
        yield 'tenant notification preferences keep _global' => [TenantComplianceSettingsType::class, new Tenant(), 'notificationPreferences', [
            'incident' => ['email' => true, 'slack' => false],
            '_global' => ['digest_enabled' => true, 'digest_frequency' => 'daily', 'webhook_timeout' => 10],
        ]];
        yield 'tenant csirt endpoints' => [TenantComplianceSettingsType::class, new Tenant(), 'csirtEndpoints', [
            'BSI-CERT' => ['url' => 'https://www.bsi.bund.de/meldung', 'contact' => 'certbund@bsi.bund.de', 'auth_type' => 'none'],
        ]];
        yield 'tenant crisis team on-call' => [TenantComplianceSettingsType::class, new Tenant(), 'crisisTeamOnCall', [
            ['date' => '2026-11-02', 'primary' => 'A. Admin', 'deputy' => 'B. Backup'],
        ]];
    }

    /**
     * @param class-string $formType
     * @param array<mixed> $data
     */
    #[Test]
    #[DataProvider('mappedFields')]
    public function existingDataRoundTripsThroughTheBuilder(string $formType, object $entity, string $field, array $data): void
    {
        $accessor = PropertyAccess::createPropertyAccessor();
        $accessor->setValue($entity, $field, $data);

        $form = $this->createForm($formType, $entity);
        self::assertTrue($form->has($field), sprintf('%s has no field %s (module gate?)', $formType, $field));
        self::assertInstanceOf(JsonBuilderType::class, $form->get($field)->getConfig()->getType()->getInnerType());

        $viewValue = $form->createView()[$field]->vars['value'];
        self::assertSame($data, json_decode($viewValue, true), 'builder receives the stored value');

        $form->submit([$field => $viewValue], false);

        self::assertTrue($form->get($field)->isSynchronized());
        self::assertCount(0, $form->get($field)->getErrors(), (string) $form->get($field)->getErrors());
        self::assertSame($data, $accessor->getValue($entity, $field));
    }

    #[Test]
    public function invalidStructureIsRejectedOnTheField(): void
    {
        $entity = new ManagementReview();
        $form = $this->createForm(ManagementReviewType::class, $entity);
        $form->submit(['actionItemsWithDeadlines' => json_encode([['owner' => 'CISO', 'status' => 'whatever']])], false);

        self::assertGreaterThan(0, count($form->get('actionItemsWithDeadlines')->getErrors()));
    }

    #[Test]
    public function notificationChannelConfigIsPrefilledAndRoundTrips(): void
    {
        $channel = (new NotificationChannel())
            ->setName('Ops')
            ->setType(NotificationChannel::TYPE_EMAIL)
            ->setConfig(['recipients' => ['ops@example.com'], 'from' => 'isms@example.com', 'delivery_mode' => 'digest_daily']);

        $form = $this->createForm(NotificationChannelType::class, $channel);
        $view = $form->createView();
        self::assertSame($channel->getConfig(), json_decode($view['configJson']->vars['value'], true));
        self::assertSame(NotificationChannel::DELIVERY_MODE_DIGEST_DAILY, $view['deliveryMode']->vars['value']);

        $form->submit(['configJson' => $view['configJson']->vars['value']], false);
        self::assertTrue($form->get('configJson')->isValid());
        self::assertSame($channel->getConfig(), $form->get('configJson')->getData());
    }

    #[Test]
    public function workflowOverlayConditionsAndApproversAreStructured(): void
    {
        $conditions = ['type' => 'field_completion', 'entity' => 'DataBreach', 'fields' => ['severity', 'notificationRequired'], 'condition' => 'severity >= high'];
        $form = $this->createForm(WorkflowStepOverlayType::class, [
            'approverUsers' => [7],
            'autoProgressConditions' => $conditions,
        ], ['user_choices' => ['Alice (alice@example.com)' => 7, 'Bob (bob@example.com)' => 9]]);

        $view = $form->createView();
        self::assertSame($conditions, json_decode($view['autoProgressConditions']->vars['value'], true));

        $form->submit([
            'approverUsers' => ['7', '9'],
            'autoProgressConditions' => $view['autoProgressConditions']->vars['value'],
        ], false);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true));
        self::assertSame([7, 9], $form->get('approverUsers')->getData());
        self::assertSame($conditions, $form->get('autoProgressConditions')->getData());

        $form = $this->createForm(WorkflowStepOverlayType::class, [], ['user_choices' => []]);
        $form->submit(['autoProgressConditions' => json_encode(['type' => 'unknown_type'])], false);
        self::assertFalse($form->get('autoProgressConditions')->isValid());
    }

    #[Test]
    public function identityProviderClaimMapIsStructured(): void
    {
        $map = ['mail' => 'email', 'given_name' => 'firstName', 'dept' => 'department'];
        $form = $this->createForm(IdentityProviderType::class, new IdentityProvider(), ['attribute_map_initial' => $map]);
        $view = $form->createView();
        self::assertSame($map, json_decode($view['attributeMapJson']->vars['value'], true));

        $form->submit(['attributeMapJson' => $view['attributeMapJson']->vars['value']], false);
        self::assertTrue($form->get('attributeMapJson')->isValid());
        self::assertSame($map, $form->get('attributeMapJson')->getData());

        $form = $this->createForm(IdentityProviderType::class, new IdentityProvider());
        $form->submit(['attributeMapJson' => json_encode(['mail' => 'password'])], false);
        self::assertFalse($form->get('attributeMapJson')->isValid(), 'only known user fields are accepted');
    }

    /**
     * @param class-string         $type
     * @param array<string, mixed> $options
     */
    private function createForm(string $type, mixed $data, array $options = []): FormInterface
    {
        self::bootKernel();
        /** @var FormFactoryInterface $factory */
        $factory = self::getContainer()->get('form.factory');

        return $factory->create($type, $data, $options + ['csrf_protection' => false]);
    }
}
