<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\UserPreferences;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CountryType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\LanguageType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

class UserPreferencesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
                'mapped' => false,
                'disabled' => true,
                'data' => $options['email'],
                'required' => false,
            ])
            ->add('identifier', TextType::class, [
                'label' => 'Identifier',
                'help' => 'Your unique public identifier. Letters, numbers, underscores and hyphens only.',
                'constraints' => [
                    new NotBlank(message: 'Please choose an identifier.'),
                    new Length(min: 3, max: 32, minMessage: 'Your identifier must be at least {{ limit }} characters.'),
                    new Regex(
                        pattern: '/^[a-zA-Z0-9_-]+$/',
                        message: 'Your identifier may only contain letters, numbers, underscores and hyphens.',
                    ),
                ],
            ])
            ->add('firstName', TextType::class, [
                'label' => 'First name',
                'required' => false,
                'constraints' => [
                    new Length(max: 255),
                ],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Last name',
                'required' => false,
                'constraints' => [
                    new Length(max: 255),
                ],
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
            ->add('newsletterOptIn', CheckboxType::class, [
                'label' => 'Subscribe to the newsletter',
                'required' => false,
            ])
            ->add('showBoardCoordinates', CheckboxType::class, [
                'label' => 'Show board coordinates',
                'required' => false,
            ])
            ->add('showOpponentThreatsOnHover', CheckboxType::class, [
                'label' => 'Highlight opponent threats on hover',
                'required' => false,
            ])
            ->add('allowContactByEmail', CheckboxType::class, [
                'label' => 'Allow other players who know my email to contact me',
                'required' => false,
            ])
            ->add('searchableByOtherUsers', CheckboxType::class, [
                'label' => 'Appear in player search',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save preferences',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserPreferences::class,
            'email' => null,
        ]);

        $resolver->setAllowedTypes('email', ['null', 'string']);
    }
}
