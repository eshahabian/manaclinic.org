(function () {
  function init() {
    if (!window.enhanceSearchSelect) return;
    document.querySelectorAll('[data-workshop-member-select]').forEach(function (select) {
      window.enhanceSearchSelect(select, {placeholder: 'جستجو با نام، نام کاربری یا شماره موبایل'});
      var input = select.parentElement.querySelector('.search-select-input');
      if (input) input.setAttribute('aria-label', 'جستجوی عضو با نام، نام کاربری یا شماره موبایل');
    });
    document.querySelectorAll('[data-workshop-create-form]').forEach(function (form) {
      var fields = form.elements;
      if (window.bindNameTransliteration) {
        window.bindNameTransliteration({firstName:fields.new_first_name, lastName:fields.new_last_name,
          nameEn:fields.new_name_en, surname:fields.new_surname, username:fields.new_username,
          transliterateUrl:form.dataset.transliterateUrl});
      }
      form.addEventListener('submit', async function (event) {
        event.preventDefault();
        var button = form.querySelector('button[type="submit"]');
        if (button.disabled) return;
        var status = form.querySelector('[data-workshop-create-status]');
        button.disabled = true; status.textContent = 'در حال ساخت پروفایل…';
        try {
          var response = await fetch(form.getAttribute('action'), {method:'POST', body:new FormData(form), credentials:'same-origin', headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
          if (!response.headers.get('content-type') || response.headers.get('content-type').indexOf('application/json') === -1) throw new Error('نشست شما منقضی شده یا ارتباط برقرار نیست؛ صفحه را تازه کنید.');
          var result = await response.json();
          if (!response.ok || !result.ok) throw new Error(result.error || 'ساخت پروفایل انجام نشد.');
          var label = result.name + ' — ' + result.username + ' — ' + result.phone;
          document.querySelectorAll('[data-workshop-member-select], #enroll_patient_id').forEach(function (select) {
            var exists = Array.from(select.options).some(function (opt) { return opt.value === result.id; });
            if (!exists) {
              var option = new Option(label, result.id);
              var search = label;
              if (/^09[0-9]{9}$/.test(result.phone)) search += ' +98' + result.phone.slice(1) + ' 0098' + result.phone.slice(1);
              option.dataset.search = search; select.add(option);
            }
          });
          var target = document.getElementById(form.dataset.workshopSelect);
          target.value = result.id; target.dispatchEvent(new Event('change', {bubbles:true}));
          form.reset();
          status.textContent = 'پروفایل «' + result.name + '» با نام کاربری «' + result.username + '» ساخته و انتخاب شد؛ اکنون «اضافه کردن عضو» را بزنید.';
          form.closest('details').open = false;
          var memberForm = target.closest('form');
          var feedback = memberForm.querySelector('[data-workshop-profile-created]');
          if (!feedback) { feedback = document.createElement('p'); feedback.setAttribute('data-workshop-profile-created',''); feedback.setAttribute('role','status'); memberForm.appendChild(feedback); }
          feedback.textContent = status.textContent;
          memberForm.querySelector('button[type="submit"]').focus();
        } catch (error) { status.textContent = error.message || 'ارتباط برقرار نشد؛ دوباره تلاش کنید.'; }
        finally { button.disabled = false; }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
