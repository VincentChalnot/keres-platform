<?php

declare(strict_types=1);

namespace App\Entity;

use App\Model\SpeedCategory;
use App\Repository\UserRatingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One rating pool per user per `SpeedCategory` (D2, 06-rating.md sec 5.1).
 * `rating`/`deviation`/`volatility` are the Glicko-2 doubles - authoritative,
 * never rounded, never fed the display-rounded `game_player` snapshot back
 * in (06-rating.md sec 7.1/8.1). A row exists only after the owner's first
 * rated game in that category (sec 5.3) - reads never insert.
 */
#[ORM\Entity(repositoryClass: UserRatingRepository::class)]
#[ORM\Table(name: 'user_rating')]
#[ORM\UniqueConstraint(name: 'uniq_user_rating_user_category', columns: ['user_id', 'category'])]
#[ORM\Index(name: 'idx_user_rating_leaderboard', columns: ['category', 'rating'], options: ['where' => 'games_played > 0'])]
class UserRating
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private readonly User $user;

    #[ORM\Column(name: 'category', type: Types::SMALLINT)]
    private readonly int $categoryValue;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 1500])]
    private float $rating = 1500.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 350])]
    private float $deviation = 350.0;

    #[ORM\Column(type: Types::FLOAT, options: ['default' => 0.06])]
    private float $volatility = 0.06;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $gamesPlayed = 0;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastRatedAt = null;

    public function __construct(User $user, SpeedCategory $category)
    {
        $this->user = $user;
        $this->categoryValue = $category->value;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCategory(): SpeedCategory
    {
        return SpeedCategory::from($this->categoryValue);
    }

    public function getRating(): float
    {
        return $this->rating;
    }

    public function getDeviation(): float
    {
        return $this->deviation;
    }

    public function getVolatility(): float
    {
        return $this->volatility;
    }

    public function getGamesPlayed(): int
    {
        return $this->gamesPlayed;
    }

    public function getLastRatedAt(): ?\DateTimeImmutable
    {
        return $this->lastRatedAt;
    }

    /** `RatingUpdater::applyForFinishedGame()` only (06-rating.md sec 5.3 write path, step 3). */
    public function apply(float $rating, float $deviation, float $volatility, \DateTimeImmutable $ratedAt): void
    {
        $this->rating = $rating;
        $this->deviation = $deviation;
        $this->volatility = $volatility;
        ++$this->gamesPlayed;
        $this->lastRatedAt = $ratedAt;
    }
}
