<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;

/**
 * Shared shape for the two Settings -> Privacy GDPR request forms (T5:
 * data export, account deletion). Both just create a `Feedback` row for a
 * human to process manually - no automated export or deletion happens
 * here. The email field is disabled/unmapped: it is purely a "you're
 * submitting this as <email>" confirmation display, never read back from
 * the request - the acting `$user` is always the authoritative source.
 */
class GdprRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', TextType::class, [
                'label' => 'gdpr.email',
                'disabled' => true,
                'mapped' => false,
                'data' => $options['user_email'],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'gdpr.message',
                'required' => false,
                'constraints' => [
                    new Length(max: 2000, maxMessage: 'gdpr.message_too_long'),
                ],
                'attr' => [
                    'rows' => 3,
                    'placeholder' => 'gdpr.message_placeholder',
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => $options['submit_label'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'user_email' => '',
            'submit_label' => 'gdpr.submit',
            'translation_domain' => 'forms',
        ]);
        $resolver->setRequired(['user_email', 'submit_label']);
        $resolver->setAllowedTypes('user_email', 'string');
        $resolver->setAllowedTypes('submit_label', 'string');
    }
}
