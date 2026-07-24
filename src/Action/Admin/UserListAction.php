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
            "(SELECT COUNT(g.id) FROM App\\Entity\\Game g WHERE g.owner = {$alias} AND g.deletedAt IS NULL)",
            "(SELECT COUNT(g2.id) FROM App\\Entity\\Game g2 WHERE g2.owner = {$alias} AND g2.gameOverAt IS NOT NULL AND g2.draw = false AND ((g2.isWhite = true AND g2.whiteWins = true) OR (g2.isWhite = false AND g2.whiteWins = false)))",
            "(SELECT COUNT(g3.id) FROM App\\Entity\\Game g3 WHERE g3.owner = {$alias} AND g3.gameOverAt IS NOT NULL AND g3.draw = false AND ((g3.isWhite = true AND g3.whiteWins = false) OR (g3.isWhite = false AND g3.whiteWins = true)))",
            "(SELECT COUNT(g4.id) FROM App\\Entity\\Game g4 WHERE g4.owner = {$alias} AND g4.draw = true)",
            "(SELECT MAX(gm.createdAt) FROM App\\Entity\\GameMove gm JOIN gm.game gg WHERE gg.owner = {$alias})",
        ));

        $dataGrid->handleRequest($request);

        return $this->templatingHelper->renderListAction($this->action, $dataGrid);
    }
}
