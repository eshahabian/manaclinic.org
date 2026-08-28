"use client";

import { useEffect, useRef } from "react";

type Particle = {
  x: number;
  y: number;
  vx: number;
  vy: number;
  r: number;
};

export function ParticleNetwork() {
  const canvasRef = useRef<HTMLCanvasElement>(null);

  useEffect(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;

    const prefersReduced = window.matchMedia(
      "(prefers-reduced-motion: reduce)"
    ).matches;
    if (prefersReduced) {
      canvas.style.display = "none";
      return;
    }

    const ctx = canvas.getContext("2d");
    if (!ctx) return;

    let particles: Particle[] = [];
    let animId = 0;
    const mouse = { x: null as number | null, y: null as number | null, radius: 160 };

    function makeParticle(): Particle {
      return {
        x: Math.random() * canvas!.width,
        y: Math.random() * canvas!.height,
        vx: (Math.random() - 0.5) * 0.35,
        vy: (Math.random() - 0.5) * 0.35,
        r: Math.random() * 1.6 + 0.7,
      };
    }

    function init() {
      particles = [];
      const n = Math.max(40, Math.floor((canvas!.width * canvas!.height) / 9000));
      for (let i = 0; i < n; i++) particles.push(makeParticle());
    }

    function resize() {
      canvas!.width = window.innerWidth;
      canvas!.height = window.innerHeight;
      init();
    }

    function drawParticle(p: Particle) {
      ctx!.beginPath();
      ctx!.arc(p.x, p.y, p.r, 0, Math.PI * 2);
      ctx!.fillStyle = "rgba(201, 151, 59, 0.65)";
      ctx!.fill();
    }

    function updateParticle(p: Particle) {
      if (p.x > canvas!.width || p.x < 0) p.vx *= -1;
      if (p.y > canvas!.height || p.y < 0) p.vy *= -1;

      if (mouse.x !== null && mouse.y !== null) {
        const dx = mouse.x - p.x;
        const dy = mouse.y - p.y;
        const d = Math.sqrt(dx * dx + dy * dy);
        if (d < mouse.radius && d > 0) {
          const f = (mouse.radius - d) / mouse.radius;
          p.x -= (dx / d) * f * 5;
          p.y -= (dy / d) * f * 5;
        }
      }

      p.x += p.vx;
      p.y += p.vy;
      drawParticle(p);
    }

    function connect() {
      const maxD = (canvas!.width / 7) * (canvas!.height / 7);
      for (let a = 0; a < particles.length; a++) {
        for (let b = a + 1; b < particles.length; b++) {
          const dx = particles[a].x - particles[b].x;
          const dy = particles[a].y - particles[b].y;
          const d = dx * dx + dy * dy;
          if (d >= maxD) continue;

          const op = (1 - d / maxD) * 0.5;
          let near = false;
          if (mouse.x !== null && mouse.y !== null) {
            const mx = particles[a].x - mouse.x;
            const my = particles[a].y - mouse.y;
            if (Math.sqrt(mx * mx + my * my) < mouse.radius) near = true;
          }

          ctx!.strokeStyle = near
            ? `rgba(201, 151, 59, ${op})`
            : `rgba(27, 94, 75, ${op * 0.55})`;
          ctx!.lineWidth = 0.7;
          ctx!.beginPath();
          ctx!.moveTo(particles[a].x, particles[a].y);
          ctx!.lineTo(particles[b].x, particles[b].y);
          ctx!.stroke();
        }
      }
    }

    function animate() {
      animId = requestAnimationFrame(animate);
      ctx!.clearRect(0, 0, canvas!.width, canvas!.height);
      particles.forEach(updateParticle);
      connect();
    }

    function onMove(e: MouseEvent) {
      mouse.x = e.clientX;
      mouse.y = e.clientY;
    }

    function onLeave() {
      mouse.x = null;
      mouse.y = null;
    }

    function onResize() {
      cancelAnimationFrame(animId);
      resize();
      animate();
    }

    function onVisibility() {
      if (document.hidden) {
        cancelAnimationFrame(animId);
      } else {
        animate();
      }
    }

    resize();
    animate();

    window.addEventListener("mousemove", onMove);
    window.addEventListener("mouseleave", onLeave);
    window.addEventListener("resize", onResize);
    document.addEventListener("visibilitychange", onVisibility);

    return () => {
      cancelAnimationFrame(animId);
      window.removeEventListener("mousemove", onMove);
      window.removeEventListener("mouseleave", onLeave);
      window.removeEventListener("resize", onResize);
      document.removeEventListener("visibilitychange", onVisibility);
    };
  }, []);

  return (
    <canvas
      ref={canvasRef}
      id="particle-canvas"
      aria-hidden="true"
    />
  );
}
