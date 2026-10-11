// `assets/board.css` is the one and only stylesheet of the board SVG. It is bundled as a string
// (`?inline`) so that the same bytes can be (a) injected once into the page, synchronously, before
// the board is attached - no unstyled flash, no extra request, same behaviour under the Vite dev
// server and in the production build - and (b) written verbatim into a static export of the board.
import boardCss from '../../../board.css?inline';

export default boardCss;

const STYLE_ATTRIBUTE = 'data-board-css';

/** Adds the board stylesheet to the document head, once per document. */
export function injectBoardCss(): void {
    if (document.head.querySelector(`style[${STYLE_ATTRIBUTE}]`)) {
        return;
    }
    const style = document.createElement('style');
    style.setAttribute(STYLE_ATTRIBUTE, '');
    style.textContent = boardCss;
    document.head.appendChild(style);
}
