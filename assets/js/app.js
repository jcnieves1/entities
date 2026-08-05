// Entity System - small client-side helpers (no framework needed)
document.addEventListener('DOMContentLoaded', function () {
  // Confirm dialogs on delete forms/links
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) {
        e.preventDefault();
      }
    });
  });

  // Dynamic field rows in the entity builder (admin/entity_edit.php)
  var addFieldBtn = document.getElementById('add-field-btn');
  var fieldsWrap = document.getElementById('fields-wrap');
  if (addFieldBtn && fieldsWrap) {
    addFieldBtn.addEventListener('click', function () {
      var idx = fieldsWrap.querySelectorAll('.field-row').length;
      var tpl = document.getElementById('field-row-template').innerHTML.replace(/__INDEX__/g, idx);
      var div = document.createElement('div');
      div.innerHTML = tpl;
      fieldsWrap.appendChild(div.firstElementChild);
    });
    fieldsWrap.addEventListener('click', function (e) {
      if (e.target.classList.contains('remove-field-btn')) {
        e.preventDefault();
        var row = e.target.closest('.field-row');
        if (fieldsWrap.querySelectorAll('.field-row').length > 1) {
          row.remove();
        }
      }
    });
  }

  // Hide/show a condition row's value input depending on its chosen operator
  // (is_null / is_not_null need no value at all).
  function updateCondValueVisibility(row) {
    var opSelect = row.querySelector('.cond-operator');
    var valueInput = row.querySelector('.cond-value');
    if (!opSelect || !valueInput) { return; }
    var needsValue = ['is_null', 'is_not_null'].indexOf(opSelect.value) === -1;
    valueInput.style.display = needsValue ? '' : 'none';
    if (!needsValue) { valueInput.value = ''; }
  }

  // Dynamic OR-group / AND-row builder for admin/field_conditions.php
  var groupsWrap = document.getElementById('cond-groups-wrap');
  if (groupsWrap) {
    groupsWrap.querySelectorAll('.cond-row').forEach(updateCondValueVisibility);

    function nextGroupIndex() {
      var max = -1;
      groupsWrap.querySelectorAll('.cond-group').forEach(function (g) {
        max = Math.max(max, parseInt(g.getAttribute('data-group-idx'), 10) || 0);
      });
      return max + 1;
    }

    var addGroupBtn = document.getElementById('add-cond-group');
    if (addGroupBtn) {
      addGroupBtn.addEventListener('click', function () {
        var g = nextGroupIndex();
        var tpl = document.getElementById('cond-group-template').innerHTML.replace(/__G__/g, g);
        var div = document.createElement('div');
        div.innerHTML = tpl;
        var groupEl = div.firstElementChild;
        groupsWrap.appendChild(groupEl);
        groupEl.querySelectorAll('.cond-row').forEach(updateCondValueVisibility);
      });
    }

    groupsWrap.addEventListener('click', function (e) {
      if (e.target.classList.contains('add-cond-row')) {
        e.preventDefault();
        var group = e.target.closest('.cond-group');
        var rowsWrap = group.querySelector('.cond-rows');
        var g = group.getAttribute('data-group-idx');
        var c = rowsWrap.querySelectorAll('.cond-row').length;
        var tpl = document.getElementById('cond-row-template').innerHTML.replace(/__G__/g, g).replace(/__C__/g, c);
        var div = document.createElement('div');
        div.innerHTML = tpl;
        var rowEl = div.firstElementChild;
        rowsWrap.appendChild(rowEl);
        updateCondValueVisibility(rowEl);
      } else if (e.target.classList.contains('remove-cond-row')) {
        e.preventDefault();
        var row = e.target.closest('.cond-row');
        var rowsWrap2 = row.closest('.cond-rows');
        row.remove();
        if (rowsWrap2 && rowsWrap2.querySelectorAll('.cond-row').length === 0) {
          var grp = rowsWrap2.closest('.cond-group');
          if (grp && groupsWrap.querySelectorAll('.cond-group').length > 1) { grp.remove(); }
        }
      } else if (e.target.classList.contains('remove-cond-group')) {
        e.preventDefault();
        if (groupsWrap.querySelectorAll('.cond-group').length > 1) {
          e.target.closest('.cond-group').remove();
        }
      }
    });

    groupsWrap.addEventListener('change', function (e) {
      if (e.target.classList.contains('cond-operator')) {
        updateCondValueVisibility(e.target.closest('.cond-row'));
      }
    });
  }
});

// ---------------------------------------------------------------------
// Live field-enable conditions on entity_form.php ("enable this field
// only if..."). Mirrors includes/entity_engine.php's evaluate_condition()/
// conditions_pass() so the same rules apply client-side (for instant
// feedback) and server-side (as the actual authority on what gets saved).
// ---------------------------------------------------------------------
window.EntityConditions = {
  relatedLookups: {},
  config: null,

  init: function (config) {
    this.config = config || { targets: [], relatedLookups: {} };
    this.relatedLookups = this.config.relatedLookups || {};
    var form = document.querySelector('form[data-conditional-form]');
    if (!form || !this.config.targets || !this.config.targets.length) { return; }
    var self = this;
    form.addEventListener('input', function () { self.evaluateAll(form); });
    form.addEventListener('change', function () { self.evaluateAll(form); });
    self.evaluateAll(form);
  },

  getValue: function (form, cond) {
    if (cond.source_type === 'own_field') {
      var el = form.querySelector('[name="field[' + cond.input_name + ']"]');
      if (!el) { return null; }
      if (el.type === 'checkbox') { return el.checked ? '1' : '0'; }
      return el.value;
    }
    if (cond.source_type === 'own_relationship') {
      var relEl = form.querySelector('[name="fk[' + cond.input_name + ']"]');
      return relEl ? relEl.value : null;
    }
    if (cond.source_type === 'related_field') {
      var sel = form.querySelector('[name="fk[' + cond.input_name + ']"]');
      var relId = sel ? sel.value : '';
      var table = this.relatedLookups[cond.input_name] || {};
      var row = table[relId];
      return row ? row[cond.related_field_name] : null;
    }
    return null;
  },

  passes: function (value, operator, compareValue, fieldType) {
    var isEmpty = (value === null || value === undefined || value === '');
    if (operator === 'is_null') { return isEmpty; }
    if (operator === 'is_not_null') { return !isEmpty; }
    if (isEmpty || compareValue === null || compareValue === undefined || compareValue === '') { return false; }

    if (fieldType === 'Date') {
      var da = Date.parse(value), db = Date.parse(compareValue);
      if (!isNaN(da) && !isNaN(db)) {
        switch (operator) {
          case 'equals': return da === db;
          case 'not_equals': return da !== db;
          case 'greater_than': return da > db;
          case 'greater_or_equal': return da >= db;
          case 'less_than': return da < db;
          case 'less_or_equal': return da <= db;
          case 'contains': return false;
        }
      }
    }

    var numA = parseFloat(value), numB = parseFloat(compareValue);
    var bothNumeric = !isNaN(numA) && !isNaN(numB) && String(value).trim() !== '' && String(compareValue).trim() !== '';
    switch (operator) {
      case 'equals':
        return bothNumeric ? numA === numB : String(value).toLowerCase() === String(compareValue).toLowerCase();
      case 'not_equals':
        return bothNumeric ? numA !== numB : String(value).toLowerCase() !== String(compareValue).toLowerCase();
      case 'greater_than': return bothNumeric && numA > numB;
      case 'greater_or_equal': return bothNumeric && numA >= numB;
      case 'less_than': return bothNumeric && numA < numB;
      case 'less_or_equal': return bothNumeric && numA <= numB;
      case 'contains': return String(value).toLowerCase().indexOf(String(compareValue).toLowerCase()) !== -1;
      default: return false;
    }
  },

  evaluateAll: function (form) {
    var self = this;
    this.config.targets.forEach(function (target) {
      var enabled = target.groups.length === 0 || target.groups.some(function (group) {
        return group.every(function (cond) {
          var val = self.getValue(form, cond);
          return self.passes(val, cond.operator, cond.compare_value, cond.field_type);
        });
      });
      var wrapper = form.querySelector('[data-cond-target="' + target.key + '"]');
      if (!wrapper) { return; }
      wrapper.style.display = enabled ? '' : 'none';
      wrapper.querySelectorAll('input, select, textarea').forEach(function (input) {
        if (enabled) {
          if (input.dataset.wasRequired === '1') { input.required = true; }
        } else if (input.required) {
          input.dataset.wasRequired = '1';
          input.required = false;
        }
      });
    });
  }
};
