(() => {
  'use strict';
  const scoreForm = document.querySelector('[data-bdi-score]');
  if (scoreForm) {
    const item9 = scoreForm.querySelector('[name="answers[9]"]');
    const safety = scoreForm.querySelector('[data-bdi-safety]');
    const updateSafety = () => { safety.hidden = !['1', '2', '3'].includes(item9.value); };
    item9.addEventListener('change', updateSafety);
    updateSafety();
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
