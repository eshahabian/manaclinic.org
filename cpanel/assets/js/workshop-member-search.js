(function () {
  function init() {
    if (!window.enhanceSearchSelect) return;
    document.querySelectorAll('[data-workshop-member-select]').forEach(function (select) {
      window.enhanceSearchSelect(select, {placeholder: 'جستجو با نام، نام کاربری یا شماره موبایل'});
      var input = select.parentElement.querySelector('.search-select-input');
      if (input) input.setAttribute('aria-label', 'جستجوی عضو با نام، نام کاربری یا شماره موبایل');
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
