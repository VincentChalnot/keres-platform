<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\OpponentType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Renamed from `NewGameType`, deliberately narrowed
 * (04-matchmaking.md sec 9.3): `opponentType` keeps exactly AI and HOTSEAT.
 * `HUMAN`/`MULTIPLAYER` must never be addable here - a networked game is
 * only ever constructed by `GameFactory` from a matched seek or an accepted
 * challenge, never this form.
 */
class LocalGameType extends AbstractType
{
    /** Highest AI level an anonymous visitor may play (levels above need an account). */
    public const int GUEST_MAX_AI_LEVEL = 4;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $guest = $options['guest'];

        $builder
            ->add('playerSide', ChoiceType::class, [
                'label' => 'new_local_game.form.player_side',
                'choices' => [
                    'color.white' => 'white',
                    'color.black' => 'black',
                    'color.random' => 'random',
                ],
                'data' => 'random', // Default selection
            ])
            ->add('opponentType', ChoiceType::class, [
                'label' => 'new_local_game.form.opponent',
                'choices' => [
                    'new_local_game.form.opponent_ai' => OpponentType::AI,
                    'new_local_game.form.opponent_hotseat' => OpponentType::HOTSEAT,
                ],
                'data' => OpponentType::AI, // Default selection
            ])
            ->add('aiLevel', ChoiceType::class, [
                'label' => 'new_local_game.form.ai_level',
                'help' => $guest ? 'new_local_game.form.ai_level_guest_help' : 'new_local_game.form.ai_level_help',
                'choices' => array_combine(range(1, 10), range(1, 10)),
                // Locked levels are listed but disabled for guests (`NewLocalGameAction` and the engine relay refuse them too).
                'choice_label' => static fn (int $level): string => $guest && $level > self::GUEST_MAX_AI_LEVEL ? 'new_local_game.form.ai_level_locked' : (string) $level,
                'choice_translation_parameters' => static fn (int $level): array => ['level' => $level],
                'choice_attr' => static fn (int $level): array => $guest && $level > self::GUEST_MAX_AI_LEVEL ? ['disabled' => 'disabled'] : [],
                'data' => 1, // T10: default is the weakest level.
            ])
            ->add('liveEvaluation', CheckboxType::class, [
                'label' => 'new_local_game.form.live_evaluation',
                'help' => $guest ? 'new_local_game.form.live_evaluation_guest_help' : 'new_local_game.form.live_evaluation_help',
                'required' => false,
                'disabled' => $guest, // A disabled field ignores the submitted value: guests never get the bar.
                'data' => false,
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'new_local_game.form.submit',
                'attr' => ['class' => 'button is-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('translation_domain', 'game');
        $resolver->setDefault('guest', false);
        $resolver->setAllowedTypes('guest', 'bool');
    }
}
