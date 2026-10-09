<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class ResetPasswordType extends AbstractType
{
    /**
     * Matches SidusUserBundle's configurable password_min_length, hardcoded
     * here since this platform has no user-management config bundle.
     */
    private const int PASSWORD_MIN_LENGTH = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'password.mismatch',
                'required' => true,
                'first_options' => ['label' => 'reset_password.password'],
                'second_options' => ['label' => 'reset_password.password_repeat'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: self::PASSWORD_MIN_LENGTH, minMessage: 'password.too_short'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'reset_password.submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', 'forms');
    }
}
