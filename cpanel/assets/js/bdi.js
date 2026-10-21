(() => {
  'use strict';
  const scoreForm = document.querySelector('[data-bdi-score]');
  if (scoreForm) {
    const item9 = scoreForm.querySelector('[name="answers[9]"]');
    const item9Choices = scoreForm.querySelectorAll('[name="choices[9]"]');
    const safety = scoreForm.querySelector('[data-bdi-safety]');
    const updateSafety = () => {
      const choice = scoreForm.querySelector('[name="choices[9]"]:checked');
      const value = item9 ? item9.value : choice?.dataset.score;
      safety.hidden = !['1', '2', '3'].includes(value);
    };
    item9?.addEventListener('change', updateSafety);
    item9Choices.forEach((input) => input.addEventListener('change', updateSafety));
    updateSafety();
    if (scoreForm.hasAttribute('data-bdi-questionnaire')) {
      const items = [...scoreForm.querySelectorAll('[data-bdi-item]')];
      const progress = scoreForm.querySelector('[data-bdi-progress]');
      const navigation = scoreForm.querySelector('[data-bdi-navigation]');
      const prev = scoreForm.querySelector('[data-bdi-prev]');
      const next = scoreForm.querySelector('[data-bdi-next]');
      const finish = scoreForm.querySelector('[data-bdi-finish]');
      const error = scoreForm.querySelector('[data-bdi-answer-error]');
      const fa = (number) => new Intl.NumberFormat('fa-IR').format(number);
      let step = 0;
      const answered = (item) => !!item.querySelector('input:checked');
      const updateProgress = () => {
        const count = items.filter(answered).length;
        progress.querySelector('progress').value = count;
        scoreForm.querySelector('[data-bdi-answered]').textContent = `${fa(count)} پاسخ ثبت‌شده در فرم`;
      };
      const showStep = (index, focus = false) => {
        step = index;
        items.forEach((item, i) => { item.hidden = i !== index; });
        prev.disabled = index === 0;
        next.hidden = index === items.length - 1;
        finish.hidden = index !== items.length - 1;
        scoreForm.querySelector('[data-bdi-step-label]').textContent = `سؤال ${fa(index + 1)} از ${fa(items.length)}`;
        error.hidden = true;
        if (focus) {
          items[index].querySelector('legend').tabIndex = -1;
          items[index].querySelector('legend').focus();
        }
        updateProgress();
      };
      progress.hidden = false;
      navigation.hidden = false;
      scoreForm.noValidate = true;
      showStep(0);
      scoreForm.addEventListener('change', updateProgress);
      prev.addEventListener('click', () => { if (step > 0) showStep(step - 1, true); });
      next.addEventListener('click', () => {
        if (!answered(items[step])) {
          error.hidden = false;
          items[step].querySelector('input').focus();
          return;
        }
        if (step < items.length - 1) showStep(step + 1, true);
      });
      scoreForm.addEventListener('submit', (event) => {
        const missing = items.findIndex((item) => !answered(item));
        if (missing !== -1) {
          event.preventDefault(); showStep(missing, true); error.hidden = false;
          return;
        }
        showStep(items.length - 1);
        if (!scoreForm.reportValidity()) event.preventDefault();
      });
    }
  }
  document.querySelector('[data-bdi-print]')?.addEventListener('click', () => window.print());
  const aiForm = document.querySelector('[data-bdi-ai]');
  if (!aiForm) return;
  const status = document.querySelector('[data-bdi-ai-status]');
  const output = document.querySelector('[data-bdi-ai-output]');
  const addText = (tag, text, parent = output) => {
    const node = document.createElement(tag);
    node.textContent = text;
    parent.appendChild(node);
    return node;
  };
  aiForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (!aiForm.reportValidity()) return;
    const button = aiForm.querySelector('button');
    if (button.disabled) return;
    button.disabled = true;
    status.textContent = 'همراه هوشمند در حال آماده‌کردن توضیح پاسخ‌های شماست…';
    output.replaceChildren();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 75000);
    try {
      const response = await fetch(aiForm.action, {
        method: 'POST', body: new FormData(aiForm), credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }, signal: controller.signal,
      });
      const data = await response.json();
      if (data.safety) {
        const alert = addText('p', data.safety); alert.className = 'bdi-safety';
        status.textContent = 'برای این پاسخنامه پیگیری انسانی در اولویت است.';
        return;
      }
      if (!response.ok || !data.analysis) throw new Error(data.error || 'تحلیل فعلاً در دسترس نیست.');
      addText('p', data.analysis.summary);
      [['observations', 'برداشت از پاسخ‌ها'], ['next_steps', 'قدم‌های پیشنهادی']].forEach(([key, title]) => {
        addText('h4', title);
        const list = addText('ul', '');
        data.analysis[key].forEach((line) => addText('li', line, list));
      });
      status.textContent = 'توضیح تولیدشده با هوش مصنوعی؛ نیازمند قضاوت و بررسی متخصص.';
      button.textContent = 'دریافت دوباره تحلیل';
    } catch (error) {
      status.textContent = error.name === 'AbortError' ? 'پاسخ سرویس طول کشید. امتیاز و نمودار آماده‌اند؛ کمی بعد دوباره تلاش کنید.' : (error instanceof SyntaxError ? 'نشست ورود یا ارتباط با سرویس قطع شده است؛ صفحه را تازه کنید و دوباره تلاش کنید.' : error.message);
    } finally {
      clearTimeout(timeout); button.disabled = false;
    }
  });
})();
