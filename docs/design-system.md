# Design system (`@keres/design-system`)

The platform is styled by the Keres design system, a separate repository
([keres-design-system](https://github.com/VincentChalnot/keres-design-system), checked out next to this one
as `../keres-design-system` when you work on it). It is **Tailwind 3**: a preset (colours, fonts, radii,
shadows, z-indexes) + one CSS entry (`src/keres.css`) with the tokens (`--k-*`), the base layer and the
components (`.btn`, `.card`, `.alert`, `.navbar`, `.modal` …). Its style guide (`styleguide/index.html`,
`game.html`, `game-frame.html`) is the visual reference: **copy the markup from there**.

Bulma and `assets/app.scss` are being replaced by it, page by page. Until the last page is migrated both are
loaded (see [Migration status](#migration-status-and-coexistence-with-bulma)).

Contents: [How it is wired](#how-it-is-wired) · [Running it](#running-it-dev-ci-production) ·
[Updating the DS](#updating-or-extending-the-design-system) · [Conventions](#conventions) ·
[Bulma → DS class mapping](#bulma--ds-class-mapping) · [Verifying a migrated page](#verifying-a-migrated-page)

## How it is wired

| Piece | Where | Role |
|---|---|---|
| Dependency | `package.json`: `"@keres/design-system": "github:VincentChalnot/keres-design-system#vX.Y.Z"` | A **git dependency pinned to a tag**; `package-lock.json` pins the exact commit. The repo is public, no token needed. |
| Tailwind config | `tailwind.config.js` | `presets: [@keres/design-system/tailwind.preset]`, `content` globs, `corePlugins.container: false`. |
| PostCSS | `postcss.config.js` | `tailwindcss` + `autoprefixer` (Vite inlines `@import` itself, no `postcss-import`). |
| CSS entry | `assets/design-system.css` | `@import "@keres/design-system/keres.css"`: tokens, Tailwind base/components/utilities, DS components. Platform-only rules would go below it. |
| JS entry | `assets/app.js` | imports `app.scss` (Bulma, temporary) **then** `design-system.css`, so the DS wins when a class name exists in both. |
| Fonts | `public/fonts/` | The DS `@font-face` rules point at `/fonts/*` (Carolingia, RomanSerif, Source Serif 4). Copy of `node_modules/@keres/design-system/styleguide/fonts/*`. |
| Form theme | `templates/form/ds_layout.html.twig`, registered in `config/packages/twig.yaml` | Symfony forms render with the DS form classes. |
| Layout | `templates/base.html.twig`, `templates/admin/base.html.twig`, `templates/_language_switcher.html.twig` | Navbar, flash messages, confirm modal, footer. |
| Behaviour | `assets/typescript/src/navbar-toggle.ts`, `notifications/NotificationBell.ts`, `utils/modal.ts` | Toggle the DS state classes (`is-active`, `is-unread`…) and `hidden`. |

The `app` entry is the only one that loads the CSS (`vite_entry_link_tags('app')` in `base.html.twig`); `play`,
`admin` and `lobby` bring none. The board SVG is **not** part of it: `assets/board.css` is bundled as a string
(`?inline`) and injected by `SVGBoardView`, see `AGENTS.md`. Nothing in this setup touches that.

### How Tailwind finds the classes (read this before using a dynamic class name)

Tailwind generates a utility only if its **complete** name appears as a word in a file matched by
`content`: `templates/**/*.twig`, `assets/*.js`, `assets/typescript/src/**/*.ts`, `src/**/*.php`,
`config/admin|datagrid/**/*.yaml`, `translations/**/*.yaml`. So:

- write `text-muted`, never `'text-' ~ tone` or `` `mt-${n}` `` (the scanner cannot see them; use a lookup table
  that spells every class out);
- a class built in PHP (`GameHeaderBadge::$tone`, `Form` `attr`) works as long as the string is in `src/`;
- the DS components (`btn--primary`, `alert--danger`, `tag--soft` …) are always there, whatever you build
  dynamically: the DS sources are in `content` too (`node_modules/@keres/design-system/src/**/*.css`), so
  `'alert--' ~ kind` and `` `btn--${variant}` `` are fine. Only **utilities** need the literal.

`corePlugins.container` is disabled: the DS wrapper is `.container-page`; Tailwind's own `.container` would
override Bulma's on pages not yet migrated.

## Running it (dev, CI, production)

Nothing new to learn: everything stays in Docker (`AGENTS.md`).

```bash
docker compose up -d node                    # installs deps (incl. the DS, needs git: apk add git is in the command) and runs Vite
docker compose exec node npm run build       # production build → public/build/
docker compose exec node npm run type-check
```

- **Dev**: the `node` service runs `apk add --no-cache git && npm install && npm run dev`. `git` is needed because
  npm fetches the DS with it and `node:alpine` has none. Tailwind runs inside Vite (PostCSS): editing a template,
  a TS file or a PHP file regenerates the utilities (HMR). First load after a restart is slow (the CSS is compiled
  on demand) and unstyled for a second or two: Vite injects the CSS with JavaScript in dev only.
- **Production image** (`Dockerfile`, stage `node_builder`): installs `git`, `npm ci`, copies `assets`,
  `templates`, `src`, `config`, `translations` (Tailwind scans them), `vite.config.js`, `postcss.config.js`,
  `tailwind.config.js`, then `npm run build`. The build needs network access to github.com (the DS), nothing else.
  A new directory that holds class names must be added to **both** `tailwind.config.js` (`content`) and that `COPY` list.
- **CI** (`.github/workflows/ci.yaml`): `npm ci` on `ubuntu-latest` (git present) in the `lint` job, then the
  image build above. No workflow change was needed.
- **A fresh checkout** needs nothing but `docker compose up -d` (and `public/fonts` is committed).

### Hacking on the DS from the platform (local checkout)

```bash
# ../keres-design-system next to this repo
docker compose -f compose.yaml -f compose.design-system.yaml up -d node
```

mounts `../keres-design-system` over `node_modules/@keres/design-system` and skips `npm install`; an edit of
`tailwind.preset.js` or `src/**/*.css` reloads the CSS like one of ours (`vite.config.js` un-ignores that path).
Go back to the pinned version with `docker compose up -d node`. Do not run `npm install` while the mount is active.

## Updating or extending the design system

Anything shared and reusable (a component, a variant, a token) belongs in the DS repo, **not** in `app.scss`:

1. Edit `src/components/*.css` (or `tokens.css`, `tailwind.preset.js`) in `../keres-design-system`, add the markup to
   `styleguide/index.html`, check it with the local checkout above.
2. Commit there (conventional commit), bump `version` in its `package.json` (+ lock), `git tag -a vX.Y.Z`,
   `git push origin main --follow-tags`.
3. In the platform: change the tag in `package.json` (`#vX.Y.Z`), then
   `docker compose exec node npm install` and commit `package.json` + `package-lock.json`.
4. If the DS ships new font files, copy them to `public/fonts/` (`cp node_modules/@keres/design-system/styleguide/fonts/* public/fonts/`).

Platform-only CSS (rare) goes in `assets/design-system.css` below the `@import`, written with `@apply` and tokens.
The DS needs `@tailwindcss/typography`, `tailwindcss`, `postcss`, `autoprefixer` in the platform's `devDependencies` (they are).

## Conventions

- **Markup**: the DS block/element/modifier naming: `btn btn--primary`, `card__title`, `alert--danger`,
  `navbar__link`. Utilities (Tailwind) for layout and spacing, DS tokens for colour: `bg-surface`, `text-muted`,
  `border-line`, `text-primary`, `font-title`, `shadow-raised`. **No raw hex/rgb** in templates, TS or CSS: use a
  token (`var(--k-primary)`, `rgb(var(--k-primary) / .2)`). The Tailwind names are in `tailwind.preset.js`.
- **JS state classes keep the `is-*` form** (`is-active`, `is-unread`, `is-current`, `is-loading`, `is-invalid`,
  `is-flipped`, `is-to-move`…): the DS CSS keys on them. `hidden` (attribute or utility) hides, `aria-pressed="true"`
  / `aria-expanded` / `aria-current` are styled too. The DS has no generic `.is-hidden`: use `hidden` (the DS also
  defines `.game-button.is-hidden`, `.game-actions.is-hidden`).
- **Icons**: **Font Awesome stays** (≈ 60 icons, including the brand ones, used from Twig and TS; the DS sprite icons
  are only for its style guide). Wrap them in the DS icon box: `<span class="icon"><i class="fa-solid fa-gear"
  aria-hidden="true"></i></span>` (`navbar__icon` in the navbar). `.btn .icon` is gold on the default button and ink
  on `btn--primary`. The king silhouette is `templates/_icon_king.html.twig`.
- **Forms**: do not pass `attr: {class: 'input'}`, `label_attr: {class: 'label'}` or `button …` from a template or
  a `FormType`: `ds_layout.html.twig` adds `field/label/input/textarea/select/checkbox/radio/help/btn` and
  `is-invalid` on an invalid widget. `<select>` carries `.select` itself (no wrapper). A submit button is
  `btn btn--primary`, reset is `btn btn--outline`; pass `btn--danger`, `btn--sm`… in `attr.class` to change it.
- **Language / i18n / `trans`**: unchanged. Keep `{% trans_default_domain %}` and the en/fr rule of `AGENTS.md`.
- **Responsive**: Tailwind breakpoints `sm 640 · md 768 · lg 1024 · xl 1280 · 2xl 1536`; the navbar switches at
  `lg`; Bulma's `columns` stack below 769px, so use `md:` where you had `columns`.
- **Spacing**: Tailwind's scale is not Bulma's (see the table); `gap-*` on a grid/flex beats margins on children.
- **Board**: `assets/board.css` only, as before. The DS `game.css` mirrors `_play_board.html.twig` (same class
  names as the game-page sections of `app.scss`): migrating the game page = delete those sections of `app.scss`.
- **Page-specific styles** you cannot express with utilities: first look for a DS component, then add one to the DS,
  then (platform-only) `assets/design-system.css`. Do not add to `app.scss`; move what you migrate out of it.
- **Commits**: `git add` explicit paths and `git commit -m … -- <paths>`; never `git add -A` (other workers share the tree).

## Migration status and coexistence with Bulma

Migrated (foundation): `templates/base.html.twig` (navbar, burger, notification bell, language switcher, flash
messages, confirm modal, footer), `templates/admin/base.html.twig` (side menu), the TwigBundle error page,
`templates/_language_switcher.html.twig`, the Symfony form theme, and their TypeScript. Their rules are gone from
`app.scss` (navbar, footer, modal, bell, flash, global typography, `@font-face`, error page, lightbox).

Still Bulma (to migrate, page by page): everything else: `templates/actions/**`, `security/**`, `admin/**` pages,
the SidusDataGrid / SidusAdmin overrides under `templates/bundles/` (datagrid, pager, edit/delete action), the
`new_local_game` / lobby / friends / settings / dashboard / profile pages, the game page chrome, and the TS that
builds Bulma markup (`LobbyController`, `FriendsController`, `app.ts` modals/feedback notification,
`admin/openingExplorer.ts`, `admin/boardEditor.ts`, `GameHeaderBadge::$tone`/`GameHeaderPresenter` tags).

While both are loaded (**`@use 'bulma/sass'` in `app.scss` and `import "./app.scss"` in `app.js` are
TEMPORARY**):

- the DS loads **after** Bulma, so where a class exists in both, the DS rule wins. Same-named classes whose DS
  look differs from Bulma's, so an unmigrated page may look half-and-half: `.field`, `.label`, `.help`, `.input`,
  `.textarea`, `.select` (Bulma wraps the `<select>`, the DS styles the `<select>`: a `div.select` shows a double
  box), `.checkbox`, `.radio`, `.tag`, `.tags`, `.tabs`, `.menu`, `.table`, `.progress`, `.pagination`, `.icon`,
  `.modal`, `.section`, `.footer`, `.navbar`, `.lightbox`, `.error-page`, `.hero`, `.intro`, `.piece-*`;
- Bulma-only classes (`box`, `columns`, `button`, `notification`, `title` …) keep their Bulma look;
- `html[data-theme="dark"]` (in `base.html.twig`) and `body { color: inherit }` (end of the Bulma block in
  `app.scss`) exist only for Bulma;
- the CSS is ~930 kB (Bulma ≈ 800 kB + the DS ≈ 130 kB) until Bulma goes. Final removal (whoever finishes): delete
  `@use 'bulma/sass'`, the "Bulma compatibility" block and the remaining page sections of `app.scss`, `app.scss`
  itself, `import "./app.scss"` in `app.js`, `data-theme="dark"`, `bulma` and `sass` in `package.json`; grep
  `\b(button|box|columns?|notification|is-[a-z]+|has-text-[a-z-]+)\b` in `templates/`, `src/`, `assets/typescript/src/`.
- when you migrate a page, remove the matching sections from `app.scss` in the same commit.

## Bulma → DS class mapping

`→` = replacement. Utilities are Tailwind classes (generated from your templates), components are DS classes.
Dynamic Bulma classes (`'is-' ~ x`) must become literal class names or a lookup table.

### Layout and surfaces

| Bulma | Design system |
|---|---|
| `container` | `container-page` (`is-fluid` → `container-page--fluid`; narrow reading column → `container-page--narrow`) |
| `section` | `section` (`py-8 sm:py-12`; `section--tight`); a page title block: `page-title` + `page-subtitle` |
| `box` | `card` (`card--flush` = no padding, `card--interactive` = lit edge on hover); title: `<h2 class="card__title">` (`card__title--sm`) |
| `card` (card-header/content/footer) | `card` + `card__title`; no sub-components |
| `columns` / `column` / `is-N` | Tailwind grid: `grid gap-4 md:grid-cols-3`; a column spanning N of 12: `md:col-span-N`; `is-half` → `md:col-span-6`, `is-one-third` → `md:col-span-4`; `is-multiline` is the default of `grid`; `is-vcentered` → `items-center`; `is-centered` → `justify-center`; `is-mobile` (columns that never stack) → drop `md:` |
| `level`, `level-left/right/item` | `flex items-center justify-between gap-4` / `flex items-center gap-3`; for a datagrid toolbar `toolbar` + `toolbar__group`; big-number blocks: `stat` (`stat__label`, `stat__value`, `stat__value--warning`), `stat-row` (3 columns) |
| `media`, `block` | `flex gap-3`, `mb-4` |
| `hero`, `hero-body` | `hero`, `hero__content`, `hero__baseline` (`marketing.css`) |
| `footer`, `content` | `footer` (+ `footer__meta`); rich text → `prose-keres` |
| `image is-64x64` / `is-rounded` | `avatar avatar--lg` (4rem), `avatar` (2rem), `avatar--sm`; custom size: `style="--avatar: 3rem"`; other images: `rounded-md`, `max-w-full` |
| `delete` (close ×) | `btn-close` (`alert__close` positions it in an alert) |
| `notification is-X` | `alert alert--X`, X ∈ `success`, `danger`, `info`, `warning`, `brand`; Bulma `is-light` is dropped; `is-dark is-light` → `alert--brand`; dismiss: `<button class="btn-close alert__close">` |
| `message` | `alert` |
| rules-page `.warning`, `.special-note` | `callout` (`callout--warning`) |
| `lightbox`, `lightbox-trigger`, `error-page*` | same names in the DS (`marketing.css`); `lightbox` toggles `is-active` |

### Buttons

| Bulma | Design system |
|---|---|
| `button` | `btn` (default look: dark fill, gold edge, uppercase, min-width 13rem) |
| `button is-primary` | `btn btn--primary` |
| `is-info` / `is-danger` | `btn--info` / `btn--danger` (soft red: `btn--danger-soft`, replaces `is-danger is-outlined|is-light`) |
| `is-success` | no equivalent (no green button): use `btn--secondary` (teal) or `btn--primary` |
| `is-light`, `is-outlined is-light`, `is-white` | `btn--outline` (the neutral look); a solid light button: `btn--light` |
| `is-text`, `is-ghost` | `btn--text` (a row of an unstyled control: also add `btn--sm` when it sits next to text) |
| `is-link` | `btn--outline` + `aria-pressed="true"` (a selected tool) |
| `is-small` / `is-medium` / `is-large` | `btn--sm` / default / `btn--lg` |
| `is-fullwidth` | `btn--block` |
| `is-rounded` | drop (the DS buttons are bevelled); a round icon button: `btn--icon` (+ `btn--sm`) |
| `is-static` | `btn--static` |
| `is-loading` | `is-loading` (same name) |
| toggled `is-primary` / `is-outlined` (lobby presets, `LobbyController.ts`) | `btn btn--outline` toggling `aria-pressed="true|false"` (styled as primary when pressed) |
| `buttons` | `btn-group` (`is-centered` → `btn-group--center`, `is-right` → `btn-group--end`, `has-addons` → `btn-group--joined`) |
| `.icon-button` (game) | `icon-button` (`game.css`) |
| a stacked "label + value" button | `btn--stack` + `btn__value` |

### Forms

| Bulma | Design system |
|---|---|
| `field` | `field` (`is-grouped` → `field--grouped`; inline `field--inline`) |
| `field has-addons` | `input-group` (children: `input`, `select`, `btn`; a "to" label between two inputs is `btn btn--static`) |
| `field is-horizontal` + `field-label` + `field-body` | `field-row` (grid, label left) with a `label` and the control(s) |
| `control` (+ `is-expanded`) | no wrapper: put the control straight in `field` (`flex-1` for `is-expanded`); `has-icons-*` has no equivalent |
| `label`, `help`, `help is-danger` | `label`, `help`, `help help--danger` |
| `input`, `textarea` | same names. `is-danger` → `is-invalid`; `is-success` → `is-valid`; `is-small` → `input--sm`; `is-rounded` dropped; `has-fixed-size` → `resize-none` |
| `<div class="select"><select>` | `<select class="select">` (the class goes on the `<select>`; `select--sm`; always full width) |
| `select is-multiple`, `is-fullwidth` | `<select multiple class="select">`, default |
| `checkbox`, `radio` | same names: `<label class="checkbox"><input type="checkbox"> Label</label>`; groups `checkboxes` / `radios` (`--inline`) |
| `progress is-primary` | `progress` (`progress--sm`), no colour modifier |
| Symfony forms | no class at all, see [Conventions](#conventions) |

### Data, navigation, feedback

| Bulma | Design system |
|---|---|
| `tag` | `tag`; colours `is-primary|success|info|warning|danger` → `tag--primary|success|info|warning|danger`; `is-dark` → `tag--brand`; `is-white` → `tag--white`; `is-light` → `tag--light`; `is-X is-light` (tinted) → `tag--soft tag--X`; `is-small` → `tag--sm`; `is-medium|large` → `tag--lg`; `is-rounded` dropped |
| `tags` | `tag-group` |
| `table` (`is-fullwidth`) | `table` (always full width); `is-hoverable` → `table--hover`; `is-narrow` → `table--compact`; monospace → `table--mono` |
| `table-container` | `table-wrap` |
| `tabs` (`<ul><li class="is-active"><a>`) | `<div class="tabs"><a class="tab is-active">`; `is-toggle` → `tabs tabs--pill` |
| `menu`, `menu-label`, `menu-list` | `menu`, `menu__label`, `menu__list` + `<a class="menu__link is-active">` |
| `pagination`, `-previous` `-next` `-list` `-link` `-ellipsis` | `pagination`, `pagination__prev`, `pagination__next`, `pagination__list`, `pagination__link` (`is-current` same), `pagination__ellipsis`; disabled: `is-disabled` |
| `navbar-*` | done in `base.html.twig`: `navbar`, `navbar__inner|brand|logo|burger|menu|start|end|link|icon|user|avatar`; the language switcher is `lang-switch` |
| `modal`, `modal-background`, `modal-card[-head|-title|-body|-foot]` | `modal` (`is-active`), `modal__backdrop`, `modal__card`, `modal__head`, `modal__title`, `modal__body`, `modal__foot`; the confirm modal is `#app-confirm-modal` (`confirmModal()` / `alertModal()`) |
| `breadcrumb`, `dropdown` | none (not used); the bell panel (`bell__panel`) is the dropdown pattern |
| heading label (`<p class="heading">`) | `eyebrow` |
| `title`, `title is-N` | page title: `page-title`; inside a card: `card__title`; otherwise the element (`h1…h6` are styled by the base layer: serif title font) |
| `subtitle` | `page-subtitle` |
| list rows (`box game-row`, friends, providers) | `list-row` (`__body`, `__action`, `__meta`), `friend-row`/`friend-list`, `provider-row`/`provider-list`, `game-header*` |
| announcements | `announcements*` |
| notification rows (bell, inbox) | `notif`, `notif__link|body|text|time|action`, `notif-list`, `notif-empty` (`is-unread`) |

### Helpers → Tailwind utilities (no DS component)

| Bulma | Tailwind (DS tokens) |
|---|---|
| `has-text-centered` / `-right` / `-left` | `text-center` / `text-right` / `text-left` |
| `has-text-grey`, `-grey-light`, `-grey-dark` | `text-muted` (secondary text), `text-subtle` (hints, disabled) |
| `has-text-white`, `-light` | `text-fg` (body text), `text-white` |
| `has-text-primary` / `-success` / `-warning` / `-info` | `text-primary` / `text-success` / `text-warning` / `text-info` |
| `has-text-danger` | `text-danger-text` (readable red; `text-danger` is the dark fill, for backgrounds/borders) |
| `has-text-weight-semibold` / `-bold` | `font-semibold` / `font-bold` |
| `has-background-*` | `bg-surface` (cards), `bg-sunken` (wells), `bg-brand` (dark gold), `bg-canvas` (page) |
| `is-size-7` / `6` / `5` / `4` / `3` | `text-xs` / `text-base` / `text-xl` / `text-2xl` / `text-3xl` (`is-size-1,2` → `text-5xl`, `text-4xl`) |
| `is-uppercase`, `is-italic`, `is-underlined`, `is-family-monospace` | `uppercase`, `italic`, `underline`, `font-mono` |
| `is-flex`, `is-inline-flex`, `is-flex-wrap-wrap`, `is-flex-direction-column`, `is-flex-grow-1` | `flex`, `inline-flex`, `flex-wrap`, `flex-col`, `grow` |
| `is-justify-content-space-between` / `-center` / `-flex-end`, `is-align-items-center` | `justify-between` / `justify-center` / `justify-end`, `items-center` |
| `is-hidden`, `is-hidden-mobile`, `is-invisible`, `is-sr-only`, `is-clipped`, `is-pulled-right` | `hidden`, `max-md:hidden`, `invisible`, `sr-only`, `overflow-hidden`, `float-right` / `ml-auto` |
| `is-fullwidth`, `is-expanded` (layout) | `w-full`, `flex-1` |
| `m*-N` / `p*-N` | Bulma 0–4 = Tailwind 0–4 (0, .25, .5, .75, 1 rem): unchanged. **Bulma 5 (1.5rem) → `*-6`; Bulma 6 (3rem) → `*-12`.** `mx-auto`, `ml-auto` unchanged |
| Sass `$keres-primary`, `-secondary`, `-light`, `-dark`, `-bg` | `primary`, `soft`, `fg`, `brand` (fills) or `line`/`edge` (borders), `canvas`; raw `#1a1a1a`/`#111` → `surface`/`sunken`/`panel`; `#2a2a2a`/`#1f1f1f` borders → `edge` |
| CSS vars `--primary-color`, `--secondary-color`, `--light-color`, `--dark-color`, `--site-navbar-height` | `--k-primary`, `--k-soft`, `--k-fg`, `--k-brand`, `--k-navbar-h` (the old names still resolve while `app.scss` lives; the `--k-*` ones are RGB triplets: `rgb(var(--k-primary) / .5)`) |

Design tokens (Tailwind names, see `tailwind.preset.js`): colours `canvas sunken inactive surface surface-hover line
line-hi line-lo edge fg soft muted subtle ink primary(-hover|-hi|-lo) secondary brand success danger danger-text
warning info turn turn-text panel popover tile-* piece-*`; fonts `font-sans|body|title|display|mono`
(`font-display` = the logo only); `rounded-sm|md|lg|game` (bevelled, not round; `rounded-full` stays round);
`shadow-raised|modal|cta`; `max-w-container|section|hero`; `h-navbar`; `z-navbar|dropdown|modal|lightbox`;
`animate-pop`.

### Gaps found (no DS equivalent)

- `button is-success` (no green button), `control has-icons-left|right`, `breadcrumb`, `dropdown`, generic
  `.is-hidden`: pick the closest DS component or a utility as above; if a real need appears, add it to the DS.
- Board editor tool palette (`board-editor__*`) and opening-explorer tree styling have no DS component yet: the
  admin pages worker adds them to the DS (`data.css` already has `.tree`).

## Verifying a migrated page

```bash
docker compose exec php bin/console lint:twig templates
docker compose exec php bin/console lint:container
docker compose exec node npm run type-check
docker compose exec node npm run build -- --outDir /tmp/keres-build --emptyOutDir   # does not touch public/build (the dev server's manifest)
composer cs:check          # only if PHP changed
```

Then look at the page at `https://app.local.playkeres.com` (log in with `/dev/login?email=…@example.com`), desktop
and 390 px wide. Hints:

- Vite in dev injects the CSS with JavaScript, so a screenshot taken straight after load can show UA-default link
  colours: wait a few seconds or run `document.getAnimations().forEach(a => a.finish())` first.
- The style guide is the reference; compare your markup with the `<section>` of the component in
  `node_modules/@keres/design-system/styleguide/index.html`.
- An admin page needs `docker compose exec php bin/console app:user:promote <email>` and a new `/dev/login`.
