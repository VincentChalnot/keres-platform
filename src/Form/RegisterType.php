<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Self-service email/password account creation. Not part of the ported
 * SidusUserBundle flow (that bundle expects accounts provisioned by an
 * admin/CLI) — added because OIDC and dev-login are otherwise the only
 * account-creation paths on this platform.
 */
class RegisterType extends AbstractType
{
    private const int PASSWORD_MIN_LENGTH = 8;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'register.email',
                'constraints' => [
                    new NotBlank(message: 'register.email_blank'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'password.mismatch',
                'required' => true,
                'first_options' => ['label' => 'register.password'],
                'second_options' => ['label' => 'register.password_repeat'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: self::PASSWORD_MIN_LENGTH, minMessage: 'password.too_short'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'register.submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', 'forms');
    }
}
