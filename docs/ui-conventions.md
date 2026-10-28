# Prime City — UI Conventions (style guide)

Companion to [`AGENTS.md`](../AGENTS.md). Everything here is **copy‑paste ready** and reflects the
client's approved decisions. When a page needs a card, toolbar, table, modal or pager, take the block
from this file instead of inventing a new look.

> Scope: page‑scoped stylesheets only (`assets/css/<page>-style.css`). Shared files
> `dash.css`, `dash-ui.css`, `theme-logo.css` are the baseline — don't edit them for one page.

---

## 1. Page skeleton

```html
<div class="dash">
    <header class="dash-head">
        <div>
            <p class="dash-eyebrow"><i class="fas fa-…"></i> Prime City &middot; Finance</p>
            <h1 class="dash-title">Page Title</h1>
            <p class="dash-sub">One line explaining what the page does.</p>
        </div>
    </header>

    <div class="card dash-card mb-4">
        <header class="dash-card__head">
            <span class="dash-card__icon"><i class="fas fa-…" aria-hidden="true"></i></span>
            <div>
                <h2 class="dash-card__title">Card Title</h2>
                <p class="dash-card__hint">Short hint.</p>
            </div>
            <!-- optional actions -->
        </header>
        <div class="card-body">…</div>
    </div>
</div>
```

---

## 2. Card head (with wrapping + mobile behaviour)

```css
@media (max-width: 991.98px) {
    .dash .dash-card__head {
        flex-wrap: wrap;
        align-items: flex-start;
        row-gap: 0.75rem;
    }

    .dash .dash-card__head > div:not(.dash-head__actions):not(.filter-buttons) {
        flex: 1 1 0%;
        min-width: 0;
    }

    .dash .dash-card__title { overflow-wrap: anywhere; }

    .dash .dash-card__head .dash-head__actions {
        flex: 1 1 100%;
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        margin: 0 !important;
    }

    .dash .dash-card__head .dash-head__actions .btn {
        flex: 1 1 0;
        justify-content: center;
        text-align: center;
        font-size: 0.78rem;
        padding: 0.5rem 0.65rem;
    }
}

@media (max-width: 575.98px) {
    .dash .dash-card__head .dash-head__actions {
        flex-direction: column;
        align-items: stretch;
    }

    .dash .dash-card__head .dash-head__actions .btn {
        flex: 1 1 auto;
        width: 100%;
        white-space: normal;
    }
}
```

Head action button = gradient pill:

```css
.dash-card .dash-card__head .btn-primary {
    border: 0;
    border-radius: 11px;
    padding: 0.55rem 1rem;
    background: linear-gradient(135deg, #0a5fe6 0%, #1b7bf2 100%);
    color: #fff;
    font-size: 0.8rem;
    font-weight: 600;
    box-shadow: 0 8px 18px rgba(10, 95, 230, 0.24);
}
.dash-card .dash-card__head .btn-primary:hover {
    filter: brightness(1.05);
    box-shadow: 0 10px 22px rgba(10, 95, 230, 0.3);
    transform: translateY(-1px);
}
```

---

## 3. Form sections

```html
<form id="xForm" …>
    <div class="form-section">
        <h5 class="section-title">Entry Details</h5>
        <div class="row"> … fields … </div>
    </div>

    <div class="form-section">
        <h5 class="section-title">Notes</h5>
        … textarea …
    </div>

    <div class="dash-form__actions">
        <button type="submit" class="btn btn-primary">Save</button>
    </div>
</form>
```

```css
#xForm .form-section {
    margin: 0;
    padding: 1.35rem 0 0;
    border-top: 1px solid #eef4fc;
}
#xForm .form-section:first-of-type { padding-top: 0; border-top: 0; }

#xForm .section-title {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    margin: 0 0 1.1rem;
    border: 0;
    color: #0d3b7a;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}
#xForm .section-title::before {
    content: "";
    width: 6px; height: 6px;
    border-radius: 50%;
    background: #3ba602;
}

#xForm .row { --bs-gutter-x: 1.1rem; --bs-gutter-y: 0.9rem; margin-bottom: 0 !important; }

#xForm .form-control,
#xForm .form-select {
    min-height: 3.1rem;
    border: 1px solid #d6e6f8;
    border-radius: 12px;
    background-color: #fbfdff;
    color: #0d3b7a;
    font-size: 0.95rem;
    font-weight: 500;
    transition: border-color .16s ease, box-shadow .16s ease, background-color .16s ease;
}
#xForm .form-control:focus,
#xForm .form-select:focus {
    border-color: #0a5fe6;
    background-color: #fff;
    box-shadow: 0 0 0 4px rgba(10, 95, 230, 0.14);
}
#xForm .form-control[readonly],
#xForm .form-select:disabled {
    background-color: #eef4fc;
    border-color: #e0ecfa;
    color: #43678f;
}

/* floating labels */
#xForm .form-floating > label { padding: 0.8rem 0.95rem; color: #6d86a6; font-size: 0.9rem; }
#xForm .form-floating > .form-control:focus ~ label,
#xForm .form-floating > .form-control:not(:placeholder-shown) ~ label,
#xForm .form-floating > .form-select ~ label {
    color: #8aa8c8;
    font-size: 0.76rem;
    font-weight: 600;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    opacity: 1;
    transform: scale(1) translateY(-0.55rem) translateX(0.15rem);
}

/* textarea: room for the label */
#xForm textarea.form-control { min-height: 6rem; padding: 2.15rem 0.95rem 0.85rem; line-height: 1.5; }
#xForm .form-floating > textarea.form-control { height: 6.5rem !important; }

/* amount fields read as money */
#xForm .amount-input { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }

/* submit row */
#xForm .dash-form__actions {
    display: flex;
    justify-content: flex-end;
    margin: 1.35rem 0 0;
    padding-top: 1.35rem;
    border-top: 1px solid #eef4fc;
}
#xForm .dash-form__actions .btn {
    border: 0;
    border-radius: 12px;
    padding: 0.72rem 1.6rem;
    background: linear-gradient(135deg, #0a5fe6 0%, #1b7bf2 100%);
    color: #fff;
    font-size: 0.9rem;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(10, 95, 230, 0.24);
}
#xForm .dash-form__actions .btn:hover { filter: brightness(1.05); transform: translateY(-1px); }
```

---

## 4. Toolbar: search (left) + filter chip (right), one row

```html
<div class="page-toolbar">
    <div class="page-toolbar__search">
        <i class="fas fa-search page-toolbar__icon" aria-hidden="true"></i>
        <input type="search" id="pageSearch" class="form-control page-toolbar__input"
               placeholder="Search …" autocomplete="off" />
    </div>

    <div class="filter-section">
        <div class="filter-row">
            <span class="filter-label"><i class="fas fa-code-branch"></i> Filter by Branch:</span>
            <div class="branch-filter-dropdown">
                <select class="form-select" name="filter_branch">…</select>
            </div>
        </div>
    </div>
</div>
```

```css
.dash-card .page-toolbar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin: 1.15rem 0 1rem;      /* top spacing is required */
}

.dash-card .page-toolbar__search {
    position: relative;
    display: flex;
    align-items: center;
    flex: 1 1 280px;
    min-width: 0;
}

.dash-card .page-toolbar__icon {
    position: absolute;
    left: 0.95rem;
    color: #8aa8c8;
    font-size: 0.78rem;
    pointer-events: none;
    z-index: 2;
}

.dash-card .page-toolbar__input {
    width: 100%;
    min-height: 2.7rem;
    padding: 0.5rem 0.9rem 0.5rem 2.4rem;
    border: 1px solid #d6e6f8;
    border-radius: 11px;
    background-color: #fbfdff;
    color: #0d3b7a;
    font-size: 0.9rem;
    font-weight: 500;
}
.dash-card .page-toolbar__input:focus {
    outline: 0;
    border-color: #0a5fe6;
    background-color: #fff;
    box-shadow: 0 0 0 4px rgba(10, 95, 230, 0.13);
}

/* filter chip */
.dash-card .page-toolbar .filter-section {
    order: 2;
    flex: 0 0 auto;
    margin: 0 0 0 auto;
    padding: 0.4rem 0.55rem 0.4rem 0.9rem;
    border: 1px solid #e8f1fc;
    border-radius: 13px;
    background: linear-gradient(180deg, #fbfdff 0%, #f7fbff 100%);
}

.dash-card .page-toolbar .filter-row { display: flex; align-items: center; gap: 0.65rem; margin: 0; }

.dash-card .page-toolbar .filter-label {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin: 0;
    color: #7b93b3;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    white-space: nowrap;
}
.dash-card .page-toolbar .filter-label i { color: #0a5fe6; font-size: 0.66rem; }

.dash-card .page-toolbar .branch-filter-dropdown { min-width: 210px; margin: 0; }
.dash-card .page-toolbar .branch-filter-dropdown .form-select {
    min-height: 2.5rem;
    padding: 0.35rem 2.1rem 0.35rem 0.8rem;
    border: 1px solid #d6e6f8;
    border-radius: 10px;
    background-color: #fff;
    color: #0d3b7a;
    font-size: 0.88rem;
    font-weight: 600;
    cursor: pointer;
}
.dash-card .page-toolbar .branch-filter-dropdown .form-select:focus {
    border-color: #0a5fe6;
    box-shadow: 0 0 0 4px rgba(10, 95, 230, 0.13);
}
```

### Mobile toolbar (critical)

```css
@media (max-width: 767.98px) {
    .dash-card .page-toolbar {
        flex-direction: column;
        align-items: stretch;
        gap: 0.75rem;
        margin: 0.9rem 0;
    }

    /* WITHOUT this the flex-basis becomes a 280px HEIGHT in column layout */
    .dash-card .page-toolbar__search {
        flex: 0 0 auto;
        width: 100%;
        min-width: 0;
        height: auto;
    }

    .dash-card .page-toolbar .filter-section { flex: 0 0 auto; width: 100%; min-width: 0; margin: 0; }
    .dash-card .page-toolbar .filter-row { flex-direction: column; align-items: stretch; gap: 0.4rem; }
    .dash-card .page-toolbar .branch-filter-dropdown { width: 100%; min-width: 0; }
    .dash-card .page-toolbar .branch-filter-dropdown .form-select { width: 100%; }
}
```

### Tab strip: tabs + branch chip share ONE row

Client rule: the tab pills and the "Current Branch" chip live on the same line — tabs left, chip
hard right. One chip for the whole card, never repeated inside each tab pane.

```html
<div class="tabs-bar">
    <ul class="nav nav-tabs" id="financialTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#loans" type="button" role="tab">Loan Portfolio</button>
        </li>
    </ul>

    <div class="branch-filter">
        <span class="filter-label"><i class="fas fa-building-columns" aria-hidden="true"></i> Current Branch</span>
        <span class="branch-filter__value">All Branches</span>
    </div>
</div>
```

```css
.dash-card .tabs-bar {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem 1rem;
    margin: 0 0 1rem;
}

.dash-card .nav-tabs {
    display: flex;
    flex: 1 1 auto;
    flex-wrap: wrap;
    gap: 0.3rem;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
    list-style: none;
}

.dash-card .branch-filter {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 0.5rem;
    margin-left: auto;      /* pins the chip to the right edge */
    padding: 0.45rem 0.85rem;
    border: 1px solid #eaf2fd;
    border-radius: 11px;
    background: #f7fbff;
}

@media (max-width: 767.98px) {
    .dash-card .tabs-bar { flex-direction: column; align-items: stretch; gap: 0.65rem; }
    .dash-card .nav-tabs {
        flex: 0 0 auto;
        flex-wrap: nowrap;
        width: 100%;
        overflow-x: auto;
        padding-bottom: 0.25rem;
        -webkit-overflow-scrolling: touch;
    }
    .dash-card .branch-filter { margin-left: 0; }
}
```

Check: `Math.abs(tabs.top − chip.top) < 18` proves they are on one row at ≥768 px.

### Area filter: label + select + button on ONE right-aligned row

When a card has a single area filter (no search), keep the three pieces on one baseline, flush right:

```html
<div class="filter-container">
    <form method="get" class="area-filter">
        <div class="filter-field">
            <label for="area_filter">Filter by Area</label>
            <select name="area_filter" id="area_filter" class="form-select">…</select>
        </div>
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter me-1" aria-hidden="true"></i> Apply Area Filter
        </button>
    </form>
</div>
```

```css
.dash-card .filter-container { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .85rem 1rem; margin: 0 0 1rem; }
.dash-card .area-filter { display: flex; flex-wrap: wrap; align-items: center; justify-content: flex-end; gap: .65rem; margin: 0 0 0 auto; }
.dash-card .area-filter .filter-field { display: inline-flex; flex: 0 0 auto; align-items: center; gap: .6rem; min-width: 0; }
.dash-card .area-filter .filter-field label {
    display: inline-block; margin: 0; color: #7b93b3; font-size: .68rem; font-weight: 700;
    letter-spacing: .08em; text-transform: uppercase; white-space: nowrap;
}
.dash-card .area-filter .filter-field .form-select { width: auto; min-width: 190px; }

@media (max-width: 767.98px) {
    .dash-card .area-filter { flex-direction: column; align-items: stretch; margin: 0; }
    .dash-card .area-filter .filter-field { flex-direction: column; align-items: stretch; width: 100%; gap: .35rem; }
    .dash-card .area-filter .filter-field .form-select { width: 100%; min-width: 0; }
    .dash-card .area-filter .btn { width: 100%; justify-content: center; }
}
```

Check: label / select / button vertical centres within 8 px, and the button's right edge equals the
container's right edge.

---

## 5. Table skin

```css
.dash-card #dataTable {
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
    font-size: 0.8rem;
}

.dash-card #dataTable thead th {
    padding: 0.7rem 0.75rem;
    border: 0;
    border-bottom: 1px solid #eaf2fd;
    background: #f7fbff;
    color: #8aa8c8;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    white-space: nowrap;
}

.dash-card #dataTable tbody td {
    padding: 0.6rem 0.75rem;
    border: 0;
    border-bottom: 1px solid #f1f6fd;
    color: #33578a;
    vertical-align: middle;
    font-variant-numeric: tabular-nums;
}

.dash-card #dataTable tbody tr:nth-child(even) td { background: #fcfdff; }
.dash-card #dataTable tbody tr:hover td { background: #f9fcff; }
.dash-card #dataTable tbody tr:nth-child(even):hover td { background: #f4f9ff; }

/* branch pill */
.dash-card #dataTable .badge {
    padding: 0.3em 0.7em;
    border: 1px solid #cfe4ff;
    border-radius: 999px;
    background: #eef6ff !important;
    color: #0a5fe6 !important;
    font-size: 0.72rem;
    font-weight: 600;
}

/* soft icon actions */
.dash-card #dataTable .action-buttons { display: flex; gap: 0.35rem; justify-content: center; }
.dash-card #dataTable .action-buttons .btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; padding: 0; border-radius: 9px; font-size: 0.72rem;
}
.dash-card #dataTable .action-buttons .btn-warning,
.dash-card #dataTable .action-buttons .btn-edit {
    border: 1px solid #ffe1a8; background: #fff8e6; color: #8a5b00;
}
.dash-card #dataTable .action-buttons .btn-danger,
.dash-card #dataTable .action-buttons .btn-delete {
    border: 1px solid #f7c9c4; background: #fdeceb; color: #c0392b;
}
```

### Status badges (always, never plain text)

```css
.dash-card .status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 74px;
    padding: 0.32em 0.75em;
    border: 1px solid transparent;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 600;
    white-space: nowrap;
}
.status-badge.badge-present { background: #e8f7e1; border-color: #c4e9b4; color: #177407; }
.status-badge.badge-halfday { background: #fff4e5; border-color: #ffd9a8; color: #a76100; }
.status-badge.badge-absent  { background: #fdeceb; border-color: #f7c9c4; color: #c0392b; }
.status-badge.badge-late    { background: #fff8e6; border-color: #ffe1a8; color: #8a5b00; }
```

Map every status value in PHP — an unmapped value renders an invisible badge:

```php
$badge_class = '';
if     ($record['status'] === 'Present')    { $badge_class = 'badge-present'; }
elseif ($record['status'] === 'Absent')     { $badge_class = 'badge-absent'; }
elseif ($record['status'] === 'Halfday')    { $badge_class = 'badge-halfday'; }
elseif ($record['status'] === 'Late/Early') { $badge_class = 'badge-late'; }
```

---

## 6. Table footer & pagination

### Right aligned (desktop)

```css
.dash-card .datatable-bottom,
.dash-card .card-body > .bottom {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-top: 0.9rem;
    padding-top: 0.9rem;
    border-top: 1px solid #f1f6fd;
}
.dash-card .datatable-info { margin-right: auto; color: #7b93b3; font-size: 0.78rem; }
.dash-card .datatable-pagination { flex: 0 0 auto; margin: 0 0 0 auto; width: auto; }
.dash-card .datatable-pagination ul {
    display: flex; flex-wrap: wrap; justify-content: flex-end;
    gap: 0.25rem; margin: 0; padding: 0; list-style: none;
}
.dash-card .datatable-pagination-list-item-link {
    display: grid; place-items: center;
    min-width: 2rem; height: 2rem; padding: 0 0.5rem;
    border: 1px solid #e6f0fb; border-radius: 9px;
    background: #fff; color: #33578a !important;
    font-size: 0.8rem; font-weight: 600; text-decoration: none; cursor: pointer;
}
.dash-card .datatable-pagination-list-item-link:hover {
    border-color: #c2daf5; background: #f4f9ff; color: #0a5fe6 !important;
}
.dash-card .datatable-pagination-list-item.datatable-active .datatable-pagination-list-item-link {
    border-color: transparent;
    background: linear-gradient(135deg, #0a5fe6, #1b7bf2);
    color: #fff !important;
}
```

### DataTables (Bootstrap 5) variant

DataTables emits `<li class="paginate_button page-item"><a class="page-link">` — style the **anchor**
and neutralise the `<li>`, otherwise the pill is wider than the reference:

```css
.dash-card .dataTables_paginate .paginate_button,
.dash-card .dataTables_paginate .page-item {
    display: block; min-width: 0; height: auto;
    margin: 0; padding: 0; border: 0; background: none; box-shadow: none;
}
.dash-card .dataTables_paginate .paginate_button .page-link,
.dash-card .dataTables_paginate .page-item .page-link {
    display: grid; place-items: center;
    min-width: 2rem; height: 2rem; padding: 0 0.5rem;
    border: 1px solid #e6f0fb; border-radius: 9px;
    background: #fff; color: #33578a !important;
    font-size: 0.8rem; font-weight: 600; line-height: 1; box-shadow: none;
}
.dash-card .dataTables_paginate .page-item.active .page-link {
    border-color: transparent;
    background: linear-gradient(135deg, #0a5fe6, #1b7bf2);
    color: #fff !important;
}
```

### Keep the footer out of the horizontal scroller

The plugin builds its footer inside `.table-responsive`; when the table scrolls sideways the footer
goes with it. Move it once after init:

```js
var $wrapper = $('#myTable_wrapper');
var $scrollBox = $wrapper.closest('.table-responsive');
if ($scrollBox.length) {
    $scrollBox.after($wrapper.find('.bottom'));
}
```

### Footer centred on mobile

```css
@media (max-width: 767.98px) {
    .dash-card .datatable-bottom,
    .dash-card .card-body > .bottom { justify-content: center; text-align: center; gap: 0.5rem; }
    .dash-card .datatable-info { width: 100%; margin: 0; text-align: center; }
    .dash-card .datatable-pagination { width: 100%; margin: 0.35rem 0 0; }
    .dash-card .datatable-pagination ul { width: 100%; justify-content: center; }
}
```

### Plugin configuration

```js
// simple-datatables
new simpleDatatables.DataTable(table, { perPage: 10, searchable: true, sortable: true });

// DataTables + Bootstrap 5 — no length dropdown, arrows as unicode escapes
$('#myTable').DataTable({
    responsive: true,
    lengthChange: false,
    pageLength: 10,
    dom: 'rt<"bottom"ip>',
    language: {
        paginate: { first: "\u00AB", last: "\u00BB", next: "\u203A", previous: "\u2039" }
    }
});
```

When the card has its own toolbar search, hide the plugin chrome:

```css
.dash-card .datatable-top,
.dash-card .dataTables_filter { display: none !important; }
```

---

## 7. Modals

```css
#xModal .modal-content {
    border: 0;
    border-radius: 18px;
    box-shadow: 0 24px 60px rgba(4, 16, 32, 0.22);
    overflow: hidden;
}
#xModal .modal-header {
    display: flex; align-items: center; gap: 0.75rem;
    padding: 1.05rem 1.3rem; border: 0;
    background: linear-gradient(135deg, #0a5fe6 0%, #1b7bf2 100%);
    color: #fff;
}
#xModal .modal-icon {
    display: inline-flex; align-items: center; justify-content: center;
    width: 38px; height: 38px; border-radius: 11px;
    background: rgba(255, 255, 255, 0.18); font-size: 0.95rem;
}
#xModal .modal-title { margin: 0; color: #fff; font-size: 1rem; font-weight: 700; }
#xModal .modal-hint { margin: 0.1rem 0 0; color: rgba(255, 255, 255, 0.78); font-size: 0.76rem; }
#xModal .btn-close { margin-left: auto; filter: brightness(0) invert(1); opacity: 0.85; }
#xModal .modal-body { padding: 1.3rem; background: #fff; }
#xModal .modal-footer {
    padding: 0.9rem 1.3rem; border-top: 1px solid #eef4fc; background: #fbfdff;
}
#xModal .modal-footer .btn { border-radius: 11px; padding: 0.58rem 1.15rem; font-size: 0.86rem; font-weight: 600; }
#xModal .modal-footer .btn-secondary { border: 1px solid #d6e6f8; background: #fff; color: #33578a; }
#xModal .modal-footer .btn-primary,
#xModal .modal-footer .btn-success {
    border: 0;
    background: linear-gradient(135deg, #0a5fe6 0%, #1b7bf2 100%);
    box-shadow: 0 10px 22px rgba(10, 95, 230, 0.24);
}
```

Dialog classes: `modal-xl modal-dialog-centered`.

### Select2 inside a modal

```css
#xModal .select2-container--default .select2-selection--single {
    height: 2.9rem !important;
    padding: 0 2.4rem 0 0.95rem !important;
    border: 1px solid #d6e6f8 !important;
    border-radius: 11px !important;
    background: #fbfdff !important;
}
#xModal .select2-container--default.select2-container--focus .select2-selection--single {
    border-color: #0a5fe6 !important;
    background: #fff !important;
    box-shadow: 0 0 0 4px rgba(10, 95, 230, 0.13) !important;
}
#xModal .select2-dropdown {
    margin-top: 0.35rem;
    border: 1px solid #dbe8f8 !important;
    border-radius: 14px !important;
    box-shadow: 0 18px 40px rgba(4, 16, 32, 0.16) !important;
    overflow: hidden;
}
#xModal .select2-search--dropdown { padding: 0.65rem 0.65rem 0.45rem !important; }
#xModal .select2-search--dropdown .select2-search__field {
    height: 2.5rem !important;
    padding: 0.4rem 0.85rem 0.4rem 2.3rem !important;
    border: 1px solid #d6e6f8 !important;
    border-radius: 10px !important;
    background: #fbfdff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 512 512' fill='%238aa8c8'%3E%3Cpath d='M416 208c0 45.9-14.9 88.3-40 122.7l126.6 126.7c12.5 12.5 12.5 32.8 0 45.3s-32.8 12.5-45.3 0L330.7 376c-34.4 25.2-76.8 40-122.7 40C93.1 416 0 322.9 0 208S93.1 0 208 0s208 93.1 208 208zM208 352a144 144 0 1 0 0-288 144 144 0 1 0 0 288z'/%3E%3C/svg%3E") no-repeat 0.8rem center / 0.85rem !important;
}
#xModal .select2-results__options { padding: 0.25rem 0.4rem 0.45rem !important; max-height: 320px !important; }
#xModal .select2-results__option { padding: 0.55rem 0.65rem !important; border-radius: 10px !important; }
#xModal .select2-results__option--highlighted[aria-selected] { background: #eef6ff !important; color: inherit !important; }
```

---

## 8. Three-line dropdown items

```js
templateResult: function (option) {
    if (!option.id) { return option.text; }

    var $o = $(option.element);
    var $wrap = $('<div class="pc-opt"></div>');

    $('<span class="pc-opt__name"></span>').text($o.data('name') || option.text).appendTo($wrap);

    if ($o.data('bio')) {
        $('<span class="pc-opt__meta"></span>')
            .text('Bio#: ' + $o.data('bio') + ($o.data('type') ? ' - ' + $o.data('type') : ''))
            .appendTo($wrap);
    }

    if ($o.data('branch')) {
        $('<span class="pc-opt__meta"></span>').text('Branch: ' + $o.data('branch')).appendTo($wrap);
    }

    return $wrap;
},
templateSelection: function (option) {
    if (!option.id) { return option.text; }
    return $(option.element).data('name') || option.text;
}
```

```css
.pc-opt { display: flex; flex-direction: column; gap: 0.1rem; padding: 0.15rem 0; line-height: 1.35; }
.pc-opt__name { color: #0d3b7a; font-size: 0.88rem; font-weight: 600; }
.pc-opt__meta { color: #8aa8c8; font-size: 0.74rem; font-weight: 500; }
.select2-results__option--highlighted[aria-selected] .pc-opt__name { color: #0a5fe6; }
.select2-results__option--highlighted[aria-selected] .pc-opt__meta { color: #6d86a6; }
```

---

## 9. Mobile: no inner card scrolling

```css
@media (max-width: 767.98px) {
    .dash .card-body { overflow: visible; max-height: none; }

    .dash .card-body .table-responsive {
        max-height: none !important;
        overflow-x: auto;
        overflow-y: visible;
        -webkit-overflow-scrolling: touch;
    }
}
```

---

## 10. Small components

| Component | Recipe |
|---|---|
| Segmented period filter (All/Daily/Weekly/Monthly) | `inline-flex`, `gap: .2rem`, `padding: .22rem`, `1px #eaf2fd`, radius 12 px, `#f7fbff`; active = gradient, radius 9 px, shadow |
| Ghost button | `1px #d6e6f8`, white, `#33578a`; hover `#b9d6f6` border, `#f4f9ff` fill, `#0a5fe6` text |
| Chip / counter | uppercase micro label `0.66–0.68rem`, `700`, letter-spacing `.08em`, `#8aa8c8` |
| Drop zone | dashed `1.5px #c7ddf7`, radius 16 px, `#fbfdff → #f6faff`; icon chip 52 px; drag-over = `#0a5fe6` border + ring; filled = green icon + file chip |
| Info panel | `#f6faff`, border `#e3eefc`, left rule `3px #0a5fe6`, radius 12 px, `0.8rem` |
| Report-type date control | Daily → `input[type=date]`, Weekly → `week`, Monthly → `month`, **Yearly → `<select>` of years `2023 … current year`** (oldest first, current year pre-selected). Same `#date_filter` id, always visible, `required`; rebuild it in JS on `report_type` change. Year options must come from the **data** (`SELECT DISTINCT YEAR(payment_date) FROM payments`), not a hardcoded range; fall back to the current year when the table is empty. The caption switches `Date` → `Year` for yearly (`#date_label`). |

---

## 11. Sidebar active state (`includes/sidebar.php`)

Every page sets `$layout_active`; the sidebar maps it to three things so the rail always shows where you are:

1. `active` on the **nested link** + `aria-current="page"`
2. `active` on the **parent section header** (`nav-link collapsed active`)
3. `show` on the **parent collapse panel** (`collapse show`, `aria-expanded="true"`) so the section is open

```php
$nav_reports = array('financial-statement', 'area-reports', 'collector-reports',
                     'penalty-report', 'amount-financed', 'savings-reports');
$reports_active = in_array($layout_active, $nav_reports, true);

$nav_link_class      = static fn (bool $a): string => 'nav-link' . ($a ? ' active' : '');
$nav_link_current    = static fn (bool $a): string => $a ? ' aria-current="page"' : '';
$nav_section_class   = static fn (bool $a): string => 'nav-link collapsed' . ($a ? ' active' : '');
$nav_section_expanded = static fn (bool $a): string => $a ? 'true' : 'false';
$nav_panel_class     = static fn (bool $a): string => 'collapse' . ($a ? ' show' : '');
```

Map table (add new pages here, never inline the comparison):

| Section | `$layout_active` values |
|---|---|
| Accounts → Customers | `customer-list`, `customer-registration`, `customer-dashboard`, `customer-history`, `accounts-registration`, `accounts-loan` |
| Accounts → Employees | `employee-list`, `collector-registration`, `proxy-registration`, `collector-loan`, `proxy-loan`, `collector-collections`, `collector-payments`, `collector-biometrics` |
| Customer Loans | `add-financing`, `multiple-payments`, `payment-history` |
| Employee Loans | `add-collector-loan`, `add-collector-payments` |
| Others | `add-investment`, `gcash-in-out`, `add-expenses` |
| Biometrics | `upload-attendance`, `payroll`, `biometrics-report` |
| Penalty Management | `run-penalty-update` |
| Logs | `activity-logs`, `date-time-logs` |
| Cashbook | `cashbook` |
| Backup | `backup-sql` |
| Reports | `financial-statement`, `area-reports`, `collector-reports`, `penalty-report`, `amount-financed`, `savings-reports` |

Record/detail pages inherit their parent entry (e.g. `collector_loan_record.php` keeps **Accounts → Employees** highlighted).
`change_password.php` has no rail entry (it lives in the topbar user menu) and therefore highlights nothing.
