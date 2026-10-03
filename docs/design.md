# The look: soft pop

The app is styled as **soft pop**: a cream page with a dot grid, a yellow bar, white cards
lifted off the page by a hard offset shadow, pastel colour where a figure has a colour of its
own, and amber for every line, band and hover in between. It replaced a neutral slate theme
in October 2026 (commits `52ebb9e` and `27446e1`).

Almost all of it is `resources/css/app.css`, with the brand colours in
`resources/js/plugins/quasar.js`. This document says what each piece is for, so a new page
can match it, and records the decisions that look wrong and are not.

## The old design

The slate theme is kept on the branch **`pre-redesign`** (commit `638213a`, the last one
before the change). To look at it:

```bash
git switch pre-redesign
npm install        # the redesign changed the font packages
npm run build
```

and `git switch main`, `npm install`, `npm run build` to come back.

## Principles

- **Amber is the furniture.** Anything that is not content -- card edges, row rules, bands,
  hovers, field outlines, chart grids -- is a shade of the one amber, `--app-border`. A grey
  line or a slate hover is the old theme left behind, and is a bug.
- **A coloured card keeps its colour inside.** Home's four headline cards and Net worth's
  Cash, Owed and Stocks cards each have a colour, and everything drawn on them is that
  colour: no amber lands on a coloured card.
- **Edges are offset shadows, not borders.** A card is white with a hard `3px 3px 0`
  shadow. There are no hairlines around cards.
- **Data colours are not theme colours.** Chart series, the swatches of a symbol or a
  category, red and green for money: those are unchanged, and a theme edit leaves them be.

## Type

| Face | Used for | Package |
| --- | --- | --- |
| Space Grotesk | Everything: set on `body` | `@fontsource-variable/space-grotesk` |
| Bricolage Grotesque, 800 | The wordmark, Home's big figures, the sign-in code | `@fontsource-variable/bricolage-grotesque` (`opsz.css`) |

Both are installed, not loaded from Google. Quasar's stylesheet sets a family on `body` and
nowhere else, so the one rule there changes the whole app; Roboto is no longer loaded.

Space Grotesk's digits are tabular under `.money`, so right-aligned columns still line up.
A new face must be checked for that before it is used on money.

## Colour

### Brand (`plugins/quasar.js`)

| Role | Value | Note |
| --- | --- | --- |
| primary | `#2b59ff` | The one interactive colour: buttons, links, focus |
| positive | `#047857` | Kept dark: a lighter green is under 4.5:1 on white at text size |
| negative | `#e11d48` | |

The fallback tints and inks on `body` in `app.css` are hex copies of these for browsers
without `color-mix()`. Change a brand colour and recompute them.

### The page

| Variable | Value | For |
| --- | --- | --- |
| `--app-ground` | `#fffbeb` | The page, under a dot grid drawn on `body` |
| `--app-surface` | `#ffffff` | Cards, the drawer |
| `--app-border` | `#f3d27a` | The amber: card shadows, outlines, panel heads |
| `--app-row-rule` | amber at 55% | The rule between rows, every list and table |
| `--app-hover` | amber at 22% | Every row's hover |
| `--app-header-bg` | `#fff7db` | A band: a currency's heading, a group row |
| `--app-panel-bg` | `#fffcf0` | A large box: a form's type panel, the filter panel, a highlighted tile |
| `--app-track` | `#fdf8ea` | The empty part of every bar |

Fixed colours outside the variables:

| Colour | Where |
| --- | --- |
| `#ffde59` yellow | The app bar, table header rows, the sign-in code |
| `#ff5ca8` pink | The wordmark's stop, the current page in the drawer (as `#ffd6e8` with `#9d174d` text), the drawer's hover and ripple |
| `#f0b44f` | The amber of shadows and toggle hovers |
| `#e0a23a` / `#b45309` | A field's hover and focus ring / a focused field's label |
| `#334155` slate | Ink on yellow and on the coloured cards |

### Coloured cards

Each sets two variables, and every rule inside the card reads them:

| Card | `--card-fill` | `--card-ink` |
| --- | --- | --- |
| Net worth | `#e2dafe` lavender | `#8b5cf6` |
| Cash | `#c3f7d6` mint | `#22a35a` |
| Stocks | `#bee9fe` sky | `#0ea5e9` |
| Cards owe, Owed | `#fed2d7` rose | `#f43f5e` |

From those two, a card draws:

- its head (the figure on Home, the title strip on Net worth) in `--card-fill`;
- its shadow in `--card-ink` at 70%;
- bands at `--card-fill` 40% mixed with white, totals at 60%;
- rows on white, ruled in `--card-ink` at 18–22%, hovered at `--card-fill` 30%;
- a change pill as a white sticker, its text green or red, its shadow in `--card-ink`.

Home's cards are `.app-home-headline__card--{key}`, keyed by the figure, not by position, so
reordering them keeps their colours. Net worth's are `.app-worth-card--{cash,owed,stocks}`,
and the phone page's `.app-simple-card--{worth,cash,stocks,cards}`, each a coloured title
strip over white. A new coloured card needs only a class that sets the two variables.

## The pieces

**Cards.** 20px corners, white, `3px 3px 0` amber shadow; a card inside a card, `2px`. Every
`q-card` and `q-table__container` gets this, so a new card needs nothing.

**Drawer.** White, edged in amber. Each page is an emoji: Noto's, as SVG files in
`resources/images/emoji/`, imported in `layout.vue`. The current page is a pale pink tab.

**Buttons.** 12px corners and bold. The tinted `.app-btn` buttons (Add, Submit, Settle,
Delete) have a `2px` offset shadow in their own colour and press flat into it. The header's
split Add has a blue one.

**Tab bar.** The phone's two pages, Home and Transactions, in a white bar at the foot edged
in amber above, each its drawer emoji with no label: the others greyed, the current one in
colour and lifted over a pink dot. Adding a transaction is the round blue button between them, raised half out of the
bar and ringed in its white, the one button with no offset shadow.

**Toggles.** Every `q-btn-toggle` chooses with `toggle-color="amber-3"` and
`toggle-text-color="grey-9"`. A new one takes the same two props.

**Badges.** Pills. An outlined badge is filled with its own colour at 12% instead.

**Fields.** Outlined in amber; amber on hover; a 2px amber ring and a brown label on focus.
A field in error keeps Quasar's red.

**Tables.** A yellow header row, amber row rules, amber hover. A long list's header is
pinned under the app bar with `useBarHeight()` (Positions, and Dividends' symbols).

**Charts.**

- Grids `#f8e6b6`, the zero line `#c9a96a` taupe. It is duller than amber on purpose, so it
  is not read as Cash flow's orange Net line.
- Lines are 1.5.
- Stacked bars meet at a half-unit `seam`.
- Legend keys are round dots.
- A typical-spending estimate is striped with `.app-estimate`.
- An empty month in the dividend heat map is a dot.

## Decisions that look wrong

Each of these has been "fixed" back to the wrong thing once, or would be.

- **The card shadow is `!important`.** A flat card carries Quasar's `no-shadow`, which is
  `!important` itself. Without it, every flat card on the dotted page has no edge at all.
- **Buttons are rounded through `:where()`.** At full weight the rule beat Quasar's
  `.q-btn-group > .q-btn-item`, and the Type and Status segments bulged inside their box.
- **The hover is translucent, not a cream hex.** `q-table` draws its hover as an overlay
  on the cell, and an opaque one hides the row's text.
- **Focus skips `.q-field--error`.** Otherwise a field that failed validation loses its red.
- **Chart colours are hex, not variables.** An SVG presentation attribute cannot read a
  CSS variable.
- **An estimate's colour is `--estimate`, not `background`.** An inline `background`
  shorthand resets the image layer and wipes the stripes (`HomeMonths.vue`'s `paint`).
- **The emoji are images, not text.** A machine without an emoji font draws an empty box,
  and `@fontsource`'s Noto Color Emoji rendered nothing at all, even where emoji work.
- **Two-class selectors in the drawer.** `.app-drawer .q-item` sets the item's ink, and a
  one-class `.app-nav-active` lost to it, so the current page's label stayed slate.

## Checking a change

There is no test of how anything looks. Lint and build pass on a page that renders wrong, so
a style change is checked in a browser: see *Looking at it* in `AGENTS.md` for running
Playwright here. Read the computed colour, not only a screenshot. Wait out Quasar's 0.3s
colour transitions before reading, or a value is read halfway there.

A quick sweep for the old theme left behind:

```bash
grep -nE "#f8fafc|#f1f5f9|#e2e8f0|#94a3b8|rgba\(0, 0, 0, 0\.0[0-9]" resources/css/app.css
```

Grey text, the small icon chips (`.app-tx-icon`, `.app-soon__kind`, the toolbar counts) and
the zero tick in a return bar are meant to be slate. A slate border, hover or bar fill is the
old theme left behind.
