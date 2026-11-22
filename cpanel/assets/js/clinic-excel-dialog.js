(() => {
  const dialog = document.getElementById('clinic-excel-dialog');
  if (!dialog || typeof dialog.showModal !== 'function') return;

  const frame = dialog.querySelector('iframe');
  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-excel-report]');
    if (!trigger || event.defaultPrevented || event.button !== 0
        || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

    event.preventDefault();
    const contextualLink = trigger.dataset.excelReport === 'menu'
      ? document.querySelector('[data-excel-report="context"]')
      : null;
    const reportUrl = new URL((contextualLink || trigger).href);
    reportUrl.searchParams.set('embed', '1');
    frame.src = reportUrl.href;
    dialog.showModal();
  });

  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
  dialog.addEventListener('close', () => {
    if (!dialog.open) frame.removeAttribute('src');
  });
  frame.addEventListener('load', () => {
    frame.contentDocument?.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || !dialog.open) return;

      event.preventDefault();
      const calendar = frame.contentDocument.querySelector('jdp-container');
      if (calendar?.style.visibility === 'visible') {
        frame.contentWindow.jalaliDatepicker.hide();
        return;
      }
      dialog.close();
    });
  });
})();
