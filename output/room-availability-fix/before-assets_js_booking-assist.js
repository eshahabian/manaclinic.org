(function () {
  var form = document.getElementById('secretary-book-form');
  if (!form) return;
  var patient = form.querySelector('#patient_id');
  var therapist = form.querySelector('#doctor_id');
  var clear = form.querySelector('#sec-doctor-clear');
  var room = form.querySelector('#sec-room');
  var date = form.querySelector('#sec-date');
  var box = form.querySelector('#sec-room-availability');
  var status = box && box.querySelector('[data-room-status]');
  var list = box && box.querySelector('[data-room-segments]');
  var requestId = 0;
  function setTherapist(value) {
    if (therapist.value === value) return;
    therapist.value = value;
    therapist.dispatchEvent(new Event('change', { bubbles: true }));
  }
  function suggestTherapist() {
    var option = patient.options[patient.selectedIndex];
    var suggested = option && option.value ? option.getAttribute('data-doctor') || '' : '';
    var available = Array.prototype.some.call(therapist.options, function (item) { return item.value === suggested; });
    setTherapist(available ? suggested : '');
  }
  if (patient && therapist) {
    patient.addEventListener('change', suggestTherapist);
    if (patient.value) suggestTherapist();
  }
  if (clear && therapist) {
    clear.addEventListener('click', function () {
      setTherapist('');
      var wrap = therapist.closest('.search-select');
      var input = wrap && wrap.querySelector('.search-select-input');
      (input || therapist).focus();
    });
    var wrap = therapist.closest('.search-select');
    var input = wrap && wrap.querySelector('.search-select-input');
    if (input) input.setAttribute('aria-label', 'درمانگر');
  }
  function fa(value) { return String(value).replace(/[0-9]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[Number(d)]; }); }
  function refreshRoom() {
    var id = ++requestId;
    if (!status || !list || !room || !date) return;
    list.replaceChildren();
    if (room.value === '4') { status.textContent = 'خارج از کلینیک به رزرو اتاق نیاز ندارد.'; return; }
    if (!date.value || !room.value) { status.textContent = 'ابتدا تاریخ و اتاق را انتخاب کنید.'; return; }
    status.textContent = 'در حال دریافت ساعت‌های اتاق…';
    var target = new URL('api/room-availability', document.baseURI);
    // Resolve relative to the booking form's application prefix, including subdirectory installs.
    var action = new URL(form.action, document.baseURI);
    target.pathname = action.pathname.replace(/\/(?:app\/appointments|secretary\/book).*$/, '/api/room-availability');
    target.search = new URLSearchParams({ date: date.value, room: room.value }).toString();
    fetch(target.href, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (response) { if (!response.ok) throw new Error('availability'); return response.json(); })
      .then(function (data) {
        if (id !== requestId) return;
        if (!Array.isArray(data.segments)) throw new Error('availability');
        var view = form.querySelector('#sec-date-view');
        status.textContent = 'اتاق ' + fa(room.value) + ' · ' + (view && view.value ? view.value : fa(date.value));
        data.segments.forEach(function (segment) {
          var item = document.createElement('div');
          item.className = 'sapp-row';
          item.style.cssText = 'padding:.6rem .8rem;border:1px solid;border-radius:.7rem;background:' + (segment.busy ? '#fff1f1' : '#edf8f3') + ';color:' + (segment.busy ? '#9c3333' : '#176446');
          item.textContent = fa(segment.start) + ' تا ' + fa(segment.end) + ' · ' + (segment.busy ? 'پر' : 'خالی');
          list.appendChild(item);
        });
      })
      .catch(function () { if (id === requestId) status.textContent = 'دریافت ساعت‌های اتاق انجام نشد؛ دوباره اتاق را انتخاب کنید.'; });
  }
  window.manaBookingRoomRefresh = refreshRoom;
  if (room) room.addEventListener('change', refreshRoom);
  if (date) date.addEventListener('change', refreshRoom);
  refreshRoom();
})();
