<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Model\Admin\UserListRow;
use Sidus\AdminBundle\Action\ActionInjectableInterface;
use Sidus\AdminBundle\Action\ActionInjectableTrait;
use Sidus\AdminBundle\DataGrid\DataGridHelper;
use Sidus\AdminBundle\Request\ActionResponseInterface;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Custom list action for the User admin: annotates each row with computed
 * game-statistics columns (gamesCount, winCount, loseCount, drawCount,
 * lastMoveAt) via correlated subselects, since these have no backing entity
 * property for the datagrid's default query handler to resolve.
 */
#[AsController]
class UserListAction implements ActionInjectableInterface
{
    use ActionInjectableTrait;

    public function __construct(
        protected ?DataGridHelper $dataGridHelper = null,
        protected ?TemplatingHelper $templatingHelper = null,
    ) {
    }

    public function __invoke(Request $request): ActionResponseInterface
    {
        $dataGrid = $this->dataGridHelper->buildDataGridForm($this->action, $request);

        $queryHandler = $dataGrid->getQueryHandler();

        if (!$queryHandler instanceof DoctrineQueryHandler) {
            throw new \UnexpectedValueException('Datagrid QueryHandler must be a DoctrineQueryHandler');
        }
        $alias = $queryHandler->getAlias(); // typically 'e'
        $qb = $queryHandler->getQueryBuilder();
        // Column::renderValue() requires an object per row, not the plain
        // array Doctrine would produce for a full-entity-select mixed with
        // extra scalar addSelect()s — project into UserListRow instead.
        $qb->select(\sprintf(
            'NEW %s(%s, %s, %s, %s, %s, %s)',
            UserListRow::class,
            $alias,
            "(SELECT COUNT(DISTINCT gp.game) FROM App\\Entity\\GamePlayer gp JOIN gp.game g WHERE gp.user = {$alias} AND g.deletedAt IS NULL)",
            "(SELECT COUNT(DISTINCT gp2.game) FROM App\\Entity\\GamePlayer gp2 JOIN gp2.game g2 WHERE gp2.user = {$alias} AND g2.gameOverAt IS NOT NULL AND g2.draw = false AND g2.opponentTypeValue <> 1 AND ((gp2.colorValue = 0 AND g2.whiteWins = true) OR (gp2.colorValue = 1 AND g2.whiteWins = false)))",
            "(SELECT COUNT(DISTINCT gp3.game) FROM App\\Entity\\GamePlayer gp3 JOIN gp3.game g3 WHERE gp3.user = {$alias} AND g3.gameOverAt IS NOT NULL AND g3.draw = false AND g3.opponentTypeValue <> 1 AND ((gp3.colorValue = 0 AND g3.whiteWins = false) OR (gp3.colorValue = 1 AND g3.whiteWins = true)))",
            "(SELECT COUNT(DISTINCT gp4.game) FROM App\\Entity\\GamePlayer gp4 JOIN gp4.game g4 WHERE gp4.user = {$alias} AND g4.draw = true)",
            "(SELECT MAX(gm.createdAt) FROM App\\Entity\\GameMove gm JOIN gm.game gg JOIN gg.players gpp WHERE gpp.user = {$alias})",
        ));

        $dataGrid->handleRequest($request);

        return $this->templatingHelper->renderListAction($this->action, $dataGrid);
    }
}
