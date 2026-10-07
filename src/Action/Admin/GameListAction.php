<?php

declare(strict_types=1);

namespace App\Action\Admin;

use App\Model\Admin\GameListRow;
use App\Model\PieceColor;
use Sidus\AdminBundle\Action\ActionInjectableInterface;
use Sidus\AdminBundle\Action\ActionInjectableTrait;
use Sidus\AdminBundle\DataGrid\DataGridHelper;
use Sidus\AdminBundle\Request\ActionResponseInterface;
use Sidus\AdminBundle\Templating\TemplatingHelper;
use Sidus\FilterBundle\Query\Handler\Doctrine\DoctrineQueryHandler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

/**
 * Custom list action for the Game admin: every game on the platform
 * (soft-deleted ones included, flagged in the grid), projected into
 * GameListRow so the move count and both players' identities come from
 * correlated subselects rather than per-row lazy loading.
 */
#[AsController]
class GameListAction implements ActionInjectableInterface
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
        $alias = $queryHandler->getAlias();
        $qb = $queryHandler->getQueryBuilder();

        $white = PieceColor::WHITE->value;
        $black = PieceColor::BLACK->value;

        $qb->select(\sprintf(
            'NEW %s(%s, %s, %s, %s, %s, %s)',
            GameListRow::class,
            $alias,
            "(SELECT COUNT(gm.id) FROM App\\Entity\\GameMove gm WHERE gm.game = {$alias})",
            "(SELECT IDENTITY(gpw.user) FROM App\\Entity\\GamePlayer gpw WHERE gpw.game = {$alias} AND gpw.colorValue = {$white})",
            "(SELECT uw.username FROM App\\Entity\\GamePlayer gpwu JOIN gpwu.user uw WHERE gpwu.game = {$alias} AND gpwu.colorValue = {$white})",
            "(SELECT IDENTITY(gpb.user) FROM App\\Entity\\GamePlayer gpb WHERE gpb.game = {$alias} AND gpb.colorValue = {$black})",
            "(SELECT ub.username FROM App\\Entity\\GamePlayer gpbu JOIN gpbu.user ub WHERE gpbu.game = {$alias} AND gpbu.colorValue = {$black})",
        ));

        $dataGrid->handleRequest($request);

        return $this->templatingHelper->renderListAction($this->action, $dataGrid);
    }
}
