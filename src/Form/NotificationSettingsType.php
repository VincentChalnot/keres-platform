<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\Notification\NotificationType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Settings -> Notifications. One in-app toggle and one email toggle per
 * `NotificationType` - built from the enum, so a new type gets both for
 * free - plus the newsletter subscription. Array-backed: `inApp`/`email`
 * map type values to booleans for `NotificationPreferences::withInApp()`/
 * `withEmail()`.
 */
class NotificationSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $inApp = $builder->create('inApp', null, ['compound' => true, 'label' => false]);
        $email = $builder->create('email', null, ['compound' => true, 'label' => false]);

        foreach (NotificationType::cases() as $type) {
            $inApp->add($type->value, CheckboxType::class, [
                'label' => $type->labelKey(),
                'translation_domain' => 'notifications',
                'required' => false,
            ]);
            $email->add($type->value, CheckboxType::class, [
                'label' => $type->labelKey(),
                'translation_domain' => 'notifications',
                'required' => false,
            ]);
        }

        $builder
            ->add($inApp)
            ->add($email)
            ->add('newsletterOptIn', CheckboxType::class, [
                'label' => 'notification_settings.newsletter',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'common.save_changes',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', 'forms');
    }
}
