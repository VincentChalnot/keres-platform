<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\OpponentType;
use Symfony\Component\Form\AbstractType;
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
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('playerSide', ChoiceType::class, [
                'label' => 'Side to play',
                'choices' => [
                    'White' => 'white',
                    'Black' => 'black',
                    'Random' => 'random',
                ],
                'data' => 'white', // Default selection
            ])
            ->add('opponentType', ChoiceType::class, [
                'label' => 'Opponent',
                'choices' => [
                    'AI' => OpponentType::AI,
                    'Hot-seat (2 players)' => OpponentType::HOTSEAT,
                ],
                'data' => OpponentType::AI, // Default selection
            ])
            ->add('aiLevel', ChoiceType::class, [
                'label' => 'Difficulty',
                'help' => 'Only applies against the AI. 1 is weakest, 10 is full strength.',
                'choices' => array_combine(range(1, 10), range(1, 10)),
                'data' => 1, // T10: default is the weakest level.
            ])
            ->add('submit', SubmitType::class, [
                'label' => 'Start Game',
                'attr' => ['class' => 'button is-primary'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([]);
    }
}
