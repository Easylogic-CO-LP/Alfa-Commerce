/**
 * Compact price-matrix editor: delegated row add/remove and the
 * price/adjust mode switch. Behavior binds to data-price-* attributes
 * (classes are styling-only); every handler requires an .alfa-prices
 * ancestor so the document-level delegation never reacts to foreign
 * markup. One listener serves every editor on the page (item prices +
 * each combination's editor).
 */
(() => {
  'use strict';

  if (window.alfaPricesInit) {
    return;
  }

  window.alfaPricesInit = true;

  // Two-way net <-> final (tax-inclusive) sync: taxes ONLY, so the stored
  // prices never depend on today's discounts. The full engine result
  // (final = a*net + b, discounts included, coefficients from two real
  // engine runs server-side) feeds the read-only "after discounts" figure.
  const calc = (row) => ({
    a: parseFloat(row.dataset.calcA || '1') || 1,
    b: parseFloat(row.dataset.calcB || '0') || 0,
    tax: parseFloat(row.dataset.taxFactor || '1') || 1,
    dec: parseInt(row.dataset.decimals || '2', 10),
    // The item base net an adjust row applies its delta to, resolved by the
    // storefront engine under this row's own context and quantity tier, the
    // same row set as its tax factor.
    base: parseFloat(row.dataset.baseNet || '0') || 0,
    // Whether that base is a sale: then the engine runs no regular discount.
    baseSale: row.dataset.baseSale === '1',
  });

  // input step for N decimal places: 2 -> 0.01, 0 -> 1, 8 -> 0.00000001
  const stepOf = (places) => (places > 0 ? '0.' + '0'.repeat(places - 1) + '1' : '1');

  const pairs = [
    ['[data-price-value]', '[data-price-final]', (v, c) => v * c.tax, (c) => c.dec],
    // A typed with-tax price back-computes the canonical base to eight places.
    ['[data-price-final]', '[data-price-value]', (v, c) => v / c.tax, () => 8],
  ];

  const applyOp = (base, op, amount) => {
    switch (op) {
      case '-': return base - amount;
      case '%': return base * (1 + amount / 100);
      case '-%': return base * (1 - amount / 100);
      default: return base + amount;
    }
  };

  const rowMode = (row) => row.dataset.mode || 'price';

  // The variant NET implied by a row: a set price IS the net; an adjust applies
  // its op on the item base net (start) or on the grossed base, back to net (final).
  const effectiveNet = (row, c) => {
    const v = parseFloat(row.querySelector('[data-price-value]')?.value);

    if (isNaN(v)) {
      return NaN;
    }

    if (rowMode(row) === 'price') {
      return v;
    }

    const op = row.querySelector('[name$="[operation]"]')?.value || '+';
    const target = row.querySelector('[name$="[adjust_target]"]:checked, [data-price-target]')?.value || 'final';
    const base = c.base;

    return target === 'start' ? applyOp(base, op, v) : applyOp(base * c.tax, op, v) / c.tax;
  };

  // The row's "after discounts" figure is the engine's result for it: a row
  // carrying its own sale, or an adjust on a sale base, takes no regular
  // discount; any other row is a*net + b, and never below zero: a fixed amount
  // is capped at what the line holds, so a unit below it is charged nothing.
  const afterDiscounts = (row, c, net) => {
    const sale = row.dataset.override || '';
    const amount = parseFloat(row.querySelector('[data-price-override]')?.value);

    // A sale counts once its input holds an amount, and never below zero: the
    // engine prices a row with no amount as a*net + b and floors a sale at zero.
    if (rowMode(row) === 'price' && sale !== '' && !Number.isNaN(amount)) {
      const floored = Math.max(0, amount);

      return sale === 'final' ? floored : floored * c.tax;
    }

    if (rowMode(row) === 'adjust' && c.baseSale) {
      return net * c.tax;
    }

    return Math.max(0, (net * c.a) + c.b);
  };

  const refreshCurrent = (row, net) => {
    const current = row.querySelector('[data-price-current]');

    if (!current) {
      return;
    }

    const c = calc(row);
    const rowNet = net ?? (rowMode(row) === 'adjust' ? effectiveNet(row, c) : parseFloat(row.querySelector('[data-price-value]')?.value));
    const figure = Number.isNaN(rowNet) ? NaN : afterDiscounts(row, c, rowNet);

    current.textContent = Number.isNaN(figure) ? '-' : figure.toFixed(c.dec);
  };

  const inverseOp = (base, op, result) => {
    switch (op) {
      case '-': return base - result;
      case '%': return base ? (result / base - 1) * 100 : 0;
      case '-%': return base ? (1 - result / base) * 100 : 0;
      default: return result - base;
    }
  };

  // Adjust mode, amount -> final (gross, BEFORE discounts) + after-discounts.
  // The final stays editable (two-way, like the grid quick edit).
  const refreshAdjustFigures = (row) => {
    if (rowMode(row) !== 'adjust') {
      return;
    }

    const c = calc(row);
    const net = effectiveNet(row, c);
    const finalEl = row.querySelector('[data-price-final]');

    if (finalEl) {
      finalEl.value = isNaN(net) ? '' : (net * c.tax).toFixed(c.dec);
    }

    refreshCurrent(row, net);
  };

  // Adjust mode, final -> amount (the posted source). Reverse of effectiveNet.
  const refreshAdjustAmount = (row) => {
    if (rowMode(row) !== 'adjust') {
      return;
    }

    const c = calc(row);
    const finalEl = row.querySelector('[data-price-final]');
    const valueEl = row.querySelector('[data-price-value]');
    const finalPre = parseFloat(finalEl?.value);

    if (Number.isNaN(finalPre)) {
      return;
    }

    const op = row.querySelector('[name$="[operation]"]')?.value || '+';
    const target = row.querySelector('[name$="[adjust_target]"]:checked, [data-price-target]')?.value || 'final';
    const base = c.base;
    const variantNet = finalPre / c.tax;
    const amount = target === 'start'
      ? inverseOp(base, op, variantNet)
      : inverseOp(base * c.tax, op, finalPre);

    if (valueEl) {
      valueEl.value = amount.toFixed(8);
    }

    refreshCurrent(row, variantNet);
  };

  // Apply a mode switch's consequences: adjust derives a read-only final; price
  // restores the editable net<->gross pair from the current net value.
  const syncModeState = (row) => {
    const finalEl = row.querySelector('[data-price-final]');

    if (rowMode(row) === 'adjust') {
      refreshAdjustFigures(row);
    } else {
      if (finalEl) {
        finalEl.readOnly = false;
      }

      row.querySelector('[data-price-value]')?.dispatchEvent(new Event('input', { bubbles: true }));
    }
  };

  // Custom discount hint: the computed other end of the pinned price.
  // Starting shows the resulting final (taxes on top), Final shows the net.
  const updateOverrideHint = (row) => {
    const hint = row.querySelector('[data-price-override-hint]');

    if (!hint) {
      return;
    }

    const type = row.querySelector('[data-price-override-type]:checked')?.value || '';
    const amount = parseFloat(row.querySelector('[data-price-override]')?.value);

    if (type === '' || Number.isNaN(amount)) {
      hint.hidden = true;

      return;
    }

    const c = calc(row);
    hint.hidden = false;
    hint.textContent = type === 'start'
      ? Joomla.Text._('COM_ALFA_PRICES_FINAL') + ': ' + (amount * c.tax).toFixed(c.dec)
      : Joomla.Text._('COM_ALFA_PRICES_PRICE') + ': ' + (amount / (c.tax || 1)).toFixed(c.dec + 2);
  };

  document.addEventListener('input', (event) => {
    const field = event.target;
    const row = field.closest('.price-row');

    if (!row || !row.closest('.alfa-prices')) {
      return;
    }

    if (field.matches('[data-price-override]')) {
      updateOverrideHint(row);
      refreshCurrent(row);

      return;
    }

    // Adjust mode (two-way): typing the amount drives the final; typing the
    // final back-computes the amount, like the grid quick edit.
    if (rowMode(row) === 'adjust') {
      if (field.matches('[data-price-final]')) {
        refreshAdjustAmount(row);
      } else if (field.matches('[data-price-value]')) {
        refreshAdjustFigures(row);
      }

      return;
    }

    for (const [from, to, convert, decimals] of pairs) {
      if (field.matches(from)) {
        const twin = row.querySelector(to);

        // A shut with-tax box (a new item: no tax yet) shows no figure.
        if (twin && !twin.disabled) {
          const c = calc(row);
          twin.value = field.value === '' ? '' : convert(parseFloat(field.value), c).toFixed(decimals(c));
        }

        refreshCurrent(row);

        return;
      }
    }
  });

  // LIVE context refresh: when a row's pricing dimension changes, fetch
  // fresh engine coefficients + applied taxes/discounts for the new context
  // from item.pricePreview; the preview never goes stale.
  const DIMENSIONS = ['currency_id', 'country_id', 'usergroup_id', 'user_id', 'quantity_start', 'quantity_end'];

  const applyPreview = (row, preview) => {
    row.dataset.calcA = preview.a;
    row.dataset.calcB = preview.b;
    row.dataset.taxFactor = preview.taxFactor;
    row.dataset.decimals = preview.decimals ?? 2;
    row.dataset.baseNet = preview.baseNet;
    row.dataset.baseSale = preview.baseSale ? '1' : '0';

    const c = calc(row);
    const net = row.querySelector('[data-price-value]');
    const final = row.querySelector('[data-price-final]');
    const netValue = parseFloat(net?.value);

    if (net) {
      net.step = stepOf(8);
    }

    if (final) {
      final.step = stepOf(c.dec);

      // Price mode: net x tax. Adjust mode: derived from the base (handled below).
      if (rowMode(row) !== 'adjust') {
        final.value = Number.isNaN(netValue) ? '' : (netValue * c.tax).toFixed(c.dec);
      }
    }

    if (rowMode(row) === 'adjust') {
      refreshAdjustFigures(row);
    }

    updateOverrideHint(row);

    const taxes = preview.taxes || [];
    const discounts = preview.discounts || [];

    const discountChip = (discount) => {
      const chip = document.createElement('span');
      chip.className = 'applied-discount';
      // The rate the engine actually applied, or no rate at all: the same
      // predicate the storefront breakdown uses, so the two cannot disagree about
      // one rule. A sale/override row is a fixed amount carrying a real,
      // money-derived rate, and it keeps it.
      chip.textContent = Number(discount.percent) > 0
        ? discount.name + ' −' + Number(discount.percent) + '%'
        : discount.name;

      return chip;
    };

    const taxChip = (tax) => {
      const chip = document.createElement('span');
      chip.className = 'applied-tax';
      chip.textContent = tax.name + ' +' + Number(tax.rate) + '%';

      return chip;
    };

    // ONE pipeline line under Final price, in APPLICATION order like the
    // frontend breakdown: before-tax discounts -> taxes -> after-tax discounts.
    row.querySelector('[data-price-pipeline]')?.replaceChildren(
      ...discounts.filter((d) => (d.timing || 'before_tax') === 'before_tax').map(discountChip),
      ...taxes.map(taxChip),
      ...discounts.filter((d) => (d.timing || 'before_tax') === 'after_tax').map(discountChip),
    );

    // Today's selling price (only meaningful while discounts run).
    const discountCell = row.querySelector('[data-price-discounts]');

    if (discountCell) {
      discountCell.hidden = !discounts.length;
      refreshCurrent(row);
    }
  };

  const refreshPreview = async (row, root) => {
    const itemId = parseInt(root.dataset.itemId || '0', 10);

    if (!itemId) {
      return;
    }

    const params = new URLSearchParams();
    params.set(Joomla.getOptions('csrf.token', ''), '1');
    params.set('id', itemId);

    for (const dimension of DIMENSIONS) {
      const field = row.querySelector('[name$="[' + dimension + ']"]');

      if (field) {
        params.set(dimension, field.value);
      }
    }

    // Stale-response guard: only the latest request may apply.
    const sequence = (row.previewSequence = (row.previewSequence || 0) + 1);

    try {
      const response = await fetch('index.php?option=com_alfa&task=item.pricePreview&format=json', {
        method: 'POST',
        body: params,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json();

      if (sequence === row.previewSequence && json && json.data && !json.error) {
        applyPreview(row, json.data);
      }
    } catch (error) {
      // Network hiccup: keep the previous coefficients.
    }
  };

  document.addEventListener('change', (event) => {
    const mode = event.target.closest('[data-price-mode]');

    if (mode && mode.closest('.alfa-prices')) {
      const row = mode.closest('.price-row');
      row.dataset.mode = mode.value;
      syncModeState(row);

      return;
    }

    // Adjust net/gross target changed: refresh the derived final + after.
    const adjustTarget = event.target.closest('[name$="[adjust_target]"]');

    if (adjustTarget && adjustTarget.closest('.alfa-prices')) {
      refreshAdjustFigures(adjustTarget.closest('.price-row'));

      return;
    }

    // Override three-state: Off disables (and clears) the value input; an
    // active override mutes the bypassed Price/Final pair via data-override.
    const overrideType = event.target.closest('[data-price-override-type]');

    if (overrideType && overrideType.closest('.alfa-prices')) {
      const valueInput = overrideType.closest('.price-cell').querySelector('[data-price-override]');
      valueInput.disabled = overrideType.value === '';

      // The sale is posted as typed, to eight places: the save judges it.
      valueInput.step = stepOf(8);

      if (overrideType.value === '') {
        valueInput.value = '';
      }

      const overrideRow = overrideType.closest('.price-row');
      overrideRow.dataset.override = overrideType.value;
      updateOverrideHint(overrideRow);
      refreshCurrent(overrideRow);

      return;
    }

    const row = event.target.closest('.price-row');
    const root = event.target.closest('.alfa-prices');
    const name = event.target.getAttribute('name') || '';

    if (row && root && DIMENSIONS.some((dimension) => name.endsWith('[' + dimension + ']'))) {
      refreshPreview(row, root);
    }
  });

  const insertRow = (wrap) => {
    const rows = wrap.querySelector('[data-price-rows]');
    const tpl = wrap.querySelector('[data-price-template]');
    const idx = 'n' + Date.now().toString(36) + rows.children.length;

    rows.insertAdjacentHTML('beforeend', tpl.innerHTML.replaceAll('__PIDX__', idx));

    // Core Joomla's re-init contract: dynamically added fields (calendar,
    // ...) initialize themselves when this event reaches them.
    const row = rows.lastElementChild;
    row.dispatchEvent(new CustomEvent('joomla:updated', { bubbles: true }));

    return row;
  };

  // Duplicate a rule: every field value crosses over EXCEPT the id (the copy
  // is a new row). The fast path for period promos: copy the base price,
  // switch the copy's custom discount on, set its Active window.
  const copyRow = (source, wrap) => {
    const target = insertRow(wrap);
    const suffix = (el) => ((el.getAttribute('name') || '').match(/\[([a-z_]+)\]$/) || [])[1];
    const targets = [...target.querySelectorAll('input[name], select[name]')];

    for (const field of source.querySelectorAll('input[name], select[name]')) {
      const key = suffix(field);

      if (!key || key === 'id') {
        continue;
      }

      if (field.type === 'radio') {
        if (field.checked) {
          const twin = targets.find((t) => t.type === 'radio' && suffix(t) === key && t.value === field.value);

          if (twin) {
            twin.checked = true;
          }
        }

        continue;
      }

      if (field.type === 'checkbox') {
        const twin = targets.find((t) => t.type === 'checkbox' && suffix(t) === key);

        if (twin) {
          twin.checked = field.checked;
        }

        continue;
      }

      if (field.type === 'hidden') {
        // The row id was already excluded above; copy the remaining STATE-bearing
        // hidden inputs (mode / operation / adjust_target) so a duplicated adjust
        // rule stays an adjust rule instead of silently reverting to a set-price rule.
        const twin = targets.find((t) => t.type === 'hidden' && suffix(t) === key);

        if (twin) {
          twin.value = field.value;
        }

        continue;
      }

      const twin = targets.find((t) => t.type === field.type && t.tagName === field.tagName && suffix(t) === key);

      if (twin) {
        twin.value = field.value;
      }
    }

    // Re-derive the presentation state from the copied values: mode attr,
    // override enable/step/hint, final twin + today figure, and fresh
    // engine coefficients for the copied dimensions.
    target.querySelector('[data-price-mode]')?.dispatchEvent(new Event('change', { bubbles: true }));
    target.querySelector('[data-price-override-type]:checked')?.dispatchEvent(new Event('change', { bubbles: true }));
    target.querySelector('[data-price-override]')?.dispatchEvent(new Event('input', { bubbles: true }));
    target.querySelector('[data-price-value]')?.dispatchEvent(new Event('input', { bubbles: true }));
    target.querySelector('[name$="[currency_id]"]')?.dispatchEvent(new Event('change', { bubbles: true }));
  };

  document.addEventListener('click', (event) => {
    // Mode cycler (set <-> adjust): same outer chrome as the grid. Flip the
    // hidden value/label, then let the change handler apply the consequences.
    const modeCycle = event.target.closest('[data-price-mode-cycle]');

    if (modeCycle && modeCycle.closest('.alfa-prices')) {
      const hidden = modeCycle.closest('.mode-cycle').querySelector('[data-price-mode]');
      const next = hidden.value === 'adjust' ? 'price' : 'adjust';
      const label = modeCycle.dataset[next === 'adjust' ? 'labelAdjust' : 'labelPrice'] || next;

      hidden.value = next;
      modeCycle.textContent = label;
      modeCycle.title = label;
      hidden.dispatchEvent(new Event('change', { bubbles: true }));

      return;
    }

    // Apply-on cycler (net <-> gross): clickable like the grid quick edit.
    const targetCycle = event.target.closest('[data-price-target-cycle]');

    if (targetCycle && targetCycle.closest('.alfa-prices')) {
      const hidden = targetCycle.closest('.op-cycle').querySelector('[data-price-target]');
      const next = hidden.value === 'final' ? 'start' : 'final';
      const label = targetCycle.dataset[next === 'final' ? 'labelFinal' : 'labelStart'] || next;

      hidden.value = next;
      targetCycle.textContent = label;
      targetCycle.title = label;
      refreshAdjustFigures(targetCycle.closest('.price-row'));

      return;
    }

    const add = event.target.closest('[data-price-add]');

    if (add && add.closest('.alfa-prices')) {
      insertRow(add.closest('.alfa-prices'));

      return;
    }

    const copy = event.target.closest('[data-price-copy]');

    if (copy && copy.closest('.alfa-prices')) {
      copyRow(copy.closest('.price-row'), copy.closest('.alfa-prices'));

      return;
    }

    const remove = event.target.closest('[data-price-remove]');

    if (remove && remove.closest('.alfa-prices')) {
      remove.closest('.price-row').remove();
    }
  });

  // Initial fill: adjust rows render their final/after empty server-side (the
  // delta needs the base net), so derive them once the markup exists.
  const initAdjustRows = () => document.querySelectorAll('.alfa-prices .price-row[data-mode="adjust"]').forEach(refreshAdjustFigures);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAdjustRows);
  } else {
    initAdjustRows();
  }
})();
