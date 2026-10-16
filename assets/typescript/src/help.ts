/**
 * Piece diagrams and the help drawer (templates/actions/_help_content.html.twig, _diagram_modal.html.twig):
 * - `[data-enlarge]` images open enlarged in `#piece-diagram-modal` (help content, piece-info panel);
 * - in the drawer, `[data-help-piece]` buttons show one piece panel (`[data-help-piece-panel]`) at a time.
 * The modals themselves open and close through the generic `[data-modal-open]` / `data-dismissible` handlers of app.js.
 */
export function initHelp(): void {
    document.addEventListener('click', (event) => {
        if (!(event.target instanceof Element)) {
            return;
        }

        const image = event.target.closest<HTMLImageElement>('img[data-enlarge]');
        const modal = document.getElementById('piece-diagram-modal');
        const target = document.getElementById('piece-diagram-modal-img');
        if (image && modal && target instanceof HTMLImageElement) {
            target.src = image.src;
            target.alt = image.alt;
            modal.classList.add('is-active');
            return;
        }

        const button = event.target.closest<HTMLButtonElement>('[data-help-piece]');
        if (button) {
            const type = button.dataset.helpPiece;
            document.querySelectorAll<HTMLElement>('[data-help-piece-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.helpPiecePanel !== type;
            });
            document.querySelectorAll<HTMLButtonElement>('[data-help-piece]').forEach((other) => {
                other.setAttribute('aria-pressed', String(other === button));
            });
        }
    });
}
