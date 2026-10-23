/**
 * Cart rule condition builder.
 *
 * Owns add / remove / renumber for the group and condition rows, and keeps each
 * row's values lookup pointed at the match type that row is set to.
 *
 * We renumber ourselves because core's subform does not: its cloner skips
 * NESTED contents when it renumbers, so an added group posted its conditions
 * under the template's placeholder index and the tree persisted as a
 * condition-less group, which matches every cart. Our shape is known (groups,
 * each with condition rows, exactly two levels), so reindexing it is simple and
 * total: after ANY structural change every input name is rewritten from its
 * position in the DOM. Nothing is left to a clone-time substitution that could
 * miss a branch.
 *
 * No markup is created here. Rows are cloned from server-rendered <template>s.
 *
 * @package  Alfa Commerce
 * @since    2.0.0
 */
((document) => {
  'use strict';

  const GROUP_PLACEHOLDER = '__GIDX__';
  const CONDITION_PLACEHOLDER = '__CIDX__';
  const TYPE_PARAM = /([?&]type=)[^&]*/;

  const root = (el) => el.closest('[data-alfa-conditions]');

  /**
   * Rewrite every input name from its position in the DOM.
   *
   * The single source of truth for indexes is where a row sits, not what it was
   * cloned from, which is exactly the assumption core's cloner breaks.
   */
  const reindex = (container) => {
    const base = container.dataset.name;

    container.querySelectorAll('[data-group]').forEach((group, gi) => {
      const minQty = group.querySelector('input[type="number"]');

      if (minQty) {
        minQty.name = `${base}[${gi}][min_quantity]`;
        // The label names the input by an id made from the same position.
        minQty.id = `${container.dataset.id}_${gi}_min_quantity`;

        const minQtyLabel = group.querySelector('[data-min-qty-label]');

        if (minQtyLabel) {
          minQtyLabel.htmlFor = minQty.id;
        }
      }

      group.querySelectorAll('[data-condition]').forEach((condition, ci) => {
        const prefix = `${base}[${gi}][conditions][${ci}]`;
        const type = condition.querySelector('[data-condition-type]');
        const operator = condition.querySelector('select:not([data-condition-type]):not([multiple])');
        const values = condition.querySelector('select[multiple]');

        if (type) {
          type.name = `${prefix}[type]`;
        }

        if (operator) {
          operator.name = `${prefix}[exclude]`;
        }

        if (values) {
          values.name = `${prefix}[values][]`;
        }
      });
    });
  };

  /** Clone a <template>'s single element child. */
  const cloneFrom = (container, selector) => {
    const tpl = container.querySelector(selector);

    return tpl ? tpl.content.firstElementChild.cloneNode(true) : null;
  };

  /**
   * A cloned row still carries the placeholder indexes in any attribute the
   * reindexer does not own (ids, labels). Strip them so nothing collides before
   * reindex() assigns the real names.
   */
  const clearPlaceholders = (row) => {
    row.querySelectorAll('[id], [for]').forEach((el) => {
      ['id', 'for'].forEach((attr) => {
        const v = el.getAttribute(attr);

        if (v && (v.includes(GROUP_PLACEHOLDER) || v.includes(CONDITION_PLACEHOLDER))) {
          el.removeAttribute(attr);
        }
      });
    });
  };

  const addCondition = (group, container) => {
    const row = cloneFrom(container, '[data-condition-template]');

    if (!row) {
      return;
    }

    clearPlaceholders(row);
    group.querySelector('[data-conditions]').appendChild(row);
    reindex(container);
  };

  document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-add-group], [data-add-condition], [data-remove-group], [data-remove-condition]');

    if (!button) {
      return;
    }

    const container = root(button);

    if (!container) {
      return;
    }

    event.preventDefault();

    if (button.hasAttribute('data-add-group')) {
      const group = cloneFrom(container, '[data-group-template]');

      if (group) {
        clearPlaceholders(group);
        container.querySelector('[data-groups]').appendChild(group);
        // A group with no condition matches nothing and the model refuses it,
        // so open with one row rather than an empty shell.
        addCondition(group, container);
      }
    } else if (button.hasAttribute('data-add-condition')) {
      addCondition(button.closest('[data-group]'), container);
    } else if (button.hasAttribute('data-remove-group')) {
      button.closest('[data-group]').remove();
      reindex(container);
    } else if (button.hasAttribute('data-remove-condition')) {
      const group = button.closest('[data-group]');
      button.closest('[data-condition]').remove();

      // A group whose last condition just went would be refused on save.
      if (!group.querySelector('[data-condition]')) {
        group.remove();
      }

      reindex(container);
    }
  });

  /** Repoint a row's lookup when its match type changes. */
  document.addEventListener('change', (event) => {
    const select = event.target;

    if (!(select instanceof HTMLSelectElement) || !select.matches('[data-condition-type]')) {
      return;
    }

    const row = select.closest('[data-condition]');
    const picker = row ? row.querySelector('[data-alfa-condition-values]') : null;

    if (!picker) {
      return;
    }

    const url = picker.getAttribute('url');

    if (url && TYPE_PARAM.test(url)) {
      picker.setAttribute('url', url.replace(TYPE_PARAM, `$1${encodeURIComponent(select.value)}`));
    }

    // Ids chosen for the previous type mean nothing to the new one. Clearing
    // through the underlying select keeps the posted value and the widget in
    // step; poking at Choices' internals would not.
    const inner = picker.querySelector('select');

    if (inner) {
      inner.querySelectorAll('option').forEach((option) => option.remove());
      inner.dispatchEvent(new Event('change', { bubbles: true }));
    }
  });
})(document);
