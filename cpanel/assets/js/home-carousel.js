(() => {
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  document.querySelectorAll('[data-carousel]').forEach((carousel) => {
    const viewport = carousel.querySelector('.mana-carousel-viewport');
    const track = carousel.querySelector('.mana-carousel-track');
    const toggle = carousel.querySelector('.mana-carousel-toggle');
    const cards = Array.from(track.children);
    const direction = carousel.dataset.carousel === 'left' ? -1 : 1;
    const speed = 32;
    let copies = [];
    let period = 0;
    let frame = 0;
    let previousTime = null;
    let position = 0;
    let paused = false;
    let hovered = false;
    let interacting = false;
    let visible = false;
    let interactionTimer;

    function animate(time) {
      if (previousTime === null) position = -viewport.scrollLeft;
      const elapsed = previousTime === null ? 0 : Math.min(time - previousTime, 50);
      previousTime = time;
      position = (position + direction * speed * elapsed / 1000 + period) % period;
      viewport.scrollLeft = -position;
      frame = requestAnimationFrame(animate);
    }

    function updatePlayback() {
      if (copies.length && visible && !hovered && !interacting && !document.hidden) {
        if (!frame) frame = requestAnimationFrame(animate);
      } else {
        cancelAnimationFrame(frame);
        frame = 0;
        previousTime = null;
      }
    }

    function updateLayout() {
      const first = cards[0].getBoundingClientRect();
      const last = cards[cards.length - 1].getBoundingClientRect();
      const width = first.right - last.left;
      const gap = parseFloat(getComputedStyle(track).columnGap);
      period = width + gap;
      const overflows = cards.length > 1 && width > viewport.clientWidth;
      const enabled = overflows && !reducedMotion.matches;
      const looping = enabled && !paused;

      toggle.hidden = !enabled;
      toggle.textContent = paused ? 'شروع حرکت' : 'توقف حرکت';
      carousel.classList.toggle('is-moving', looping);

      if (looping && !copies.length) {
        copies = cards.map((card) => {
          const copy = card.cloneNode(true);
          copy.setAttribute('aria-hidden', 'true');
          copy.tabIndex = -1;
          return copy;
        });
        track.append(...copies);
      } else if (!looping && copies.length) {
        const offset = -viewport.scrollLeft;
        copies.forEach((copy) => copy.remove());
        copies = [];
        if (offset >= period) viewport.scrollLeft = -(offset % period);
      }
      updatePlayback();
    }

    toggle.addEventListener('click', () => {
      paused = !paused;
      updateLayout();
    });

    viewport.addEventListener('focusin', (event) => {
      if (copies.length && event.target.matches(':focus-visible')) {
        paused = true;
        updateLayout();
      }
    });

    viewport.addEventListener('pointerenter', (event) => {
      if (event.pointerType === 'mouse') {
        hovered = true;
        updatePlayback();
      }
    });
    viewport.addEventListener('pointerleave', () => {
      hovered = false;
      updatePlayback();
    });
    viewport.addEventListener('touchstart', () => {
      clearTimeout(interactionTimer);
      interacting = true;
      updatePlayback();
    }, { passive: true });
    function finishTouch(event) {
      if (event.touches.length) return;
      interactionTimer = setTimeout(() => {
        interacting = false;
        updatePlayback();
      }, 1200);
    }
    viewport.addEventListener('touchend', finishTouch, { passive: true });
    viewport.addEventListener('touchcancel', finishTouch, { passive: true });

    const sizeObserver = new ResizeObserver(updateLayout);
    sizeObserver.observe(viewport);
    const visibilityObserver = new IntersectionObserver(([entry]) => {
      visible = entry.isIntersecting;
      updatePlayback();
    });
    visibilityObserver.observe(viewport);
    document.addEventListener('visibilitychange', updatePlayback);
    reducedMotion.addEventListener('change', updateLayout);
    updateLayout();
  });
})();
