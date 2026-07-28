<?php

declare(strict_types=1);

namespace App\Service\Matchmaking;

use App\Entity\Seek;
use App\Entity\User;
use App\Model\Glicko\Rating;
use App\Model\MultiplayerLimits;
use App\Model\SpeedCategory;
use App\Repository\FriendshipRepository;
use App\Service\Rating\RatingUpdater;
use Symfony\Component\Uid\Uuid;

/**
 * Wire shapes for `/lobby/seeks` and the `lobby/seeks` Mercure topic
 * (04-matchmaking.md sec 5.1-5.2, 02-realtime.md sec 4.0/4.3). One builder,
 * shared by the HTTP listing and the SSE broadcast, so they can never drift
 * (the same discipline `GameStatePayloadBuilder` already applies).
 *
 * `PlayerRef.rating`/`.provisional` are `RatingUpdater::currentRating()`,
 * inflation-adjusted at render time (06-rating.md sec 4.3/8.1) - `UNLIMITED`
 * seeks (no pool) fall back to the literal `GLICKO_DEFAULT_RATING`
 * placeholder, per sec 4.3's explicit exception.
 */
final readonly class SeekPayloadBuilder
{
    private const int ENCODE_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private FriendshipRepository $friendshipRepository,
        private RatingUpdater $ratingUpdater,
    ) {
    }

    /**
     * @param Seek[] $seeks
     *
     * @return array<string, mixed>
     */
    public function buildListing(array $seeks, ?User $viewer, int $poolSize, \DateTimeImmutable $now): array
    {
        return [
            'seeks' => array_map(fn (Seek $seek) => $this->buildSummary($seek, $viewer, $now), $seeks),
            'poolSize' => $poolSize,
            'serverTime' => $this->micros($now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSummary(Seek $seek, ?User $viewer, \DateTimeImmutable $now): array
    {
        $self = null !== $viewer && $seek->getUser() === $viewer;

        return [
            'uuid' => $seek->getUuid()->toRfc4122(),
            'user' => $this->buildPlayerRef($seek->getUser(), $seek->getTimeControl()->speedCategory(), $now),
            'timeControl' => $this->buildTimeControlRef($seek),
            'rated' => $seek->isRated(),
            'color' => strtolower($seek->getColorPreference()->name),
            'ratingRange' => ['min' => $seek->getRatingMin(), 'max' => $seek->getRatingMax()],
            'autoWiden' => $seek->isAutoWiden(),
            'createdAt' => $this->micros($seek->getCreatedAt()),
            'self' => null === $viewer ? null : $self,
            'playable' => null === $viewer ? null : (!$self && $this->isPlayableFor($seek, $viewer, $now)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAddedEvent(Seek $seek, int $poolSize, \DateTimeImmutable $now): array
    {
        return [
            'type' => 'seek.added',
            'seekUuid' => $seek->getUuid()->toRfc4122(),
            'seek' => $this->buildSummary($seek, null, $now),
            'reason' => null,
            'poolSize' => $poolSize,
            'serverTime' => $this->micros($now),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRemovedEvent(Uuid $seekUuid, string $reason, int $poolSize, \DateTimeImmutable $now): array
    {
        return [
            'type' => 'seek.removed',
            'seekUuid' => $seekUuid->toRfc4122(),
            'seek' => null,
            'reason' => $reason,
            'poolSize' => $poolSize,
            'serverTime' => $this->micros($now),
        ];
    }

    public function encode(array $payload): string
    {
        return json_encode($payload, self::ENCODE_FLAGS);
    }

    /**
     * sec 3.2/5.1: the poster's side of `accepts()` only - "ignoring the
     * viewer's own window, because clicking is consent" (sec 3.8c). Colour
     * never blocks: `accept()` always mirrors to the complement or RANDOM.
     * Block relations (sec 3.2/04-matchmaking.md line 610) always apply,
     * even under `autoWiden` - a wide-open window still never crosses a block.
     */
    private function isPlayableFor(Seek $seek, User $viewer, \DateTimeImmutable $now): bool
    {
        if ($this->friendshipRepository->isBlockedEitherWay($viewer, $seek->getUser())) {
            return false;
        }

        if ($seek->isAutoWiden()) {
            return true; // width(0) already covers the placeholder-rating case; a real widening window only grows.
        }

        $category = $seek->getTimeControl()->speedCategory();
        $viewerRating = null === $category
            ? MultiplayerLimits::GLICKO_DEFAULT_RATING
            : $this->ratingUpdater->currentRating($viewer, $category, $now)->display();

        if (null !== $seek->getRatingMin() && $viewerRating < $seek->getRatingMin()) {
            return false;
        }

        if (null !== $seek->getRatingMax() && $viewerRating > $seek->getRatingMax()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPlayerRef(User $user, ?SpeedCategory $category, \DateTimeImmutable $now): array
    {
        $rating = null === $category
            ? new Rating(rating: MultiplayerLimits::GLICKO_DEFAULT_RATING)
            : $this->ratingUpdater->currentRating($user, $category, $now);

        return [
            'uuid' => $user->getId()->toRfc4122(),
            'username' => $user->getUsername(),
            'rating' => $rating->display(),
            'provisional' => $rating->isProvisional(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTimeControlRef(Seek $seek): array
    {
        $timeControl = $seek->getTimeControl();

        return [
            'kind' => strtolower($timeControl->getKind()->name),
            'initialSeconds' => $timeControl->getInitialSeconds(),
            'incrementSeconds' => $timeControl->getIncrementSeconds(),
            'daysPerMove' => $timeControl->getDaysPerMove(),
            'speed' => null !== $timeControl->speedCategory() ? strtolower($timeControl->speedCategory()->name) : null,
        ];
    }

    private function micros(\DateTimeImmutable $dateTime): int
    {
        return (int) $dateTime->format('Uu');
    }
}
