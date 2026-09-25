<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\Notification\NotificationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Settings -> Notifications. One in-app toggle per `NotificationType` -
 * built from the enum, so a new type gets its toggle for free - plus the
 * newsletter subscription. Array-backed: `inApp` maps type values to
 * booleans for `NotificationPreferences::withInApp()`.
 */
class NotificationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inApp = $builder->create('inApp', null, ['compound' => true, 'label' => false]);

        foreach (NotificationType::cases() as $type) {
            $inApp->add($type->value, CheckboxType::class, [
                'label' => $type->label(),
                'required' => false,
            ]);
        }

        $builder
            ->add($inApp)
            ->add('newsletterOptIn', CheckboxType::class, [
                'label' => 'Subscribe to the newsletter',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save changes',
            ]);
    }
}
