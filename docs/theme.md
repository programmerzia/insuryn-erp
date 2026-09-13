# Theme tokens

The visual system follows `docs/ux-design-brief.md` §2. Colours come from the CoreBari palette
(`corebari/src/styles/globals.css`), mapped onto the brief's semantic token names. The token file is
`resources/css/theme/corebari.css`. It is the only file in the repository that may contain a colour value;
`resources/js/tests/theme.test.ts` fails on a colour, a retired palette class, an all-caps label, an
off-scale font size or an off-rule radius anywhere else.

## Where the brief and CoreBari differ

The user's instruction was to keep CoreBari's typeface, logo and accent, and to follow the brief for
everything else.

| Brief §2 | Applied | Why |
|---|---|---|
| Accent `#1F5F8B` (steel blue) | CoreBari Brick `#A8462A` | User instruction: keep the CoreBari accent |
| IBM Plex Sans, no second display face | IBM Plex Sans only; IBM Plex Sans Condensed and Plex Mono dropped | The typeface is the same; the brief forbids a second display face and monospace body text |
| Neutral dark mode `#14171B` / `#1C2025` | CoreBari Navy `#0B1330` / Surface `#101A3D` | CoreBari is dark-first on navy |
| `warn` `#B7791F` | `#94600F` in light mode | `#B7791F` measures 3.64:1 on white, below AA for text |
| Ten tokens | Ten tokens plus six companions (below) | AA needs separate tones for accent as a fill and accent as text, and for control borders versus hairlines |

## Tokens

Ratios are WCAG contrast against `surface` / `surface-2`, computed by `theme.test.ts` (text needs 4.5,
control borders and focus rings need 3).

| Token | Light | Dark (CoreBari) | Use | Light ratio | Dark ratio |
|---|---|---|---|---|---|
| `surface` | `#FFFFFF` | Navy `#0B1330` | page and panel background | — | — |
| `surface-2` | `#F4F5F8` | Surface `#101A3D` | table header, sidebar, inset areas, row hover | — | — |
| `line` | `#DDE1E8` | Blueprint at 16% | hairline borders, dividers (decorative) | — | — |
| `ink` | Navy `#0B1330` | Ivory `#F4F1E9` | primary text | 17.9 / 16.8 | 16.2 / 15.0 |
| `ink-2` | `#4B5270` | Ivory dim `#B6B2CE` | secondary text, labels | 7.6 / 7.0 | 8.9 / 8.3 |
| `accent` | Brick `#A8462A` | Brick `#A8462A` | primary button fill, selection bar | — | — |
| `accent-soft` | `#F6EDEA` | `#2E1E2F` | selected row | — | — |
| `ok` | `#1E7F4F` | Green `#4FBF95` | posted, matched, reconciled | 4.9 / 4.6 | 8.0 / 7.5 |
| `warn` | `#94600F` | Amber `#E8B04B` | pending, ageing, soft-locked | 5.3 / 4.9 | 9.3 / 8.7 |
| `danger` | `#B42318` | `#F47067` | failed, unbalanced, locked-period violations | 6.5 / 6.0 | 6.6 / 6.1 |

Companions (not in the brief's table, needed for AA):

| Token | Light | Dark | Use |
|---|---|---|---|
| `accent-text` | Brick `#A8462A` | Brick on tint `#D97E5C` | links and accent-coloured text (Brick fails as text on navy) |
| `accent-hover` | Brick press `#8F3B23` | Brick hover `#B04E2E` | primary button hover |
| `accent-ink` | Ivory `#F4F1E9` | Ivory `#F4F1E9` | text on an `accent` fill (5.2:1) |
| `line-control` | `#7C8599` | Blueprint at 50% | input and select borders (WCAG 1.4.11, ≥ 3:1) |
| `focus` | `#1F6FA8` | Blueprint `#8FD3FF` | focus ring |
| `shadow-float`, `scrim` | navy-based | navy-based | the one shadow (menus, palette, dialogs) and dialog backdrop |

## Type, spacing and shape

- Font: IBM Plex Sans 400/500/600, self-hosted from `@fontsource` (Latin subset), with Noto Sans Bengali
  (Bengali subset) in the same stack. Plex Sans figures are tabular by default; measured in Chrome, "1111"
  and "0000" have the same advance with and without `tnum`. Numeric cells still set `tabular-nums` (`.num`).
- Scale utilities: `text-dense` 12, `text-ui` 13 (body default), `text-body` 14 (inputs), `text-section`
  16, `text-title` 20. Sentence case; no all-caps, no eyebrow labels.
- 4px grid. Controls are 32px (`h-8`). Table rows use `--row-h`: 32px compact, 40px comfortable (the
  `data-density` attribute on `<html>`).
- Radius: `rounded-control` 4px, `rounded-panel` 6px, none on tables. `shadow-float` only on floating layers.
- Logo: the CoreBari mark (`components/Logo.vue`), drawn with `accent` and `ink`.

## Theme switching

`<html data-theme="light|dark">` is an explicit choice; no attribute follows the operating system. The
light palette is complete on `:root`. The dark palette is repeated under
`@media (prefers-color-scheme: dark) :root:not([data-theme="light"])` and `:root[data-theme="dark"]`;
the test checks the two blocks are identical.

## Adding a customer theme

1. Copy `resources/css/theme/corebari.css` to `resources/css/theme/<customer>.css` and change values only.
   Keep every token name and all three blocks.
2. In the customer's build, import that file in `resources/css/app.css` instead of `corebari.css`
   (extension packages set this through their Vite entry; core files are not edited, see CONTEXT.md
   "Customization policy").
3. Add the file to `theme.test.ts` (the `themeCss` path) and run `npm test`. Every pairing must still clear AA.
