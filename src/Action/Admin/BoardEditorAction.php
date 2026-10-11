<?php

declare(strict_types=1);

namespace App\Action\Admin;

use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page shell of the board editor: build a position from scratch on the game's own
 * board renderer, draw arrows, export it as static HTML. Everything happens in the
 * browser (assets/typescript/src/admin/boardEditor.ts) - there is no endpoint
 * behind it and the position is never stored server-side, so PHP knows nothing
 * about boards or pieces here.
 */
#[AsController]
class BoardEditorAction
{
    #[Route(path: '/admin/board-editor', name: 'admin_board_editor', methods: ['GET'])]
    public function __invoke(): array
    {
        return [];
    }
}
