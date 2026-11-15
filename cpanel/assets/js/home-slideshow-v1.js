(() => {
  const root = document.querySelector('.mana-home-slider');
  if (!root) return;
  const slides = [...root.querySelectorAll('[data-home-slide]')];
  const buttons = [...root.querySelectorAll('[data-home-slide-to]')];
  const pause = root.querySelector('[data-home-slide-pause]');
  const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
  let current = 0, paused = motion.matches, timer;
  const show = index => {
    current = index;
    slides.forEach((slide, i) => { slide.hidden = i !== index; });
    buttons.forEach((button, i) => button.setAttribute('aria-pressed', String(i === index)));
  };
  const schedule = () => {
    clearInterval(timer);
    pause.textContent = paused ? 'پخش' : 'توقف';
    pause.setAttribute('aria-label', paused ? 'شروع پخش خودکار' : 'توقف پخش خودکار');
    if (!paused && !document.hidden) timer = setInterval(() => show((current + 1) % slides.length), 7000);
  };
  buttons.forEach((button, i) => button.addEventListener('click', () => { show(i); paused = true; schedule(); }));
  pause.addEventListener('click', () => { paused = !paused; schedule(); });
  root.addEventListener('focusin', event => { if (event.target !== pause) { paused = true; schedule(); } });
  root.addEventListener('mouseenter', () => clearInterval(timer));
  root.addEventListener('mouseleave', schedule);
  document.addEventListener('visibilitychange', schedule);
  motion.addEventListener('change', () => { paused = motion.matches; schedule(); });
  schedule();
})();
