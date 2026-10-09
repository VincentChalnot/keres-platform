<?php

declare(strict_types=1);

namespace App\Form;

use App\Service\Locale\LocaleResolver;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * Settings -> Profile. Array-backed, not entity-bound: its fields span
 * `User` (username, display name) and `UserPreferences` (names, language,
 * country), and `username` is written through `UsernameGenerator::change()`'s
 * guarded DBAL statement (05-social.md sec 1.6), never a plain ORM flush -
 * so this form must not own a `data_class` that would tempt a caller into
 * flushing it directly. Email is shown read-only: it is the login identity.
 */
class ProfileSettingsType extends AbstractType
{
    public function __construct(
        private readonly LocaleResolver $localeResolver,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Never disabled: a pure case change is allowed at any time (U3),
            // so the 12-month cooldown is enforced server-side on submit.
            ->add('username', TextType::class, [
                'label' => 'profile_settings.username',
                'constraints' => [
                    new NotBlank(message: 'profile.username.blank'),
                    new Regex(
                        pattern: '/^[a-zA-Z0-9_-]{3,32}$/',
                        message: 'profile.username.format',
                    ),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'profile_settings.display_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'profile_settings.first_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'profile_settings.last_name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'profile_settings.email',
                'disabled' => true,
                'required' => false,
            ])
            // The interface language (`User::$locale`); empty = follow the browser.
            ->add('locale', ChoiceType::class, [
                'label' => 'profile_settings.locale',
                'required' => false,
                'placeholder' => 'profile_settings.locale_auto',
                'choices' => $this->localeResolver->enabledLocales(),
                'choice_label' => static fn (string $locale): string => 'language.'.$locale,
                'choice_translation_domain' => 'navigation',
            ])
            ->add('country', CountryType::class, [
                'label' => 'profile_settings.country',
                'required' => false,
                'placeholder' => 'profile_settings.country_none',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'profile_settings.submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', 'forms');
    }
}
