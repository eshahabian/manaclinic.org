<?php
declare(strict_types=1);

/**
 * پنل روزهای حضور یک درمانگر.
 * انتخاب هر روز موقتاً نگه داشته می‌شود؛ با «ذخیره تغییرات» همه با هم ثبت می‌شوند.
 *
 * @param array{post_url?:string,include_doctor_id?:bool,show_summary?:bool,patient_base?:string} $opts
 */
function therapist_weekday_panel_html(PDO $pdo, string $doctorId, string $doctorName, array $opts = []): string
{
    if (!function_exists('doctor_availability_resync_weekly')) {
        require_once __DIR__ . '/availability.php';
    }
    ensure_availability_schema($pdo);
    ensure_doctor_weekly_hours_schema($pdo);
    if ($doctorId !== '' && function_exists('doctor_availability_resync_weekly')) {
        doctor_availability_resync_weekly($pdo, $doctorId, 12);
    }
    $presenceHours = appointment_presence_hours();
    $weekdays = doctor_weekdays_sat_first();
    $weeklyMap = $doctorId !== '' ? doctor_weekly_hours_map($pdo, $doctorId) : [];
    $weekdaySummary = $doctorId !== '' ? doctor_weekday_presence_summary($pdo, $doctorId) : [];
    $summaryByWeekday = [];
    foreach ($weekdaySummary as $row) {
        $summaryByWeekday[(int) ($row['weekday'] ?? -1)] = $row;
    }
    $postUrl = (string) ($opts['post_url'] ?? '/secretary/therapist-days');
    $includeDoctorId = !array_key_exists('include_doctor_id', $opts) || !empty($opts['include_doctor_id']);
    $showSummary = !array_key_exists('show_summary', $opts) || !empty($opts['show_summary']);
    $patientBase = (string) ($opts['patient_base'] ?? '/secretary/patients');

    $clampPresenceHour = static function (int $hour) use ($presenceHours): int {
        if (in_array($hour, $presenceHours, true)) {
            return $hour;
        }

        return $presenceHours[0];
    };

    $initialDraft = [];
    foreach ($weekdays as $w => $_label) {
        $saved = $weeklyMap[$w] ?? [];
        if ($saved === []) {
            continue;
        }
        $initialDraft[(string) (int) $w] = [
            'from' => $clampPresenceHour((int) $saved[0]),
            'to' => $clampPresenceHour((int) $saved[count($saved) - 1]),
            'clear' => 0,
        ];
    }

    ob_start();
    ?>
    <p class="muted" style="margin:.2rem 0 .8rem;line-height:1.7">
      روز را بزنید و ساعت شروع و پایان را انتخاب کنید؛ همان‌جا موقتاً نگه داشته می‌شود تا روز بعدی را هم تنظیم کنید.
      در پایان «ذخیره تغییرات» را بزنید. اسلات‌ها یک‌ساعته‌اند و گرفتن نوبت تا دو هفته آینده باز است.
    </p>
    <form method="post" action="<?= e(url($postUrl)) ?>" class="avail-week-draft-form" data-avail-draft data-doctor-id="<?= e($doctorId) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_week">
      <?php if ($includeDoctorId): ?>
        <input type="hidden" name="doctor_id" value="<?= e($doctorId) ?>">
      <?php endif; ?>
      <input type="hidden" name="draft_json" id="avail-draft-json" value="<?= e(json_encode($initialDraft, JSON_UNESCAPED_UNICODE)) ?>">

      <div class="avail-weekday-row" role="tablist" aria-label="روزهای هفته">
        <?php foreach ($weekdays as $w => $label): ?>
          <?php $has = isset($weeklyMap[$w]); ?>
          <button type="button" class="avail-weekday-btn<?= $has ? ' has-hours' : '' ?>" data-weekday-open="<?= (int) $w ?>">
            <?= e($label) ?>
            <span class="avail-weekday-dot" data-draft-dot="<?= (int) $w ?>"<?= $has ? '' : ' hidden' ?> aria-hidden="true"></span>
          </button>
        <?php endforeach; ?>
      </div>

      <?php foreach ($weekdays as $w => $label): ?>
        <?php
          $saved = $weeklyMap[$w] ?? [];
          $defaultFrom = $clampPresenceHour((int) ($saved[0] ?? 9));
          $defaultTo = $clampPresenceHour((int) ($saved !== [] ? $saved[count($saved) - 1] : 13));
          if ($defaultTo < $defaultFrom) {
              $defaultTo = $defaultFrom;
          }
        ?>
        <div class="avail-weekday-editor" data-weekday-panel="<?= (int) $w ?>" hidden>
          <div class="avail-weekday-editor-head">
            <strong>ساعت حضور — <?= e($label) ?></strong>
            <span class="muted" style="font-size:.85rem" data-draft-status="<?= (int) $w ?>">
              <?php if ($saved !== []): ?>
                الان: <?= e(implode(' · ', array_map(static fn (int $h): string => appointment_hour_chip_label($h), $saved))) ?>
              <?php else: ?>
                هنوز ساعتی برای این روز انتخاب نشده است.
              <?php endif; ?>
            </span>
          </div>
          <div data-draft-day="<?= (int) $w ?>" data-day-label="<?= e($label) ?>">
            <?= appointment_hour_range_fields_html($presenceHours, (int) $defaultFrom, (int) $defaultTo, 'hour_from_' . (int) $w, 'hour_to_' . (int) $w, 'th-w' . (int) $w) ?>
            <div style="margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
              <button type="button" class="btn btn-outline btn-sm" data-draft-keep="<?= (int) $w ?>">نگه داشتن این روز</button>
              <button type="button" class="btn btn-outline btn-sm" data-draft-clear="<?= (int) $w ?>">پاک کردن این روز</button>
            </div>
            <p class="muted" style="margin:.55rem 0 0;font-size:.8rem;line-height:1.65" data-draft-hint="<?= (int) $w ?>">با زدن ساعت‌ها، این روز موقتاً ذخیره می‌شود. می‌توانید روز دیگری را هم تنظیم کنید.</p>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="avail-weekday-fields" style="margin-top:1rem">
        <label>
          <span class="label">اعمال برای</span>
          <select class="input avail-native-select" name="weeks">
            <option value="4">۴ هفته آینده</option>
            <option value="8">۸ هفته آینده</option>
            <option value="12" selected>۱۲ هفته آینده</option>
            <option value="26">۶ ماه آینده</option>
          </select>
        </label>
      </div>
      <p class="muted" style="margin:.65rem 0 0;font-size:.85rem;line-height:1.7" data-draft-summary>هنوز تغییر موقتی ندارید.</p>
      <div style="margin-top:.85rem;display:flex;flex-wrap:wrap;gap:.5rem">
        <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
      </div>
    </form>

    <?php if ($showSummary): ?>
    <section class="avail-summary-panel" id="avail-summary-panel" hidden style="margin-top:1rem">
      <h3 style="font-size:1rem;margin:0 0 .6rem">خلاصه حضور ذخیره‌شده</h3>
      <?php foreach ($weekdays as $w => $label): ?>
        <?php
          $row = $summaryByWeekday[(int) $w] ?? null;
          $hours = is_array($row) ? ($row['hours'] ?? []) : [];
          $chips = is_array($row) && is_array($row['chips'] ?? null) ? $row['chips'] : [];
        ?>
        <div data-summary-weekday="<?= (int) $w ?>" hidden>
          <?php if ($hours === []): ?>
            <p class="muted" style="margin:0">برای «<?= e($label) ?>» هنوز ساعت حضوری ثبت نشده است.</p>
          <?php else: ?>
            <div class="avail-summary-hours">
              <?php foreach ($chips as $chip): ?>
                <?php
                  if (!is_array($chip)) {
                      continue;
                  }
                  $who = trim((string) ($chip['patient'] ?? ''));
                  $patientId = trim((string) ($chip['patient_id'] ?? ''));
                  $face = (string) ($chip['label'] ?? '');
                  $isTaken = !empty($chip['taken']);
                  $text = $who !== '' ? ($face . ' · ' . $who) : $face;
                ?>
                <?php if ($patientId !== ''): ?>
                  <a class="avail-hour-pill is-booked" href="<?= e(url(rtrim($patientBase, '/') . '/' . $patientId)) ?>"><?= e($text) ?></a>
                <?php else: ?>
                  <span class="avail-hour-pill<?= $isTaken ? ' is-booked' : '' ?>"><?= e($text) ?></span>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>

    <?= appointment_hour_range_script() ?>
    <script>
    (function(){
      var form = document.querySelector("[data-avail-draft]");
      if (!form || form.getAttribute("data-draft-inited") === "1") return;
      form.setAttribute("data-draft-inited", "1");
      var draftInput = document.getElementById("avail-draft-json");
      var summaryEl = form.querySelector("[data-draft-summary]");
      var draft = {};
      try { draft = JSON.parse((draftInput && draftInput.value) || "{}") || {}; } catch (e) { draft = {}; }
      var labels = {};
      form.querySelectorAll("[data-draft-day]").forEach(function(box){
        labels[box.getAttribute("data-draft-day")] = box.getAttribute("data-day-label") || "";
      });
      function hourLabel(h){
        h = parseInt(h, 10);
        if (h === 0) return "۱۲ شب";
        if (h === 12) return "۱۲ ظهر";
        var n = String(h).replace(/\d/g, function(d){ return "۰۱۲۳۴۵۶۷۸۹"[d]; });
        if (h < 12) return n + " صبح";
        if (h < 17) return n + " بعدازظهر";
        if (h < 20) return n + " عصر";
        return n + " شب";
      }
      function writeDraft(){
        if (draftInput) draftInput.value = JSON.stringify(draft);
        var parts = [];
        Object.keys(draft).forEach(function(key){
          var item = draft[key];
          if (!item) return;
          var name = labels[key] || ("روز " + key);
          if (item.clear) {
            parts.push(name + ": پاک شود");
            return;
          }
          parts.push(name + ": از " + hourLabel(item.from) + " تا " + hourLabel(item.to));
        });
        if (summaryEl) {
          summaryEl.textContent = parts.length
            ? ("آماده ذخیره: " + parts.join(" · "))
            : "هنوز تغییر موقتی ندارید.";
        }
        form.querySelectorAll("[data-draft-dot]").forEach(function(dot){
          var key = String(dot.getAttribute("data-draft-dot") || "");
          var item = draft[key];
          var on = !!(item && !item.clear);
          var btn = form.querySelector("[data-weekday-open=\"" + key + "\"]");
          if (on) {
            dot.hidden = false;
            if (btn) btn.classList.add("has-hours", "is-drafted");
          } else if (item && item.clear) {
            dot.hidden = true;
            if (btn) {
              btn.classList.remove("has-hours");
              btn.classList.add("is-drafted");
            }
          } else {
            if (btn) btn.classList.remove("is-drafted");
          }
        });
        form.querySelectorAll("[data-draft-status]").forEach(function(el){
          var key = String(el.getAttribute("data-draft-status") || "");
          var item = draft[key];
          if (!item) return;
          if (item.clear) {
            el.textContent = "برای ذخیره: این روز پاک می‌شود.";
            return;
          }
          el.textContent = "موقت: از " + hourLabel(item.from) + " تا " + hourLabel(item.to);
        });
      }
      function readDayHours(day){
        var box = form.querySelector("[data-draft-day=\"" + day + "\"]");
        if (!box) return null;
        var fromEl = box.querySelector("input[name=hour_from_" + day + "]") || box.querySelector("input[type=hidden]");
        var inputs = box.querySelectorAll("input[type=hidden]");
        var toEl = box.querySelector("input[name=hour_to_" + day + "]") || inputs[1];
        if (!fromEl || !toEl) return null;
        return { from: parseInt(fromEl.value, 10), to: parseInt(toEl.value, 10) };
      }
      function keepDay(day){
        var hours = readDayHours(day);
        if (!hours || isNaN(hours.from) || isNaN(hours.to)) return;
        draft[String(day)] = { from: hours.from, to: hours.to, clear: 0 };
        writeDraft();
        var hint = form.querySelector("[data-draft-hint=\"" + day + "\"]");
        if (hint) hint.textContent = "این روز موقتاً نگه داشته شد. روز دیگری را انتخاب کنید یا ذخیره تغییرات را بزنید.";
      }
      function clearDay(day){
        draft[String(day)] = { from: 0, to: 0, clear: 1 };
        writeDraft();
        var hint = form.querySelector("[data-draft-hint=\"" + day + "\"]");
        if (hint) hint.textContent = "پاک شدن این روز موقتاً ثبت شد. با ذخیره تغییرات اعمال می‌شود.";
      }
      form.querySelectorAll("[data-draft-day]").forEach(function(box){
        var day = box.getAttribute("data-draft-day");
        box.addEventListener("click", function(e){
          var chip = e.target && e.target.closest ? e.target.closest("[data-hour]") : null;
          if (!chip || !box.contains(chip)) return;
          window.setTimeout(function(){ keepDay(day); }, 0);
        });
      });
      form.querySelectorAll("[data-draft-keep]").forEach(function(btn){
        btn.addEventListener("click", function(){ keepDay(btn.getAttribute("data-draft-keep")); });
      });
      form.querySelectorAll("[data-draft-clear]").forEach(function(btn){
        btn.addEventListener("click", function(){ clearDay(btn.getAttribute("data-draft-clear")); });
      });
      form.addEventListener("submit", function(e){
        writeDraft();
        if (!Object.keys(draft).length) {
          e.preventDefault();
          if (summaryEl) summaryEl.textContent = "اول حداقل یک روز را تنظیم کنید، بعد ذخیره تغییرات را بزنید.";
        }
      });
      writeDraft();
    })();
    </script>
    <?php

    return (string) ob_get_clean();
}
