<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\GamePlayer;
use Sidus\FilterBundle\Doctrine\Filter\Type\AbstractDoctrineFilterType;
use Sidus\FilterBundle\Exception\BadQueryHandlerException;
use Sidus\FilterBundle\Filter\FilterInterface;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandlerInterface;
use Sidus\FilterBundle\Query\Handler\QueryHandlerInterface;

/**
 * "Games played by <someone>": case-insensitive substring match on a
 * participant's username, email or display name, on either colour.
 *
 * Implemented as an EXISTS subquery on purpose: the generic text filter
 * would LEFT JOIN the players collection once per attribute, multiplying
 * rows (and breaking pagination counts) whenever both players match.
 */
class GamePlayerFilterType extends AbstractDoctrineFilterType
{
    public function handleData(QueryHandlerInterface $queryHandler, FilterInterface $filter, $data): void
    {
        if (!$queryHandler instanceof DoctrineQueryHandlerInterface) {
            throw new BadQueryHandlerException($queryHandler, DoctrineQueryHandlerInterface::class);
        }

        if (!\is_string($data) || '' === trim($data)) {
            return;
        }

        $qb = $queryHandler->getQueryBuilder();
        $uid = uniqid('player', false);
        $qb->setParameter($uid, '%'.addcslashes(mb_strtolower(trim($data)), '%_\\').'%');

        $qb->andWhere(\sprintf(
            'EXISTS (SELECT 1 FROM %s gpf JOIN gpf.user uf WHERE gpf.game = %s AND (LOWER(uf.username) LIKE :%s OR LOWER(uf.email) LIKE :%s OR LOWER(uf.displayName) LIKE :%s))',
            GamePlayer::class,
            $queryHandler->getAlias(),
            $uid,
            $uid,
            $uid,
        ));
    }
}
