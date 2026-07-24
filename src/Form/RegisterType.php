<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
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
                'label' => 'Email address',
                'constraints' => [
                    new NotBlank(message: 'Please enter your email address.'),
                ],
            ])
            ->add('password', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The passwords don\'t match.',
                'required' => true,
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
                'constraints' => [
                    new NotBlank(),
                    new Length(min: self::PASSWORD_MIN_LENGTH, minMessage: 'Your password must be at least {{ limit }} characters.'),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Create account',
            ]);
    }
}
