# AGENTS.md — Prime City

Project instructions for any agent working in this repository. **Read this before touching UI code.**
The detailed, copy‑paste style guide lives in [`docs/ui-conventions.md`](docs/ui-conventions.md).

---

## 0. Trigger phrase: “Update the UI for this”

When the client says **“update the UI for this”** (or “modernise this page”, “make this modern and
professional”, “fix the UI of X”), do **not** ask what they want — run this playbook on the named page.
It encodes every correction they have already given me.

### 0.1 Read first (never skip)

```
<page>.php                        markup + context vars
actions/<page>-actions.php        what data exists (fields to show, statuses, branches)
assets/css/<page>-style.css       current skin
assets/js/<page>.js               plugin init (DataTables / simple-datatables / Select2)
```

### 0.2 Playbook, in order

| # | Step | What “done” looks like |
|---|---|---|
| 1 | **Shell** | `.dash` canvas + `.dash-head` present; every block is `.card.dash-card` with `.dash-card__head` (icon + title + hint) |
| 2 | **Card heads** | gradient/ghost action buttons; wrap on mobile; ≤575 px stack the actions full width |
| 3 | **Forms** | split into `.form-section` + `.section-title` (green dot, uppercase); 3.1 rem rounded controls with brand focus ring; `.dash-form__actions` with a right-aligned gradient submit |
| 4 | **Search + filter** | one toolbar row **directly above `.table-responsive`**: search left (`flex: 1 1 280px`, magnifier), filter chip right (`margin-left:auto`), top spacing `1.15rem`; hide any built-in plugin search |
| 5 | **Tables** | uppercase muted heads on `#f7fbff`, hairline rows, hover/zebra, tabular numbers, branch/badge pills, **soft 30 px icon buttons** for row actions |
| 6 | **Statuses** | every status rendered as a pill (map *all* values in PHP — unmapped = invisible) |
| 7 | **Footer / pager** | pill pager flush **right**, info left; footer lifted **out** of the horizontal scroller; 10 rows per page; **no length dropdown** |
| 8 | **Modals** | `modal-xl modal-dialog-centered`, gradient header + icon + hint, sectioned body, ghost Cancel + gradient save; Select2 panels restyled |
| 9 | **Mobile** | card body must not scroll internally; toolbar/reset search `flex: 0 0 auto`; head wraps; footer **centred**; zero horizontal overflow at 390 / 576 / 768 px |
| 10 | **Assets** | `<page>-style.css` / `<page>.js` links carry `?v=filemtime(...)` |
| 11 | **Verify** | §4 recipe: lint, `node --check`, brace parity, 41-page regression, screenshots |
| 12 | **Report** | short table of verified facts + screenshot, one line per change area |

### 0.3 Never break while restyling

- Keep every `id`, `name`, `onclick`, `oninput` and JS hook the page relies on.
- Keep the form action / enctype / required attributes.
- Keep existing columns, data and permissions logic untouched — this is a **UI** pass.
- Don’t touch print/generate pages, `login.php`, `index.php`, `*_menu.php`, `customer_view_payment.php`.

### 0.4 Pages already following these conventions

Treat these as the reference implementations — copy their patterns instead of inventing:

`add_collector_loan.php` · `add_collector_payments.php` · `add_investment.php` · `add_expenses.php` ·
`gcash_in_out.php` · `upload_attendance.php` · `payroll.php` · `customers_multiple_payment.php` ·
`payment_history.php` · `add_financing.php`

For any page not on that list, run the full playbook.

### 0.5 If a request conflicts with a rule above

The **newest client instruction wins**. Apply it, then update this file and
`docs/ui-conventions.md` in the same turn so the record stays true.

---

## 1. What this project is

Legacy PHP admin app for a lending/collection business, being modernised page by page.

| | |
|---|---|
| Path | `E:\laragon\www\Prime_City` |
| URL | `http://localhost/Prime_City/` |
| Runtime | PHP 8.3.x · Apache (Laragon) · MySQL 8.4.x (`db_prime_city`) |
| DB access | `config/database.php` → `db()` (mysqli singleton), `db_pdo()`, `env()` |
| Version control | **none** — there is no git. Every change is manual; verify after each edit. |

### Layout conventions

```
<page>.php                     thin view: context vars + markup
actions/<page>-actions.php     logic + queries, functions prefixed per page (acl_, acp_, ua_, pr_ …)
api/<page>-endpoint.php        JSON endpoints
assets/css/<page>-style.css    page-scoped stylesheet
assets/js/<page>.js            page behaviour (IIFE, exports via window.*)
includes/topbar.php            chrome, uses $layout_active / $user
includes/sidebar.php           chrome, uses $layout_menu
includes/footer.php            chrome
```

Asset links must be cache‑busted, following the existing convention:

```php
<link href="assets/css/<page>-style.css?v=<?php echo is_file(__DIR__ . '/assets/css/<page>-style.css') ? filemtime(__DIR__ . '/assets/css/<page>-style.css') : 1; ?>" rel="stylesheet" />
```

---

## 2. THE UI RULES (this is what the client keeps asking for)

> "Modern and professional UI" is the standing requirement for **every** page. The rules below are the
> client's decisions, learned the hard way. Apply them **by default** — do not wait to be asked again.

### 2.1 Shell & page header

- Page canvas: `<div class="dash">` with `.dash-head` (eyebrow + `.dash-title` + `.dash-sub`).
- Every block is a `.card.dash-card` with `<header class="dash-card__head">` containing
  `.dash-card__icon`, a `<div>` with `.dash-card__title` + `.dash-card__hint`, then any action buttons.
- Card heads **must wrap on mobile** (see §2.9). Never let a head crush the title.

### 2.2 Forms

- Group fields into `.form-section` blocks with `<h5 class="section-title">Title</h5>`
  (green 6 px dot, uppercase 0.78 rem, letter-spacing .1em) separated by `1px #eef4fc` hairlines.
- Controls: `min-height: 3.1rem`, `border-radius: 12px`, `1px solid #d6e6f8`, `#fbfdff`, text `#0d3b7a`,
  focus `border-color:#0a5fe6` + `box-shadow: 0 0 0 4px rgba(10,95,230,.14)`.
- Floating labels float into the micro‑uppercase caption; required marker `#d92d20`.
- Read‑only / disabled fields: `#eef4fc` fill, muted text.
- Submit row: `.dash-form__actions` — right aligned, gradient button
  `linear-gradient(135deg,#0a5fe6 0%,#1b7bf2 100%)`, radius 12 px, `0 10px 22px rgba(10,95,230,.24)`.

### 2.3 Toolbars (search + filter)

- **Search always sits on the left, the filter on the right, in ONE row.**
- Search: `flex: 1 1 260–280px`, magnifier icon overlaid at `left: .95rem`, input `2.6–2.7rem`,
  radius 11 px, `#fbfdff`, brand focus ring.
- Toolbar needs **top spacing** (`margin: 1.15rem 0 1rem`).
- Toolbar goes **directly above `.table-responsive`**, after any filter panel.
- The branch filter is a light **chip**, never a heavy grey box:
  `1px solid #e8f1fc`, `border-radius: 13px`, `linear-gradient(180deg,#fbfdff,#f7fbff)`,
  uppercase micro label with a small icon, white select `2.5rem`, radius 10 px.
  The wrapper element **must** literally be `.filter-section` (not `mb-3`/`row`) or the chip styles never
  apply; inside it the select is `width:auto; min-width:190px` so it does not stretch the card.
- If a card has its own toolbar search, **hide the plugin's built‑in search** (`.datatable-top`,
  `.dataTables_filter`) — never show two search boxes.
- **Tabs + branch chip share one row**: wrap the `ul.nav.nav-tabs` and the “Current Branch” chip in
  `.tabs-bar` (`display:flex; justify-content:space-between; align-items:center`), tabs `flex:1 1 auto`,
  chip `flex:0 0 auto; margin-left:auto`. One chip for the card, never one per tab pane.
  Below 768 px the strip stacks and the tabs scroll horizontally.
- A lone area filter is a **single right-aligned row**: `Filter by Area` label, `select` and `Apply`
  button on one baseline (`.filter-container { justify-content: flex-end }`, `.area-filter .filter-field`
  becomes `display: inline-flex; align-items: center; gap: .6rem`, select `width: auto; min-width: 190px`).
  Below 768 px it stacks full width.

### 2.4 Tables

- Uppercase muted headers (`0.64rem`, `#8aa8c8`, `#f7fbff` background), hairline row separators
  `#f1f6fd`, zebra `#fcfdff`, hover `#f4f9ff`, tabular numerals, no outer borders.
- Branch cells: blue pill (`#eef6ff`, border `#cfe4ff`, text `#0a5fe6`).
- Row actions: **soft 30 px icon buttons**, radius 9 px — amber edit (`#fff8e6/#8a5b00`),
  red delete (`#fdeceb/#c0392b`). Never solid Bootstrap yellow/red.
- **Status is always a badge/pill**, never plain text: Present green, Halfday amber,
  Absent red, Late/Early amber (`#e8f7e1/#177407`, `#fff4e5/#a76100`, `#fdeceb/#c0392b`, `#fff8e6/#8a5b00`).

### 2.5 Table footers & pagination

- Layout: info on the **left**, pager on the **right**.
- Footer must live **outside** the horizontally scrolling container — move it out in JS if the plugin
  renders it inside `.table-responsive`, so it does not slide away when the table is scrolled.
- Pager buttons: `2rem × 2rem`, radius 9 px, `1px #e6f0fb`, white, `#33578a`, `0.8rem/600`;
  hover `#c2daf5`/`#f4f9ff`/`#0a5fe6`; active = brand gradient, white text.
- Arrow labels are `‹ ›` — write them as **`\u2039` / `\u203A`** in JS (literal glyphs get mangled by encoding).
- Fixed length control: **no "Show N entries" dropdown**; 10 rows per page.

### 2.6 Modals

- Form modals: `modal-xl modal-dialog-centered` (≈1140 px), `18px` radius, deep soft shadow.
- Header: gradient brand bar with a translucent icon chip, white title and a short hint caption.
- Body split into `.form-section`s; footer = ghost **Cancel** + gradient primary action.
- Select2 panels: 14 px radius, shadow, search field with magnifier icon, option rows radius 10 px,
  highlight = soft `#eef6ff` tint (not a saturated block).

### 2.7 Employee / record dropdowns

- Multi‑field records render as **three lines** inside the dropdown:
  1. `Last, First M.` — bold navy
  2. `Bio#: 12345 - Collector` — muted `#8aa8c8`
  3. `Branch: Tuguegarao City, Abulug` — muted `#8aa8c8`
- The selected value shows **line 1 only**.

### 2.8 Fixed lists

- Where the client names the options, render exactly those options (e.g. payroll branches:
  `All Branches`, Tuguegarao City, Abulug, Gattaran, Ilagan).

### 2.9 Mobile (≤ 767.98 px / ≤ 575.98 px)

- **Card bodies must not scroll internally** — the page scrolls; only a wide table may scroll sideways
  (`overflow-x: auto; overflow-y: visible; max-height: none`).
- Toolbars stack: `flex-direction: column`, and the search wrapper **must** be reset to
  `flex: 0 0 auto; width: 100%; height: auto` (otherwise its `flex-basis` becomes a 260–280 px height).
- Filter chip full width, label above the select, select full width, `min-width: 0`.
- Card head: `flex-wrap: wrap; align-items: flex-start; row-gap: .75rem`, title block
  `flex: 1 1 0%; min-width: 0`, action buttons take a full‑width row (`flex: 1 1 100%`),
  stacked vertically below 576 px.
- Table footer: **centred** (info centred, pager centred, each full width).
- No horizontal overflow: `document.documentElement.scrollWidth === clientWidth`.

### 2.9b Sidebar active state

- Every page sets `$layout_active`; `includes/sidebar.php` maps it to **three** things (see
  `docs/ui-conventions.md` §11): `active` on the nested link + `aria-current="page"`, `active` on the
  parent section header, and `show` on the parent collapse panel.
- Never inline the comparison again — add the page key to the section array at the top of the sidebar.

### 2.10 Palette

| Token | Value |
|---|---|
| Brand gradient | `linear-gradient(135deg, #0a5fe6 0%, #1b7bf2 100%)` |
| Blues | `#005dfa`, `#0099fa`, `#0a3572`, `#0d3b7a` (text), `#33578a` (secondary text) |
| Greens | `#3ba602` (dot), `#177407` (success text) |
| Muted text | `#8aa8c8`, `#7b93b3`, `#a9c1dc` |
| Hairlines | `#eef4fc`, `#eaf2fd`, `#f1f6fd`, `#e8f1fc` |
| Field border / fill | `#d6e6f8` / `#fbfdff` |
| Edit / delete soft | `#fff8e6`+`#8a5b00` / `#fdeceb`+`#c0392b` |

The sidebar keeps the logo‑derived gradient; the top nav stays the original blue.

---

## 3. Don'ts (things that were reverted or corrected)

- Don't restyle or remove print/generate pages, `login.php`, `index.php`, `*_menu.php`, `customer_view_payment.php`.
- Don't add features that were not requested (e.g. extra modals, extra columns, extra buttons).
- Don't remove a filter's `All …` option unless explicitly asked.
- Don't put a bordered/padded box around a filter that lives in a toolbar.
- Don't show the DataTables/simple-datatables length dropdown.
- Don't hardcode literal `‹`/`›`/`«`/`»` glyphs in JS — use unicode escapes.

---

## 4. Verification recipe (run after every change)

1. `php -l <file>` for every touched PHP file.
2. `node --check assets/js/<page>.js` for touched JS.
3. CSS brace parity: count `{` vs `}` in the touched stylesheet (must match).
4. **Regression**: authenticated curl of all **40** navigable pages → expect `200`, zero
   `Warning|Notice|Fatal error|Deprecated`, balanced `<div>` counts.
5. Visual check with headless Edge (`--dump-dom` probes through `document.title`, plus screenshots)
   at 1520 px desktop and 390 / 576 / 768 px widths.
6. Delete every temp artefact: `Get-ChildItem -Filter '_*'` must report `none`, and no `*.bak` may remain.

Session shim for authenticated probes: a temp `_s.php` that sets
`$_SESSION['logged_in']=true`, `user_id=3`, `account_source='accounts'`, `account_type='Admin'`,
`first_name='Admin1'`, then redirects — delete it afterwards.

Baseline CSS brace counts: `dash.css 65/65`, `dash-ui.css 152/152`, `theme-logo.css 143/143`.

---

## 5. Working style with this client

- Terse, imperative requests. Do the work, then report a small table of what was verified + a screenshot.
- They often ask for the *same* refinement on the next page — that is exactly why this file exists:
  apply the conventions above proactively instead of waiting for the request.
- When a request conflicts with an earlier decision, follow the **newest** instruction and update
  `docs/ui-conventions.md` so the record stays current.
