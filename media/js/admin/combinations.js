/**
 * Combination Studio - independent iframe popup with its own save
 * (order-edit pattern, hardened):
 *
 *   open   lazy: the iframe src is set on first open per variant, so the
 *          item page carries ZERO studio markup until needed. A dirty guard
 *          blocks opening while the item form has unsaved changes; Studio
 *          saves are atomic against a clean parent by construction.
 *   save   the popup posts its own form (Apply stays in the dialog, Save
 *          closes it) and reports back via postMessage.
 *   reload DEFERRED: the parent re-renders through core
 *          FormController::reload only when the dialog closes after a save,
 *          never under an open Studio. Scroll position is restored after the
 *          round-trip via sessionStorage.
 *
 * Also runs inside the popup itself (shared asset): only the modal-shell
 * handlers find their elements there, so the file is context-safe.
 */
(() => {
  'use strict';

  if (window.alfaCombinationsInit) {
    return;
  }

  window.alfaCombinationsInit = true;

  const modal = () => document.getElementById('combinationStudioModal');
  const frame = () => document.getElementById('combination-frame');
  const itemForm = () => document.getElementById('item-form');
  const modalApi = () => window.bootstrap ? window.bootstrap.Modal.getOrCreateInstance(modal()) : null;

  let itemFormDirty = false;
  let modalOpen = false;
  let pendingReload = false;
  let studioDirty = false;
  let currentIndex = -1;

  const triggers = () => [...document.querySelectorAll('[data-studio-open]')];

  // < 2/6 > navigation injected into the NATIVE modal-header, beside the title.
  const ensureHeaderNav = () => {
    const header = modal()?.querySelector('.modal-header');

    if (!header || header.querySelector('.studio-nav')) {
      return;
    }

    const nav = document.createElement('div');
    nav.className = 'studio-nav';
    nav.innerHTML = '<button type="button" class="studio-prev" aria-label="prev">&lsaquo;</button>'
      + '<span class="studio-counter"></span>'
      + '<button type="button" class="studio-next" aria-label="next">&rsaquo;</button>';
    header.insertBefore(nav, header.querySelector('.btn-close'));

    nav.querySelector('.studio-prev').addEventListener('click', () => navigate(-1));
    nav.querySelector('.studio-next').addEventListener('click', () => navigate(1));
  };

  const showVariant = (index) => {
    const list = triggers();
    const trigger = list[index];
    const view = frame();
    const shell = modal();

    if (!trigger || !view || !shell) {
      return;
    }

    currentIndex = index;
    studioDirty = false;

    if (view.dataset.current !== trigger.dataset.src) {
      view.src = trigger.dataset.src;
      view.dataset.current = trigger.dataset.src;
    }

    view.title = trigger.dataset.label || '';

    const title = shell.querySelector('.modal-title');

    if (title) {
      title.textContent = trigger.dataset.label || '';
    }

    const counter = shell.querySelector('.studio-counter');

    if (counter) {
      counter.textContent = (index + 1) + ' / ' + list.length;
      shell.querySelector('.studio-prev').disabled = index === 0;
      shell.querySelector('.studio-next').disabled = index === list.length - 1;
    }
  };

  const navigate = (delta) => {
    const next = currentIndex + delta;

    if (next < 0 || next >= triggers().length) {
      return;
    }

    if (studioDirty && !confirm(Joomla.Text._('COM_ALFA_COMBINATION_NAV_DIRTY'))) {
      return;
    }

    showVariant(next);
  };

  // Scroll restoration after a deferred reload (key carries the item id so
  // different items never inherit each other's position).
  const scrollKey = () => 'alfaScroll:' + (new URLSearchParams(window.location.search).get('id') || '0');

  document.addEventListener('DOMContentLoaded', () => {
    const saved = sessionStorage.getItem(scrollKey());

    if (saved !== null) {
      sessionStorage.removeItem(scrollKey());
      window.scrollTo({ top: parseInt(saved, 10), behavior: 'instant' });
    }
  });

  document.addEventListener('change', (event) => {
    if (itemForm() && itemForm().contains(event.target) && !modalOpen) {
      itemFormDirty = true;
    }
  });

  // Name column: typing into a secondary-language box pins that cell open, so
  // blurring never hides a name just entered. The collapsed state is otherwise
  // pure CSS (:focus-within); this only sets the attribute the stylesheet reads.
  document.addEventListener('input', (event) => {
    const secondary = event.target.closest('.variant-name .ml-field-item.is-secondary');

    if (secondary && event.target.value.trim() !== '') {
      secondary.closest('[data-variant-name]').setAttribute('data-expanded', '');
    }
  });

  // Drag-to-reorder: option lists (per field) + the variations grid. The DOM
  // order IS the saved order: the item form posts rows in document order and
  // saveItemOptions/saveCombinations number them by position, so a drag only
  // flags the form dirty (no extra request). Re-inits cleanly after a reload.
  const initSortables = () => {
    if (!window.Sortable) {
      return;
    }

    const flagMoved = (event) => {
      if (event.oldIndex !== event.newIndex) {
        itemFormDirty = true;
      }
    };

    // Value order: drag the rows inside one group (the row handle).
    document.querySelectorAll('[data-sortable-options], [data-sortable-info]').forEach((list) => {
      if (list.dataset.sortableBound) {
        return;
      }

      list.dataset.sortableBound = '1';
      window.Sortable.create(list, {
        handle: '.sortable-handler',
        draggable: '.settings-row',
        animation: 150,
        onEnd: flagMoved,
      });
    });

    // Group order: drag a whole option/info FIELD group by its header handle. Each
    // group posts its field id (jform[field_order][]) in document order, so the
    // item save numbers them (saveItemFieldOrder). The group handle is .group-drag,
    // distinct from the row handle, so the two levels never clash.
    document.querySelectorAll('[data-options-list], [data-info-list]').forEach((list) => {
      if (list.dataset.groupSortableBound) {
        return;
      }

      list.dataset.groupSortableBound = '1';
      window.Sortable.create(list, {
        handle: '.group-drag',
        draggable: '.settings-group',
        animation: 150,
        onEnd: flagMoved,
      });
    });

    // Variations: ONE order per dimension (Size, Color, ...), driven by dragging a
    // row. A drop changes EXACTLY the dimension you crossed (the first dimension
    // where the dragged row differs from the neighbour it landed against):
    //   - cross a Size  -> the Size order changes -> the WHOLE group moves;
    //   - reorder within a Size -> the Color order changes -> it applies to EVERY
    //     Size (consistent, at any depth).
    // Then we re-sort all rows by the value tuple, so groups never split and no
    // other dimension is touched (no side effects).
    const gridTuple = (row) => (row.dataset.tuple || '').split('-');

    const gridRows = (container) => Array.prototype.slice.call(container.querySelectorAll(':scope > tr'));

    // Per-dimension value order from the CURRENT (grouped) rows: first appearance.
    const dimensionOrders = (rows) => {
      const depth = rows.length ? gridTuple(rows[0]).length : 0;
      const orders = [];

      for (let d = 0; d < depth; d += 1) {
        const seen = Object.create(null);
        const list = [];

        rows.forEach((row) => {
          const value = gridTuple(row)[d];

          if (!seen[value]) {
            seen[value] = true;
            list.push(value);
          }
        });

        orders.push(list);
      }

      return orders;
    };

    // Captured at drag start (rows still grouped), so we update only what moved.
    let comboPreOrder = null;

    const applyVariantOrder = (container, dragged, movedDown) => {
      const rows = gridRows(container);

      if (rows.length < 2 || !comboPreOrder) {
        return;
      }

      const orders = comboPreOrder.map((list) => list.slice());
      const ref = movedDown ? dragged.previousElementSibling : dragged.nextElementSibling;

      if (ref && ref.dataset.tuple) {
        const dt = gridTuple(dragged);
        const rt = gridTuple(ref);
        let changed = -1;

        for (let d = 0; d < dt.length; d += 1) {
          if (dt[d] !== rt[d]) {
            changed = d;
            break;
          }
        }

        if (changed !== -1) {
          // Move the dragged value next to the neighbour's value in THAT dimension.
          const order = orders[changed].filter((value) => value !== dt[changed]);
          const at = order.indexOf(rt[changed]);

          order.splice(movedDown ? at + 1 : at, 0, dt[changed]);
          orders[changed] = order;
        }
      }

      const rank = orders.map((list) => {
        const map = Object.create(null);
        list.forEach((value, index) => { map[value] = index; });
        return map;
      });

      rows.sort((a, b) => {
        const ta = gridTuple(a);
        const tb = gridTuple(b);

        for (let d = 0; d < ta.length; d += 1) {
          if (rank[d][ta[d]] !== rank[d][tb[d]]) {
            return rank[d][ta[d]] - rank[d][tb[d]];
          }
        }

        return 0;
      });

      // Minimal DOM reconcile: only the rows that are out of place move (the row
      // you dragged is already where you dropped it, so it stays put; no stick),
      // the matching rows in the other groups slide into the same order.
      let anchor = container.firstElementChild;

      rows.forEach((row) => {
        if (row === anchor) {
          anchor = anchor.nextElementSibling;
        } else {
          container.insertBefore(row, anchor);
        }
      });
    };

    const grid = document.querySelector('[data-sortable-combinations]');

    if (grid && !grid.dataset.sortableBound) {
      grid.dataset.sortableBound = '1';
      window.Sortable.create(grid, {
        // No settle animation: the order is re-computed on drop, so SortableJS
        // would animate the row to where you LET GO and then it would jump to the
        // final sorted spot ("animate then jump" = the intermittent lag). Instant
        // placement is consistent and snappier on these heavy rows.
        handle: '.sortable-handler',
        draggable: 'tr',
        animation: 0,
        onStart: () => { comboPreOrder = dimensionOrders(gridRows(grid)); },
        onEnd: (event) => {
          applyVariantOrder(grid, event.item, event.newIndex > event.oldIndex);
          flagMoved(event);
        },
      });
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSortables);
  } else {
    initSortables();
  }

  const close = () => {
    modalApi()?.hide();
  };

  // DEFERRED reload on EVERY close path (Save, Close button, Esc, backdrop):
  // bootstrap fires hidden.bs.modal for all of them.
  document.addEventListener('hidden.bs.modal', (event) => {
    if (event.target !== modal()) {
      return;
    }

    modalOpen = false;

    if (pendingReload) {
      pendingReload = false;
      reloadItemPage();
    }
  });

  const reloadItemPage = () => {
    const form = itemForm();

    if (!form) {
      window.location.reload();

      return;
    }

    sessionStorage.setItem(scrollKey(), String(window.scrollY));

    // Core FormController::reload(): token-checked, stashes the posted form
    // data in user state (unsaved field values survive the round-trip) and
    // redirects back to the edit view; the grid re-reads the fresh DB state.
    form.querySelector('input[name="task"]').value = 'item.reload';

    // Painted first, then submitted; see Alfa.loader.showThen().
    Alfa.loader.showThen(function () { form.submit(); });
  };

  // Locked price chip (2+ price rows) -> open that row's Edit, where the full
  // price matrix lives. Delegates to the row's own Edit button (dirty-guard,
  // nav and modal show all handled there).
  const openRowEdit = (chip) => chip.closest('tr')?.querySelector('[data-studio-open]')?.click();

  document.addEventListener('keydown', (event) => {
    if ((event.key === 'Enter' || event.key === ' ') && event.target.closest('[data-open-edit]')) {
      event.preventDefault();
      openRowEdit(event.target.closest('[data-open-edit]'));
    }
  });

  document.addEventListener('click', (event) => {
    const lockedOpen = event.target.closest('[data-open-edit]');

    if (lockedOpen) {
      openRowEdit(lockedOpen);

      return;
    }

    const trigger = event.target.closest('[data-studio-open]');

    if (trigger) {
      if (itemFormDirty) {
        // The Studio saves directly to the database; an unsaved item form
        // would silently lose its changes on the post-save re-render.
        alert(Joomla.Text._('COM_ALFA_COMBINATION_DIRTY_GUARD'));

        return;
      }

      ensureHeaderNav();
      showVariant(triggers().indexOf(trigger));
      modalOpen = true;
      modalApi()?.show();

      return;
    }

    const remove = event.target.closest('[data-combination-remove]');

    if (remove) {
      const row = remove.closest('[data-combination-id]');
      const id = parseInt(row.dataset.combinationId || '0', 10);
      const deleted = document.getElementById('deleted-combination-ids');

      if (id > 0 && deleted) {
        deleted.value = deleted.value ? deleted.value + ',' + id : String(id);
      }

      row.remove();
    }
  });

  // Generator / filter / bulk tools
  const tools = () => document.querySelector('.alfa-combi-tools');
  const gridRows = () => [...document.querySelectorAll('.alfa-combinations tbody tr')];

  // The generator lives in its own modal; the Generate button sits in the
  // modal FOOTER, outside [data-generator]; resolve both document-wide.
  const generator = () => document.querySelector('[data-generator]');
  const generateButton = () => document.querySelector('[data-generate]');

  // Free choice: ANY subset of fields (>=1 value somewhere). Locked (existing
  // combinations): EVERY field of the locked dimension set needs >=1 value:
  // the depth lock keeps all combinations on the same set.
  const updateGenerateState = () => {
    const shell = generator();
    const button = generateButton();

    if (!shell || !button) {
      return;
    }

    const fields = [...shell.querySelectorAll('[data-generator-field]:not([data-locked-out])')];
    const ready = shell.dataset.locked === '1'
      ? fields.every((field) => field.querySelector('input:checked'))
      : fields.some((field) => field.querySelector('input:checked'));
    button.disabled = !ready;
  };

  const generate = async () => {
    const shell = generator();
    const button = generateButton();
    const params = new URLSearchParams();
    params.set(Joomla.getOptions('csrf.token', ''), '1');
    params.set('id', shell.dataset.itemId);

    for (const field of shell.querySelectorAll('[data-generator-field]:not([data-locked-out])')) {
      for (const value of field.querySelectorAll('input:checked')) {
        params.append('values[' + field.dataset.generatorField + '][]', value.value);
      }
    }

    button.disabled = true;

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.generateCombinations&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (json?.error && json.message) {
        // Server-side guard (depth lock): verbatim to the admin.
        alert(json.message);
      } else if (json?.data?.created > 0 || json?.data?.reordered) {
        // Fresh rows created, or the dimension order changed: re-render through
        // item.reload (unsaved item form state survives, scroll is restored).
        reloadItemPage();

        return;
      } else {
        alert(Joomla.Text._('COM_ALFA_COMBI_GENERATE_NONE'));
      }
    } catch (error) {
      // Network hiccup: leave the page as is.
    }

    button.disabled = false;
    updateGenerateState();
  };

  // Operation cycler: ONE button in front of the amount; click cycles the
  // operation. Shared by the grid quick-adjust, the options rows and the
  // Studio price editor (this asset loads on both pages).
  const OPERATIONS = ['+', '-', '%', '-%'];
  const OPERATION_LABELS = { '+': '+', '-': '−', '%': '+%', '-%': '−%' };

  document.addEventListener('click', (event) => {
    const cycle = event.target.closest('[data-op-cycle]');

    if (!cycle) {
      return;
    }

    const hidden = cycle.closest('.op-cycle').querySelector('input[type="hidden"]');
    const next = OPERATIONS[(OPERATIONS.indexOf(hidden.value) + 1) % OPERATIONS.length];
    hidden.value = next;
    cycle.textContent = OPERATION_LABELS[next];
    cycle.title = OPERATION_LABELS[next];

    // The grid quick-adjust mirrors a live final price; the operation is part
    // of that sum, so cycling it must refresh the preview too.
    const quick = cycle.closest('.quick-adjust');

    if (quick) {
      recomputeQuickFinal(quick);
    }

    // In the Studio popup the op lives inside a price-row; nudge prices.js to
    // re-derive that row's final via its amount input.
    const priceRow = cycle.closest('.price-row');

    if (priceRow) {
      priceRow.querySelector('[data-price-value]')?.dispatchEvent(new Event('input', { bubbles: true }));
    }
  });

  // Generic value cycler: a prefix button that walks a fixed value list, same
  // chrome as the op-cycle. Used for the quick mode (price/adjust) and the
  // adjust target (net/gross). The values + labels travel on the wrapper.
  document.addEventListener('click', (event) => {
    const wrap = event.target.closest('[data-cycle]');

    if (!wrap || !event.target.closest('button')) {
      return;
    }

    const values = (wrap.dataset.cycleValues || '').split(',').filter(Boolean);

    if (!values.length) {
      return;
    }

    const labels = JSON.parse(wrap.dataset.cycleLabels || '{}');
    const hidden = wrap.querySelector('input[type="hidden"]');
    const next = values[(values.indexOf(hidden.value) + 1) % values.length];
    const button = wrap.querySelector('button');

    hidden.value = next;
    button.textContent = labels[next] || next;
    button.title = labels[next] || next;

    // The quick mode cycler also shows/hides the adjust-only controls.
    if (wrap.hasAttribute('data-quick-mode')) {
      const quick = wrap.closest('.quick-adjust');

      if (quick) {
        quick.querySelectorAll('.adjust-only').forEach((el) => {
          el.hidden = next === 'price';
        });
        quick.dataset.quickMode = next;
      }
    }

    const cycled = wrap.closest('.quick-adjust');

    if (cycled) {
      recomputeQuickFinal(cycled);
    }
  });

  // Quick edit final price (TWO-WAY): final = a*net + b for a set price, or the
  // item base +/- adjust for an adjust. One coefficient set (the item's
  // any-context) for the whole grid, derived client-side (no per-row server
  // cost). Typing the net value computes the final; typing the final back-
  // computes the net value / adjust amount (which is what gets posted).
  const QUICK_COEF = Joomla.getOptions('com_alfa.combinations.priceCoef', { a: 1, b: 0, taxFactor: 1, decimals: 2, baseNet: 0, baseSale: false, discounts: [] });
  const TAX = QUICK_COEF.taxFactor || 1;

  const applyOp = (base, op, amount) => {
    switch (op) {
      case '-': return base - amount;
      case '%': return base * (1 + amount / 100);
      case '-%': return base * (1 - amount / 100);
      default: return base + amount;
    }
  };

  const inverseOp = (base, op, result) => {
    switch (op) {
      case '-': return base - result;
      case '%': return base ? (result / base - 1) * 100 : 0;
      case '-%': return base ? (1 - result / base) * 100 : 0;
      default: return result - base;
    }
  };

  const quickContext = (quick) => ({
    mode: quick.querySelector('[data-quick-mode] input[type="hidden"]')?.value || 'adjust',
    op: quick.querySelector('.op-cycle:not([data-cycle]) input[type="hidden"]')?.value || '+',
    target: quick.querySelector('[data-cycle]:not([data-quick-mode]) input[type="hidden"]')?.value || 'final',
  });

  // The variant's NET price from whatever sits in the value input: a set price
  // is the net itself; an adjust applies the op on the item base net (start) or
  // on the pre-tax-grossed base then divides back (final/gross target).
  const variantNetFrom = (value, mode, op, target) => {
    if (mode === 'price') {
      return value;
    }

    return target === 'start'
      ? applyOp(QUICK_COEF.baseNet, op, value)
      : applyOp(QUICK_COEF.baseNet * TAX, op, value) / TAX;
  };

  // The engine's result for the row ("after discounts"): the row's own sale in
  // price mode, or the item's sale base in adjust mode, takes no regular
  // discount; otherwise final = a*net + b, and never below zero: a fixed amount
  // is capped at what the line holds, so a unit below it is charged nothing.
  const afterDiscounts = (quick, variantNet, mode) => {
    const saleType = quick.dataset.quickSaleType || '';

    if (mode === 'price' && saleType !== '') {
      // The engine floors a sale at zero.
      const sale = Math.max(0, parseFloat(quick.dataset.quickSaleValue));

      return saleType === 'final' ? sale : sale * TAX;
    }

    if (mode === 'adjust' && QUICK_COEF.baseSale) {
      return variantNet * TAX;
    }

    return Math.max(0, QUICK_COEF.a * variantNet + QUICK_COEF.b);
  };

  // Read-only "after discounts" figure. The element only exists when a
  // discount runs, so a missing node is a no-op.
  const writeQuickAfter = (quick, variantNet, mode) => {
    const after = quick.querySelector('[data-quick-after]');

    if (!after) {
      return;
    }

    const value = after.querySelector('[data-quick-after-value]');
    // An empty value input posts no row (the save deletes it), so nothing is shown.
    const figure = isNaN(variantNet) ? NaN : afterDiscounts(quick, variantNet, mode);

    if (isNaN(figure)) {
      after.hidden = true;

      if (value) {
        value.textContent = '';
      }

      return;
    }

    if (value) {
      value.textContent = figure.toFixed(QUICK_COEF.decimals);
    }

    after.hidden = false;
  };

  // net value / adjust amount -> final (gross, BEFORE discounts) + after-discounts
  function recomputeQuickFinal(quick) {
    const finalInput = quick.querySelector('[data-quick-final]');
    const valueInput = quick.querySelector('[data-quick-value]');

    if (!finalInput || !valueInput) {
      return;
    }

    const { mode, op, target } = quickContext(quick);
    const value = parseFloat(valueInput.value);

    if (isNaN(value)) {
      finalInput.value = '';
      writeQuickAfter(quick, NaN, mode);

      return;
    }

    const variantNet = variantNetFrom(value, mode, op, target);

    finalInput.value = (variantNet * TAX).toFixed(QUICK_COEF.decimals);
    writeQuickAfter(quick, variantNet, mode);
  }

  // final (pre-discount gross) -> net value / adjust amount (the posted source)
  function recomputeQuickValue(quick) {
    const finalInput = quick.querySelector('[data-quick-final]');
    const valueInput = quick.querySelector('[data-quick-value]');

    if (!finalInput || !valueInput) {
      return;
    }

    const { mode, op, target } = quickContext(quick);
    const finalPre = parseFloat(finalInput.value);

    if (isNaN(finalPre)) {
      writeQuickAfter(quick, NaN, mode);

      return;
    }

    const variantNet = finalPre / TAX;
    let value;

    if (mode === 'price') {
      value = variantNet;
    } else if (target === 'start') {
      value = inverseOp(QUICK_COEF.baseNet, op, variantNet);
    } else {
      value = inverseOp(QUICK_COEF.baseNet * TAX, op, finalPre);
    }

    // Back-computed to the catalogue's eight places, never shortened.
    valueInput.value = value.toFixed(8);
    writeQuickAfter(quick, variantNet, mode);
  }

  document.addEventListener('input', (event) => {
    const quick = event.target.closest('.quick-adjust');

    if (!quick) {
      return;
    }

    if (event.target.matches('[data-quick-final]')) {
      recomputeQuickValue(quick);
    } else {
      recomputeQuickFinal(quick);
    }
  });

  // Initial fill: run AFTER the grid markup exists (this asset can evaluate
  // before the table is parsed, which left the finals blank on first load).
  const initQuickFinals = () => document.querySelectorAll('.quick-adjust').forEach(recomputeQuickFinal);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initQuickFinals);
  } else {
    initQuickFinals();
  }

  // DIRTY GUARD for the three editor popups (capture phase, runs before
  // bootstrap's modal toggler): Generate / Add reload the page through
  // item.reload, which re-reads the grid from the DB; unsaved inline edits
  // (quick adjust, sku, stock) would visually vanish. Same rule as the Studio.
  const GUARDED_POPUPS = ['#combinationGeneratorModal', '#itemOptionsModal', '#itemInfoModal'];

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-bs-toggle="modal"]');

    if (!trigger || !GUARDED_POPUPS.includes(trigger.getAttribute('data-bs-target')) || !itemFormDirty) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    alert(Joomla.Text._('COM_ALFA_COMBINATION_DIRTY_GUARD'));
  }, true);

  // LAZY popup bodies: fetched on FIRST open via item.fieldsPopup; the
  // item page carries zero editor markup until needed (Studio-iframe idea).
  const LAZY_POPUPS = {
    combinationGeneratorModal: 'generator',
    itemOptionsModal: 'options',
    itemInfoModal: 'info',
  };

  document.addEventListener('show.bs.modal', async (event) => {
    const type = LAZY_POPUPS[event.target.id];

    if (!type || event.target.dataset.loaded) {
      return;
    }

    const container = event.target.querySelector('[data-popup-lazy]');
    const itemId = document.querySelector('[data-field-settings]')?.dataset.itemId || '0';
    const params = new URLSearchParams();
    params.set(Joomla.getOptions('csrf.token', ''), '1');
    params.set('id', itemId);
    params.set('type', type);

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.fieldsPopup&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (json?.data?.html) {
        event.target.dataset.loaded = '1';
        container.outerHTML = json.data.html;

        if (type === 'generator') {
          updateGenerateState();
        } else if (type === 'options') {
          updateAddOptionsState();
        } else if (type === 'info') {
          updateAddInfoState();
        }

        // Drag the value pills to set the CREATION order: the checked values are
        // collected in document order, so a value dragged first is generated/added
        // first (its ordering follows). Bound once per field after the lazy inject.
        if (window.Sortable) {
          event.target.querySelectorAll('[data-sortable-pills]').forEach((field) => {
            if (field.dataset.pillSortableBound) {
              return;
            }

            field.dataset.pillSortableBound = '1';
            window.Sortable.create(field, {
              handle: '.pill-drag',
              draggable: '.value-pill',
              animation: 150,
            });
          });

          // Drag a WHOLE field (by its label) to set the DIMENSION order: generate()
          // collects fields in document order, so a field dragged first becomes the
          // first variation dimension. Locked-out (option-duty) fields stay put.
          const fieldList = event.target.querySelector('[data-sortable-generator-fields]');

          if (fieldList && !fieldList.dataset.fieldSortableBound) {
            fieldList.dataset.fieldSortableBound = '1';
            window.Sortable.create(fieldList, {
              handle: '.tools-label',
              draggable: '.tools-field',
              filter: '.is-locked-out',
              animation: 150,
            });
          }
        }
      }
    } catch (error) {
      // Network hiccup: the spinner stays; closing and reopening retries.
    }
  });

  // Options picker (generator-style, additive)
  const updateAddOptionsState = () => {
    const editor = document.querySelector('[data-options-editor]');
    const button = document.querySelector('[data-add-options]');

    if (editor && button) {
      button.disabled = !editor.querySelector('[data-add-option-value]:checked:not(:disabled)');
    }
  };

  const addOptions = async (button) => {
    const editor = document.querySelector('[data-options-editor]');
    const params = new URLSearchParams();
    params.set(Joomla.getOptions('csrf.token', ''), '1');
    params.set('id', editor.dataset.itemId);

    for (const value of editor.querySelectorAll('[data-add-option-value]:checked:not(:disabled)')) {
      params.append('values[]', value.value);
    }

    button.disabled = true;

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.addOptions&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (json?.data?.added > 0) {
        // New rows exist: re-render through item.reload (the list rows are
        // form fields; unsaved item-form state survives the round-trip).
        reloadItemPage();

        return;
      }
    } catch (error) {
      // Network hiccup: leave the page as is.
    }

    button.disabled = false;
    updateAddOptionsState();
  };

  // Informational picker (generator-style, additive)
  const updateAddInfoState = () => {
    const editor = document.querySelector('[data-info-editor]');
    const button = document.querySelector('[data-add-info]');

    if (editor && button) {
      button.disabled = !editor.querySelector('[data-add-info-value]:checked:not(:disabled)');
    }
  };

  const addInfo = async (button) => {
    const editor = document.querySelector('[data-info-editor]');
    const params = new URLSearchParams();
    params.set(Joomla.getOptions('csrf.token', ''), '1');
    params.set('id', editor.dataset.itemId);

    for (const value of editor.querySelectorAll('[data-add-info-value]:checked:not(:disabled)')) {
      params.append('values[]', value.value);
    }

    button.disabled = true;

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.addInfoValues&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (json?.data?.added > 0) {
        reloadItemPage();

        return;
      }
    } catch (error) {
      // Network hiccup: leave the page as is.
    }

    button.disabled = false;
    updateAddInfoState();
  };

  // Filter: within a field checked values OR together; across fields AND.
  const applyFilters = () => {
    const filters = tools()?.querySelector('[data-combi-filters]');

    if (!filters) {
      return;
    }

    const active = [...filters.querySelectorAll('[data-filter-select]')]
      .map((select) => [...select.selectedOptions].map((option) => option.value))
      .filter((values) => values.length);

    let visible = 0;

    for (const row of gridRows()) {
      const values = (row.dataset.values || '').split('-');
      const match = active.every((wanted) => wanted.some((value) => values.includes(value)));
      row.hidden = !match;

      if (match) {
        visible++;
      } else {
        const checkbox = row.querySelector('[data-select-row]');

        if (checkbox) {
          checkbox.checked = false;
        }
      }
    }

    filters.querySelector('[data-filter-clear]').hidden = !active.length;
    filters.querySelector('[data-filter-empty]').hidden = visible > 0 || !active.length;
    updateBulkBar();
  };

  const selectedRows = () => gridRows().filter(
    (row) => !row.hidden && row.querySelector('[data-select-row]')?.checked,
  );

  const updateBulkBar = () => {
    const trigger = document.querySelector('[data-bulk-trigger]');

    if (!trigger) {
      return;
    }

    const count = selectedRows().length;
    const badge = trigger.querySelector('[data-bulk-count]');
    trigger.disabled = count === 0;
    badge.hidden = count === 0;
    badge.textContent = String(count);
  };

  document.addEventListener('change', (event) => {
    if (event.target.closest('[data-generator]')) {
      updateGenerateState();

      return;
    }

    if (event.target.closest('[data-options-editor]')) {
      updateAddOptionsState();

      return;
    }

    if (event.target.closest('[data-info-editor]')) {
      updateAddInfoState();

      return;
    }

    if (event.target.closest('[data-combi-filters]')) {
      applyFilters();

      return;
    }

    if (event.target.matches('[data-select-all]')) {
      for (const row of gridRows()) {
        if (!row.hidden) {
          const checkbox = row.querySelector('[data-select-row]');

          if (checkbox) {
            checkbox.checked = event.target.checked;
          }
        }
      }

      updateBulkBar();

      return;
    }

    if (event.target.matches('[data-select-row]')) {
      updateBulkBar();
    }
  });

  document.addEventListener('click', (event) => {
    if (event.target.closest('[data-generate]')) {
      generate();

      return;
    }

    // "Show only": reveal/hide the grid filter panel from the header.
    const filterToggle = event.target.closest('[data-combi-filter-toggle]');

    if (filterToggle) {
      const panel = document.querySelector('[data-combi-filters]');

      if (panel) {
        const open = panel.hidden;
        panel.hidden = !open;
        filterToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        filterToggle.classList.toggle('active', open);
      }

      return;
    }

    const addInfoButton = event.target.closest('[data-add-info]');

    if (addInfoButton) {
      addInfo(addInfoButton);

      return;
    }

    // Informational removal: deferred like options/variants.
    const infoRemove = event.target.closest('[data-info-remove]');

    if (infoRemove) {
      const infoItem = infoRemove.closest('[data-info-item]');
      const deleted = document.getElementById('deleted-info-value-ids');

      if (deleted) {
        deleted.value = deleted.value
          ? deleted.value + ',' + infoItem.dataset.infoItem
          : infoItem.dataset.infoItem;
      }

      infoItem.remove();

      const panel = document.querySelector('[data-info-panel]');

      if (panel && !panel.querySelector('[data-info-item]')) {
        panel.querySelector('[data-info-empty]').hidden = false;
      }

      return;
    }

    const addButton = event.target.closest('[data-add-options]');

    if (addButton) {
      addOptions(addButton);

      return;
    }

    // Option removal: deferred like the variants grid; the id lands in the
    // hidden deleted-ids input, the row leaves the DOM, the hard delete
    // happens on item save.
    const optionRemove = event.target.closest('[data-option-remove]');

    if (optionRemove) {
      const optionItem = optionRemove.closest('[data-option-item]');
      const deleted = document.getElementById('deleted-option-value-ids');

      if (deleted) {
        deleted.value = deleted.value
          ? deleted.value + ',' + optionItem.dataset.optionItem
          : optionItem.dataset.optionItem;
      }

      optionItem.remove();

      const panel = document.querySelector('[data-options-panel]');

      if (panel && !panel.querySelector('[data-option-item]')) {
        panel.querySelector('[data-options-empty]').hidden = false;
      }

      return;
    }

    if (event.target.closest('[data-filter-clear]')) {
      for (const fancy of tools()?.querySelectorAll('[data-combi-filters] joomla-field-fancy-select') || []) {
        fancy.choicesInstance?.removeActiveItems();
      }

      for (const select of tools()?.querySelectorAll('[data-filter-select]') || []) {
        for (const option of select.options) {
          option.selected = false;
        }
      }

      applyFilters();

      return;
    }

    const bulkState = event.target.closest('[data-bulk-state]');

    if (bulkState) {
      for (const row of selectedRows()) {
        const state = row.querySelector('input[name$="[state]"]');

        if (state) {
          state.checked = bulkState.dataset.bulkState === '1';
        }
      }

      return;
    }

    if (event.target.closest('[data-bulk-stock]')) {
      const stock = prompt(Joomla.Text._('COM_ALFA_COMBI_BULK_STOCK_PROMPT'));

      if (stock === null) {
        return;
      }

      for (const row of selectedRows()) {
        const input = row.querySelector('input[name$="[stock]"]');

        if (input) {
          input.value = stock;
        }
      }

      return;
    }

    if (event.target.closest('[data-bulk-delete]')) {
      const rows = selectedRows();

      if (!rows.length || !confirm(Joomla.Text._('COM_ALFA_COMBI_BULK_DELETE_CONFIRM').replace('%d', String(rows.length)))) {
        return;
      }

      // Reuse the single-row delete path: id lands in the deleted-ids input
      // and the row leaves the DOM; the hard delete happens on item save.
      for (const row of rows) {
        row.querySelector('[data-combination-remove]')?.click();
      }

      updateBulkBar();
    }
  });

  window.addEventListener('message', (event) => {
    if (event.origin !== window.location.origin || !event.data
        || (event.data.messageType !== 'alfa:combination-action'
            && event.data.messageType !== 'alfa:combination-dirty')) {
      return;
    }

    if (event.data.messageType === 'alfa:combination-dirty') {
      studioDirty = true;

      return;
    }

    if (event.data.saved) {
      // Fresh DB state exists: re-render the parent when the dialog closes.
      pendingReload = true;
      itemFormDirty = false;
      studioDirty = false;
    }

    if (event.data.shouldClose) {
      close();
    }
  });
})();

/*
 * Per-field visibility (ShowOn) popup: independent of the combinations
 * logic above. The shared #itemVisibilityModal is reused for every field:
 * on open it lazily fetches that field's builder (item.fieldsPopup
 * type=visibility) and on Save posts the canonical rule (item.saveVisibility).
 * Mirrors the options/generator AJAX pattern; zero plugin dispatch.
 */
(function () {
  'use strict';

  const token = () => Joomla.getOptions('csrf.token', '');
  const spinner = '<div class="text-center p-4"><span class="spinner-border" role="status" aria-hidden="true"></span></div>';
  const itemId = () => document.querySelector('[data-field-settings]')?.dataset.itemId || '0';
  let current = null;

  // ONE delegated listener, robust to load timing (the script is NOT deferred,
  // so the modal may not exist at parse time). The modal/body are looked up at
  // CLICK time, when the DOM is in place, never captured at load.
  document.addEventListener('click', async (event) => {
    const open = event.target.closest('[data-showon-open]');

    if (open) {
      const modal = document.getElementById('itemVisibilityModal');

      if (!modal) {
        return;
      }

      const body = modal.querySelector('[data-showon-body]');
      current = { fieldId: open.dataset.fieldId, button: open };
      body.innerHTML = spinner;
      bootstrap.Modal.getOrCreateInstance(modal).show();

      const params = new URLSearchParams();
      params.set(token(), '1');
      params.set('id', itemId());
      params.set('type', 'visibility');
      params.set('field_id', current.fieldId);

      try {
        const response = await fetch('index.php?option=com_alfa&task=item.fieldsPopup&format=json', {
          method: 'POST',
          body: params,
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const json = await response.json();

        if (json?.data?.html) {
          body.innerHTML = json.data.html;
          const aso = body.querySelector('.aso[data-config]');

          if (aso && window.Alfa?.ShowonBuilder) {
            window.Alfa.ShowonBuilder.init(aso);
          }
        }
      } catch (error) {
        // Network hiccup: spinner stays; closing + reopening retries.
      }

      return;
    }

    const save = event.target.closest('[data-showon-save]');

    if (!save || !current) {
      return;
    }

    const modal = document.getElementById('itemVisibilityModal');
    const body = modal ? modal.querySelector('[data-showon-body]') : null;
    const store = body ? body.querySelector('[data-visibility-editor] [data-aso-store]') : null;
    const rule = store ? store.value : '';

    const params = new URLSearchParams();
    params.set(token(), '1');
    params.set('id', itemId());
    params.set('field_id', current.fieldId);
    params.set('showon', rule);

    save.disabled = true;

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.saveVisibility&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (json?.data?.saved !== undefined) {
        // Swap the inline control in place from the server's authoritative state +
        // human summary (no reload, no JS re-implementation of the wording).
        const control = current.button.closest('[data-showon-control]');

        if (control) {
          const state = json.data.state || 'none';
          const summary = json.data.summary || '';
          const badge = control.querySelector('[data-showon-badge]');
          const sum = control.querySelector('[data-showon-summary]');
          const label = control.querySelector('[data-showon-edit-label]');

          control.dataset.showonState = state;

          if (badge) {
            badge.textContent = state === 'set'
              ? Joomla.Text._('COM_ALFA_SHOWON_BADGE_CUSTOM')
              : (state === 'inherited'
                ? Joomla.Text._('COM_ALFA_SHOWON_BADGE_GLOBAL')
                : (state === 'invalid' ? Joomla.Text._('COM_ALFA_SHOWON_BADGE_INVALID') : ''));
          }

          if (sum) {
            sum.textContent = summary || Joomla.Text._('COM_ALFA_SHOWON_ALWAYS');
          }

          if (label) {
            label.textContent = Joomla.Text._(state === 'none' ? 'COM_ALFA_SHOWON_SET' : 'COM_ALFA_SHOWON_EDIT');
          }
        }

        if (modal) {
          bootstrap.Modal.getOrCreateInstance(modal).hide();
        }
      }
    } catch (error) {
      // Leave the dialog open so the user can retry.
    } finally {
      save.disabled = false;
    }
  });
})();
