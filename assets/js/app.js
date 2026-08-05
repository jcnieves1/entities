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
});
