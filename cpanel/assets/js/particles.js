(function () {
  var canvas = document.getElementById('particle-canvas');
  if (!canvas) return;
  if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    canvas.style.display = 'none';
    return;
  }
  var ctx = canvas.getContext('2d');
  var particles = [];
  var animId = 0;
  var mouse = { x: null, y: null, radius: 160 };

  function Particle() {
    this.x = Math.random() * canvas.width;
    this.y = Math.random() * canvas.height;
    this.vx = (Math.random() - 0.5) * 0.35;
    this.vy = (Math.random() - 0.5) * 0.35;
    this.r = Math.random() * 1.6 + 0.7;
  }
  Particle.prototype.draw = function () {
    ctx.beginPath();
    ctx.arc(this.x, this.y, this.r, 0, Math.PI * 2);
    ctx.fillStyle = 'rgba(201, 151, 59, 0.65)';
    ctx.fill();
  };
  Particle.prototype.update = function () {
    if (this.x > canvas.width || this.x < 0) this.vx *= -1;
    if (this.y > canvas.height || this.y < 0) this.vy *= -1;
    if (mouse.x !== null) {
      var dx = mouse.x - this.x;
      var dy = mouse.y - this.y;
      var d = Math.sqrt(dx * dx + dy * dy);
      if (d < mouse.radius && d > 0) {
        var f = (mouse.radius - d) / mouse.radius;
        this.x -= (dx / d) * f * 5;
        this.y -= (dy / d) * f * 5;
      }
    }
    this.x += this.vx;
    this.y += this.vy;
    this.draw();
  };

  function connect() {
    var maxD = (canvas.width / 7) * (canvas.height / 7);
    for (var a = 0; a < particles.length; a++) {
      for (var b = a + 1; b < particles.length; b++) {
        var dx = particles[a].x - particles[b].x;
        var dy = particles[a].y - particles[b].y;
        var d = dx * dx + dy * dy;
        if (d < maxD) {
          var op = (1 - d / maxD) * 0.5;
          var near = false;
          if (mouse.x !== null) {
            var mx = particles[a].x - mouse.x;
            var my = particles[a].y - mouse.y;
            if (Math.sqrt(mx * mx + my * my) < mouse.radius) near = true;
          }
          ctx.strokeStyle = near
            ? 'rgba(201, 151, 59,' + op + ')'
            : 'rgba(27, 94, 75,' + (op * 0.55) + ')';
          ctx.lineWidth = 0.7;
          ctx.beginPath();
          ctx.moveTo(particles[a].x, particles[a].y);
          ctx.lineTo(particles[b].x, particles[b].y);
          ctx.stroke();
        }
      }
    }
  }

  function init() {
    particles = [];
    var n = Math.max(40, Math.floor((canvas.width * canvas.height) / 9000));
    for (var i = 0; i < n; i++) particles.push(new Particle());
  }
  function resize() {
    canvas.width = window.innerWidth;
    canvas.height = window.innerHeight;
    init();
  }
  function animate() {
    animId = requestAnimationFrame(animate);
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    particles.forEach(function (p) { p.update(); });
    connect();
  }

  window.addEventListener('mousemove', function (e) {
    mouse.x = e.clientX;
    mouse.y = e.clientY;
  });
  window.addEventListener('mouseleave', function () {
    mouse.x = null;
    mouse.y = null;
  });
  window.addEventListener('resize', function () {
    cancelAnimationFrame(animId);
    resize();
    animate();
  });
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) cancelAnimationFrame(animId);
    else animate();
  });

  resize();
  animate();
})();
