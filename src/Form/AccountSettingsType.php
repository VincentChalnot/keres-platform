<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * `GET|POST /settings/profile` (05-social.md sec 9.2). Array-backed like
 * RegisterType/FeedbackType, not entity-bound like UserPreferencesType:
 * the `username` field is written through
 * `UsernameGenerator::changeOnce()`'s guarded DBAL statement (sec 1.6),
 * never a plain ORM flush, so this form must not own a `data_class`
 * that would tempt a caller into flushing it directly.
 */
class AccountSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, [
                'label' => 'Username',
                'disabled' => !$options['canChange'],
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
                'constraints' => [
                    new Length(max: 255),
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save changes',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'canChange' => true,
        ]);

        $resolver->setAllowedTypes('canChange', 'bool');
    }
}
