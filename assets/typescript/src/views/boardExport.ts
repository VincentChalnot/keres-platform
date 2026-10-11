// Static export of a board SVG: one self-contained HTML file holding `assets/board.css` (verbatim, the one
// stylesheet of the board) and the board `<svg>` exactly as it is displayed - no script, no external request.
// Admin-only tool (English only); `SVGBoardView.exportStaticHtml()` is the entry point, this module is the
// part that does not depend on the view.
import boardCss from './boardCss';

/** What the board sits on in the app: `html { background-color: rgb(var(--k-canvas)) }` (design system, tokens.css). The file has no stylesheet of the DS, hence the literal. The coordinate labels are #ddbd90 and unreadable on white. */
const PAGE_BACKGROUND = '#0d110f';

/** Attributes that only serve interaction or the live view's bookkeeping; the file is static. */
const INTERACTION_ATTRIBUTES = ['data-anim-speed', 'data-tile-index', 'data-from', 'data-to', 'pointer-events'];

const URL_REFERENCE = /url\(\s*['"]?#([^'")\s]+)['"]?\s*\)/g;

/** Properties of the app's page that reach the board SVG: set on the SVG itself, or inherited by its text. See `pageStyleOf`. */
const PAGE_PROPERTIES = ['filter', 'text-rendering', '-webkit-font-smoothing', '-moz-osx-font-smoothing'];

export interface BoardHtmlOptions {
    /** Document title. */
    title: string;
    /**
     * Called with the working copy of the SVG, before the generic clean-up: the place for state that only the caller
     * understands (a drag in progress, a hover). The copy is thrown away afterwards; the SVG passed in is never modified.
     */
    prepare?: (clone: SVGSVGElement) => void;
}

/** Builds the complete HTML document of `svg` in its current state. `svg` is only read. */
export function buildBoardHtml(svg: SVGSVGElement, options: BoardHtmlOptions): string {
    const applied = pageStyleOf(svg);
    const clone = svg.cloneNode(true) as SVGSVGElement;
    options.prepare?.(clone);
    stripTransientState(clone);
    pruneDefinitions(clone);
    stripLayoutWhitespace(clone);

    const markup = new XMLSerializer().serializeToString(clone);

    return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${escapeHtml(options.title)}</title>
<style>
${boardCss}
/* Page (not part of the board): the background of the app, the board centred and scaled to the window. */
${pageCss(clone, applied)}
</style>
</head>
<body>
${markup}
</body>
</html>
`;
}

/** Hands `html` to the browser as a file download. */
export function downloadHtml(html: string, filename: string): void {
    const url = URL.createObjectURL(new Blob([html], {type: 'text/html;charset=utf-8'}));
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.hidden = true;
    document.body.appendChild(link);
    link.click();
    link.remove();
    // Not at once: some browsers start reading the blob after the click returned
    window.setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function escapeHtml(text: string): string {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function pageCss(svg: SVGSVGElement, applied: string): string {
    const box = (svg.getAttribute('viewBox') ?? '').trim().split(/[\s,]+/).map(Number);
    if (box.length !== 4 || !box.every(Number.isFinite) || box[2] <= 0 || box[3] <= 0) {
        throw new Error('The board SVG has no usable viewBox');
    }
    const ratio = `${box[2]} / ${box[3]}`;

    // `svg.keres-board` (board.css) says cursor: pointer, which means nothing here; same specificity, so the later rule wins
    return `html { background: ${PAGE_BACKGROUND}; }
body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
body > svg { display: block; width: 100%; max-width: calc(100vh * ${ratio}); aspect-ratio: ${ratio}; height: auto;${applied} }
svg.keres-board { cursor: auto; }`;
}

/**
 * What the page the board is displayed in does to it, as declarations for the exported SVG: the CSS the app sets on the
 * SVG itself (`filter`: the game-over dimming) and the text settings its labels inherit from the page (Bulma's
 * `text-rendering` / font smoothing). Without them the labels are rasterised differently and the board would only
 * look like the one in the app. Read from the live SVG, so it follows whatever the page does.
 */
function pageStyleOf(svg: SVGSVGElement): string {
    const computed = getComputedStyle(svg);

    return PAGE_PROPERTIES
        .map((name) => [name, computed.getPropertyValue(name).trim()])
        .filter(([, value]) => !['', 'none', 'auto'].includes(value))
        .map(([name, value]) => ` ${name}: ${value};`)
        .join('');
}

/**
 * Leftovers of the live board's animation and interaction that board.css owns: the file shows the end state
 * (a capture already gone, a revived piece already there, nothing hidden, nothing in motion).
 */
function stripTransientState(svg: SVGSVGElement): void {
    svg.querySelectorAll('.piece-captured, .arrow-preview').forEach((element) => element.remove());
    svg.querySelectorAll('.piece-revived').forEach((element) => element.classList.remove('piece-revived'));
    svg.querySelectorAll<SVGElement>('.piece').forEach((piece) => {
        piece.style.removeProperty('visibility');
        piece.style.removeProperty('transition');
        if (!piece.getAttribute('style')) {
            piece.removeAttribute('style');
        }
    });
    for (const element of [svg, ...Array.from(svg.querySelectorAll('*'))]) {
        for (const name of INTERACTION_ATTRIBUTES) {
            element.removeAttribute(name);
        }
    }
}

/**
 * Drops the `<symbol>`s / `<defs>` children nobody uses. An id counts as used when something outside the definitions
 * refers to it (`href`, `xlink:href`, `url(#id)` in an attribute, a `style` attribute, a `<style>` element or board.css),
 * or when a definition that is itself kept does - until nothing new turns up. A definition without any id is kept: it
 * cannot be told to be unused, and a missing one would be a bug where a leftover only costs bytes.
 */
function pruneDefinitions(svg: SVGSVGElement): void {
    const isContainer = (element: Element): boolean => 'defs' === element.localName || 'symbol' === element.localName;
    const definitions: Element[] = [];
    const referenced = new Set<string>();
    collectUrlReferences(boardCss, referenced);
    for (const child of Array.from(svg.children)) {
        if ('defs' === child.localName) {
            definitions.push(...Array.from(child.children));
        } else if (isContainer(child)) {
            definitions.push(child);
        } else {
            collectReferences(child, referenced);
        }
    }

    const kept = new Set<Element>();
    let grew = true;
    while (grew) {
        grew = false;
        for (const definition of definitions) {
            if (kept.has(definition)) continue;
            const ids = [definition, ...Array.from(definition.querySelectorAll('[id]'))].map((element) => element.id).filter(Boolean);
            if (ids.length > 0 && !ids.some((id) => referenced.has(id))) continue;
            kept.add(definition);
            collectReferences(definition, referenced);
            grew = true;
        }
    }

    definitions.filter((definition) => !kept.has(definition)).forEach((definition) => definition.remove());
    Array.from(svg.children)
        .filter((child) => 'defs' === child.localName && 0 === child.childElementCount)
        .forEach((child) => child.remove());
}

function collectUrlReferences(text: string, into: Set<string>): void {
    for (const match of text.matchAll(URL_REFERENCE)) {
        into.add(match[1]);
    }
}

function collectReferences(root: Element, into: Set<string>): void {
    for (const element of [root, ...Array.from(root.querySelectorAll('*'))]) {
        for (const attribute of Array.from(element.attributes)) {
            // localName is `href` for both `href` and `xlink:href`
            if ('href' === attribute.localName && attribute.value.startsWith('#')) {
                into.add(attribute.value.slice(1));
            }
            collectUrlReferences(attribute.value, into);
        }
        if ('style' === element.localName) {
            collectUrlReferences(element.textContent ?? '', into);
        }
    }
}

/** Indentation between elements (the sprite sheet is pretty-printed) means nothing in SVG, outside `<text>`; the file stays small without it. */
function stripLayoutWhitespace(svg: SVGSVGElement): void {
    const walker = document.createTreeWalker(svg, NodeFilter.SHOW_TEXT);
    const blank: Node[] = [];
    for (let node = walker.nextNode(); node; node = walker.nextNode()) {
        const parent = node.parentElement;
        if (!/\S/.test(node.textContent ?? '') && parent && 'text' !== parent.localName && 'tspan' !== parent.localName && 'style' !== parent.localName) {
            blank.push(node);
        }
    }
    blank.forEach((node) => node.parentNode?.removeChild(node));
}
