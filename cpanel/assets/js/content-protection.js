(function () {
  if (!document.body.hasAttribute('data-content-protected')) return;

  function editable(target) {
    return target instanceof Element && !!target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"]), [data-allow-copy]');
  }
  function block(event) {
    if (!editable(event.target)) event.preventDefault();
  }
  ['copy', 'cut', 'contextmenu', 'selectstart', 'dragstart'].forEach(function (name) {
    document.addEventListener(name, block);
  });
  document.addEventListener('keydown', function (event) {
    if (editable(event.target)) return;
    if ((event.ctrlKey || event.metaKey) && ['c', 'x', 's', 'a'].includes(event.key.toLowerCase())) event.preventDefault();
  });
})();
