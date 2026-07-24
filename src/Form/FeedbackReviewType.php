<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Feedback;
use App\Model\FeedbackCategory;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class FeedbackReviewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'disabled' => true,
                'choices' => [
                    'Bug report' => FeedbackCategory::BUG,
                    'Suggestion' => FeedbackCategory::SUGGESTION,
                    'Gameplay feedback' => FeedbackCategory::GAMEPLAY,
                    'Other' => FeedbackCategory::OTHER,
                ],
            ])
            ->add('message', TextareaType::class, [
                'label' => 'Message',
                'disabled' => true,
                'attr' => ['rows' => 8],
            ])
            ->add('user', TextType::class, [
                'label' => 'Submitted by',
                'disabled' => true,
                'mapped' => false,
                'data' => (string) $options['data']?->getUser()?->getEmail(),
            ])
            ->add('createdAt', TextType::class, [
                'label' => 'Submitted at',
                'disabled' => true,
                'mapped' => false,
                'data' => $options['data']?->getCreatedAt()?->format('Y-m-d H:i:s'),
            ])
            ->add('reviewed', CheckboxType::class, [
                'label' => 'Reviewed',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Feedback::class,
        ]);
    }
}
