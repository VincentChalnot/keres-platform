#!/usr/bin/env node
/**
 * Static front-end checks (no browser, no dependency): `npm run check:front` (CI: lint job).
 * Fails on the things that silently break the Keres design system layout (docs/design-system.md, "Things that bite"):
 *   1. Bulma vocabulary or an `is-*`/`has-*` state class the design system does not define,
 *   2. a Tailwind `grid` without `grid-cols-*` (grows with its content: sideways scrolling on a phone),
 *   3. a Font Awesome style (`fa-solid`, `fa-regular`, `fa-brands`) that assets/app.js does not import.
 * Class names are read from `class="…"`, `class: '…'`, `'class' => '…'`, `className = '…'` and `classList.add('…')`.
 * Add a legitimate exception to ALLOWED_EXTRA (with a reason), never to the scanned files.
 */
import {readdirSync, readFileSync, statSync} from 'node:fs';
import {join, relative} from 'node:path';

const ROOT = new URL('..', import.meta.url).pathname;
const DS_SRC = join(ROOT, 'node_modules/@keres/design-system/src');

/** Class names of the old Bulma markup that mean nothing here (the design system's equivalents are in the docs). */
const BULMA = new Set(`button buttons box notification columns column control field-label field-body level level-left
level-right level-item title subtitle heading delete tags table-container media container content hero-body menu-list
menu-label navbar-item navbar-brand navbar-menu navbar-start navbar-end navbar-burger modal-background modal-card
modal-card-head modal-card-title modal-card-body modal-card-foot pagination-previous pagination-next pagination-list
pagination-link pagination-ellipsis has-addons has-text-centered has-text-right has-text-grey has-text-grey-light
has-text-white has-text-weight-bold has-text-weight-semibold is-fullwidth is-rounded is-outlined is-light is-dark
is-primary is-link is-info is-success is-warning is-danger is-white is-medium is-large is-flex is-multiline is-half
is-centered is-vcentered is-grouped is-expanded is-hoverable is-narrow is-size-7 is-family-monospace`.split(/\s+/));

/** `is-*`/`has-*` classes that are not defined in the design system CSS but are fine (reason in the comment). */
const ALLOWED_EXTRA = new Set([]);

const errors = [];
const fail = (file, message) => errors.push(`${relative(ROOT, file)}: ${message}`);

function walk(dir, extensions, out = []) {
    for (const name of readdirSync(dir)) {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            walk(path, extensions, out);
        } else if (extensions.some((ext) => name.endsWith(ext))) {
            out.push(path);
        }
    }

    return out;
}

// State classes the design system styles: every `.is-foo` / `.has-foo` of its CSS.
const dsState = new Set(ALLOWED_EXTRA);
for (const file of walk(DS_SRC, ['.css'])) {
    for (const [, name] of readFileSync(file, 'utf8').matchAll(/\.((?:is|has)-[a-z0-9-]+)/g)) {
        dsState.add(name);
    }
}

const files = [
    ...walk(join(ROOT, 'templates'), ['.twig']),
    ...walk(join(ROOT, 'assets/typescript/src'), ['.ts']),
    ...walk(join(ROOT, 'src'), ['.php']),
    ...walk(join(ROOT, 'config/admin'), ['.yaml']),
    ...walk(join(ROOT, 'config/datagrid'), ['.yaml']),
    join(ROOT, 'assets/app.js'),
];

const CLASS_ATTRIBUTE = /(?:\bclass(?:Name)?\s*[=:]\s*|['"]class['"]\s*=>\s*)(["'`])((?:(?!\1)[^\\]|\\.)*?)\1/gs;
const CLASS_LIST = /classList\.(?:add|remove|toggle|contains|replace)\(([^)]*)\)/g;

/** Class tokens of one `class` value, with Twig/template interpolations removed. */
const tokens = (value) => value
    .replace(/\{\{.*?\}\}|\{%.*?%\}|\$\{[^}]*\}/gs, ' ')
    .split(/\s+/)
    .filter(Boolean);

for (const file of files) {
    const source = readFileSync(file, 'utf8');
    const groups = [];

    for (const match of source.matchAll(CLASS_ATTRIBUTE)) {
        groups.push(tokens(match[2]));
    }
    for (const match of source.matchAll(CLASS_LIST)) {
        groups.push([...match[1].matchAll(/['"]([^'"]+)['"]/g)].map((m) => m[1]));
    }

    for (const group of groups) {
        for (const token of group) {
            const base = token.replace(/^.*:/, '').replace(/^!/, '');

            if (dsState.has(base)) {
                continue; // e.g. `.game-button.is-danger` is the design system's own
            }

            if (BULMA.has(base)) {
                fail(file, `Bulma class "${token}" (see the Bulma → DS table in docs/design-system.md)`);
            } else if (/^(?:is|has)-[a-z0-9-]+$/.test(base) && !dsState.has(base)) {
                fail(file, `"${token}" is not a state class of the design system`);
            }
        }

        // Twig/TS sources only: a grid with no explicit column count.
        if (group.includes('grid') && !group.some((token) => /^(?:[a-z0-9]+:)*grid-cols-/.test(token))) {
            fail(file, `class "grid" without grid-cols-* (write "grid grid-cols-1 md:grid-cols-N")`);
        }
    }
}

// Font Awesome styles used vs imported.
const app = readFileSync(join(ROOT, 'assets/app.js'), 'utf8');
const used = new Map();
for (const file of files.filter((f) => !f.endsWith('.php') || f.includes('/templates/'))) {
    for (const [, style] of readFileSync(file, 'utf8').matchAll(/\bfa-(solid|regular|brands)\b/g)) {
        used.set(style, file);
    }
}
for (const [style, file] of used) {
    if (!app.includes(`@fortawesome/fontawesome-free/css/${style}.min.css`)) {
        fail(file, `uses fa-${style} but assets/app.js does not import its stylesheet`);
    }
}

if (errors.length > 0) {
    console.error(`check:front found ${errors.length} problem(s):\n- ${[...new Set(errors)].join('\n- ')}`);
    process.exit(1);
}

console.log(`check:front OK (${files.length} files, ${dsState.size} DS state classes)`);
