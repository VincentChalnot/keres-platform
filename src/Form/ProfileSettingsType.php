<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
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
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Never disabled: a pure case change is allowed at any time (U3),
            // so the 12-month cooldown is enforced server-side on submit.
            ->add('username', TextType::class, [
                'label' => 'Username',
                'constraints' => [
                    new NotBlank(message: 'Please enter a username.'),
                    new Regex(
                        pattern: '/^[a-zA-Z0-9_-]{3,32}$/',
                        message: 'Usernames are 3-32 characters long and may only contain letters, numbers, underscores and hyphens.',
                    ),
                ],
            ])
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'required' => false,
                'constraints' => [new Length(max: 255)],
            ])
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'disabled' => true,
                'required' => false,
            ])
            ->add('locale', LanguageType::class, [
                'label' => 'Language',
                'required' => false,
                'placeholder' => 'Not specified',
            ])
            ->add('country', CountryType::class, [
                'label' => 'Country',
                'required' => false,
                'placeholder' => 'Not specified',
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save changes',
            ]);
    }
}
