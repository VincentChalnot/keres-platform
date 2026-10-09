<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\FeedbackCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

class FeedbackType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', ChoiceType::class, [
                'label' => 'feedback.category',
                'choices' => [
                    'feedback.category_bug' => FeedbackCategory::BUG,
                    'feedback.category_suggestion' => FeedbackCategory::SUGGESTION,
                    'feedback.category_gameplay' => FeedbackCategory::GAMEPLAY,
                    'feedback.category_other' => FeedbackCategory::OTHER,
                ],
                'constraints' => [
                    new NotBlank(message: 'feedback.category_blank'),
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'feedback.message',
                'constraints' => [
                    new NotBlank(message: 'feedback.message_blank'),
                    new Length(min: 10, max: 5000, minMessage: 'feedback.message_too_short'),
                ],
                'attr' => [
                    'rows' => 6,
                    'placeholder' => 'feedback.message_placeholder',
                ],
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'feedback.submit',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['translation_domain' => 'forms']);
    }
}
