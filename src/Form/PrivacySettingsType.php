<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\UserPreferences;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Settings -> Privacy: who can find and reach you. Blocked users are listed next to it, outside the form. */
class PrivacySettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('searchableByOtherUsers', CheckboxType::class, [
                'label' => 'Appear in player search',
                'required' => false,
            ])
            ->add('allowContactByEmail', CheckboxType::class, [
                'label' => 'Allow other players who know my email to contact me',
                'required' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Save changes',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserPreferences::class,
        ]);
    }
}
