<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\UserPreferences;
use App\Model\AnimationSpeed;
use App\Model\ChessExperience;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/** Settings -> Board & gameplay: how the board looks and behaves when a game page opens. */
class BoardSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('showBoardCoordinates', CheckboxType::class, [
                'label' => 'board_settings.show_coordinates',
                'required' => false,
            ])
            ->add('showOpponentThreatsOnHover', CheckboxType::class, [
                'label' => 'board_settings.show_threats',
                'required' => false,
            ])
            ->add('rotateOpponentPieces', CheckboxType::class, [
                'label' => 'board_settings.rotate_opponent',
                'required' => false,
            ])
            ->add('pieceAnimationSpeed', EnumType::class, [
                'class' => AnimationSpeed::class,
                'label' => 'board_settings.animation_speed',
                'help' => 'board_settings.animation_speed_help',
                'choice_label' => static fn (AnimationSpeed $speed): string => 'board_settings.animation_speed_'.$speed->value,
                'expanded' => true,
            ])
            // Lives on the user, not on the preferences: the action reads and writes it.
            ->add('chessExperience', EnumType::class, [
                'class' => ChessExperience::class,
                'mapped' => false,
                'required' => false,
                'placeholder' => 'board_settings.chess_experience_none',
                'label' => 'board_settings.chess_experience',
                'help' => 'board_settings.chess_experience_help',
                'choice_label' => static fn (ChessExperience $answer): string => 'board_settings.chess_experience_'.$answer->value,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'common.save_changes',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => UserPreferences::class,
            'translation_domain' => 'forms',
        ]);
    }
}
