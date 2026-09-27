<?php
declare(strict_types=1);

/** @var array $workshopList */
/** @var string $workshopEmpty */
/** @var string $workshopRole doctor|secretary */

$workshopList = $workshopList ?? [];
$workshopEmpty = $workshopEmpty ?? 'کارگاهی در این بخش نیست.';
$workshopRole = $workshopRole ?? 'doctor';
$workshopEnrollmentsById = $workshopEnrollmentsById ?? [];
$workshopEditBase = $workshopRole === 'secretary' ? '/secretary/workshops' : '/doctor/workshops';
$workshopPostBase = $workshopEditBase;
$workshopMediaPost = $workshopRole === 'secretary' ? '/secretary/workshop-media' : '/doctor/workshop-media';
$openDoctorPathId = $openDoctorPathId ?? '';
$doctorPathBoardById = $doctorPathBoardById ?? [];
?>
<?php if (!$workshopList): ?>
  <p class="muted binder-empty"><?= e($workshopEmpty) ?></p>
<?php else: ?>
  <div class="stack">
    <?php foreach ($workshopList as $workshop): ?>
      <?php $archived = workshop_is_archived($workshop); ?>
      <?php
        $staffSessions = $sessionsByWorkshop[(string) $workshop['id']] ?? [];
        $staffOverview = function_exists('workshop_overview_payload')
          ? workshop_overview_payload($workshop, ['status' => 'CONFIRMED'], $staffSessions, true, null)
          : [];
        $staffOverview['staff'] = true;
        $staffOverview['editUrl'] = url($workshopEditBase . '?edit=' . rawurlencode((string) $workshop['id']) . '&tab=new');
        $people = function_exists('workshop_overview_people_lists')
          ? workshop_overview_people_lists($workshopEnrollmentsById[(string) $workshop['id']] ?? [])
          : ['approved' => [], 'pending' => []];
        $staffOverview['approvedPeople'] = $people['approved'];
        $staffOverview['pendingPeople'] = $people['pending'];
      ?>
      <article class="workshop-binder-card<?= $archived ? ' is-archived' : '' ?>" id="workshop-<?= e($workshop['id']) ?>">
        <div class="workshop-card-row">
          <div class="workshop-card-main" data-workshop-open role="button" tabindex="0">
            <?= function_exists('workshop_overview_data_script') ? workshop_overview_data_script($staffOverview) : '' ?>
            <strong><?= e($workshop['title']) ?></strong>
            <span class="badge" style="margin-right:.5rem"><?= e(workshop_type_label($workshop['type'])) ?></span>
            <?php if ($workshop['type'] !== 'OFFLINE'): ?>
              <span class="badge" style="margin-right:.35rem"><?= e(workshop_session_interval_label((string) ($workshop['session_interval'] ?? 'DAILY'))) ?></span>
            <?php endif; ?>
            <?php if ($workshopRole === 'secretary' && !empty($workshop['doctor_name'])): ?>
              <div class="muted" style="font-size:.85rem;margin-top:.35rem">درمانگر: <?= e($workshop['doctor_name']) ?></div>
            <?php endif; ?>
            <?= staff_sign_html(['name' => $workshop['created_by_name'] ?? '', 'username' => $workshop['created_by_username'] ?? '']) ?>
            <?php $workshopMediaStats = workshop_media_counts_html(workshop_media_counts_from_row($workshop)); if ($workshopMediaStats): ?>
              <div style="margin-top:.4rem"><?= $workshopMediaStats ?></div>
            <?php endif; ?>
            <div class="muted" style="font-size:.85rem;margin-top:.35rem">
              <?php if ($workshop['type'] === 'OFFLINE'): ?>
                دوره آفلاین
              <?php else: ?>
                <?= e(format_workshop_datetime_fa($workshop['starts_at'])) ?> — <?= e(format_workshop_datetime_fa($workshop['ends_at'])) ?>
              <?php endif; ?>
            </div>
            <div class="muted" style="font-size:.85rem;margin-top:.25rem">
              <?= e(format_price((int)$workshop['price'])) ?>
              · ثبت‌نام: <?= (int)$workshop['enrolled_count'] ?><?= !empty($workshop['capacity']) ? ' / ' . (int)$workshop['capacity'] : '' ?>
              · <?= $workshop['is_published'] ? 'منتشر شده' : 'پیش‌نویس' ?>
              · <?= !empty($workshop['enrollment_open']) ? 'ثبت‌نام باز' : 'ثبت‌نام بسته' ?>
              · <?= $workshop['status'] === 'COMPLETED' ? 'آرشیو / برگزار شده' : ($workshop['status'] === 'CANCELLED' ? 'لغو شده' : 'فعال') ?>
            </div>
          </div>
          <div class="workshop-card-actions">
            <?php if ($workshopRole === 'doctor'): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url('/doctor/workshop-export?id=' . $workshop['id'])) ?>">خروجی ثبت‌نام‌ها</a>
              <?php if (($workshop['type'] ?? '') === 'ONLINE' && function_exists('video_call_workshop_enter_url')): ?>
                <a class="btn btn-primary btn-sm" href="<?= e(video_call_workshop_enter_url((string) $workshop['id'])) ?>">ورود به جلسه آنلاین</a>
              <?php endif; ?>
              <?php if (($workshop['type'] ?? '') === 'OFFLINE' && function_exists('workshop_qa_url_doctor')): ?>
                <a class="btn btn-outline btn-sm" href="<?= e(workshop_qa_url_doctor((string) $workshop['id'])) ?>">تالار گفتگو</a>
              <?php endif; ?>
              <?php if ($workshop['status'] !== 'CANCELLED'): ?>
                <a class="btn btn-outline btn-sm" href="<?= e(url($workshopEditBase . '?edit=' . $workshop['id'])) ?>#session-notes">یادداشت جلسات</a>
              <?php endif; ?>
            <?php endif; ?>
            <?php if (!$archived && $workshop['status'] !== 'COMPLETED' && $workshop['status'] !== 'CANCELLED'): ?>
              <a class="btn btn-outline btn-sm" href="<?= e(url($workshopEditBase . '?edit=' . $workshop['id'])) ?>#workshop-form"><?= $workshopRole === 'secretary' ? 'ویرایش / فایل' : 'ویرایش' ?></a>
              <form method="post" action="<?= e(url($workshopPostBase)) ?>">
                <input type="hidden" name="action" value="toggle_enrollment">
                <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= !empty($workshop['enrollment_open']) ? 'بستن ثبت‌نام' : 'باز کردن ثبت‌نام' ?></button>
              </form>
            <?php endif; ?>
            <?php if ($workshopRole === 'doctor' && ($workshop['status'] === 'PUBLISHED' || ($workshop['is_published'] && !$archived))): ?>
              <form method="post" action="<?= e(url($workshopPostBase)) ?>">
                <input type="hidden" name="action" value="complete">
                <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
                <button class="btn btn-outline btn-sm" type="submit">تسویه و پایان</button>
              </form>
            <?php endif; ?>
            <form method="post" action="<?= e(url($workshopPostBase)) ?>">
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= $workshop['is_published'] ? 'لغو انتشار' : 'انتشار' ?></button>
            </form>
            <form method="post" action="<?= e(url($workshopPostBase)) ?>" onsubmit="return confirm('حذف شود؟')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= e($workshop['id']) ?>">
              <button class="btn btn-danger btn-sm" type="submit">حذف</button>
            </form>
          </div>
        </div>
        <?php
          $wid = (string) $workshop['id'];
          $tabName = 'wcard-' . $wid;
          $doctorBoardOpen = $workshopRole === 'doctor' && $openDoctorPathId !== '' && $openDoctorPathId === $wid;
          $pathPeople = [];
          if ($workshopRole === 'doctor') {
              foreach (($workshopEnrollmentsById[$wid] ?? []) as $enr) {
                  if (!is_array($enr)) {
                      continue;
                  }
                  if (!in_array((string) ($enr['status'] ?? ''), ['CONFIRMED', 'COMPLETED'], true)) {
                      continue;
                  }
                  $pathPeople[] = $enr;
              }
          }
          $openTab = $doctorBoardOpen ? 'path' : 'files';
        ?>
        <div class="wcard-tabs">
          <input type="radio" name="<?= e($tabName) ?>" id="<?= e($tabName) ?>-files" <?= $openTab === 'files' ? 'checked' : '' ?>>
          <input type="radio" name="<?= e($tabName) ?>" id="<?= e($tabName) ?>-enroll" <?= $openTab === 'enroll' ? 'checked' : '' ?>>
          <?php if ($workshopRole === 'doctor'): ?>
            <input type="radio" name="<?= e($tabName) ?>" id="<?= e($tabName) ?>-path" <?= $openTab === 'path' ? 'checked' : '' ?>>
          <?php endif; ?>
          <input type="radio" name="<?= e($tabName) ?>" id="<?= e($tabName) ?>-info" <?= $openTab === 'info' ? 'checked' : '' ?>>
          <div class="wcard-tabbar" role="tablist">
            <label for="<?= e($tabName) ?>-files">فایل جلسات</label>
            <label for="<?= e($tabName) ?>-enroll">ثبت‌نام‌ها</label>
            <?php if ($workshopRole === 'doctor'): ?>
              <label for="<?= e($tabName) ?>-path">مسیر</label>
            <?php endif; ?>
            <label for="<?= e($tabName) ?>-info">جزئیات</label>
          </div>
          <div class="wcard-panel wcard-panel-files">
            <?= workshop_session_file_lines_html($staffSessions, url($workshopMediaPost), $wid) ?>
          </div>
          <div class="wcard-panel wcard-panel-enroll">
            <?php if (in_array($workshopRole, ['secretary', 'doctor'], true)): ?>
              <?php
                $enrollmentList = $workshopEnrollmentsById[$wid] ?? [];
                $enrollmentDeskAction = url($workshopPostBase);
                require __DIR__ . '/workshop_enrollment_desk.php';
              ?>
            <?php endif; ?>
          </div>
          <?php if ($workshopRole === 'doctor'): ?>
            <div class="wcard-panel wcard-panel-path">
              <div class="workshop-path-people">
                <ul class="workshop-path-people-list">
                  <li>
                    <span>یادداشت جلسه، پیام برای مراجع و فایل همان جلسه</span>
                    <?php if (function_exists('workshop_doctor_board_url')): ?>
                      <?php if ($doctorBoardOpen && function_exists('workshop_doctor_board_close_url')): ?>
                        <a class="btn btn-outline btn-sm" href="<?= e(workshop_doctor_board_close_url($workshop)) ?>">بستن مسیر</a>
                      <?php else: ?>
                        <a class="btn btn-outline btn-sm" href="<?= e(workshop_doctor_board_url($workshop)) ?>">باز کردن مسیر</a>
                      <?php endif; ?>
                    <?php endif; ?>
                  </li>
                </ul>
                <?php if ($doctorBoardOpen && !empty($doctorPathBoardById[$wid]) && function_exists('workshop_doctor_path_render')): ?>
                  <?= workshop_doctor_path_render($doctorPathBoardById[$wid]) ?>
                <?php endif; ?>
              </div>
              <?php if ($pathPeople): ?>
                <div class="workshop-path-people">
                  <h3 class="workshop-path-people-title">شرکت‌کننده‌ها</h3>
                  <ul class="workshop-path-people-list">
                    <?php foreach ($pathPeople as $enr): ?>
                      <li>
                        <span><?= e((string) ($enr['patient_name'] ?? 'مراجع')) ?></span>
                        <?php if (function_exists('workshop_path_doctor_url')): ?>
                          <a class="btn btn-outline btn-sm" href="<?= e(workshop_path_doctor_url((string) ($enr['id'] ?? ''))) ?>">مسیر این نفر</a>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php else: ?>
                <p class="muted" style="margin:.75rem 0 0">هنوز شرکت‌کننده تأییدشده‌ای نیست.</p>
              <?php endif; ?>
            </div>
          <?php endif; ?>
          <div class="wcard-panel wcard-panel-info">
            <?php if ($workshop['type'] === 'IN_PERSON' && !empty($workshop['location'])): ?>
              <p style="margin:.35rem 0 0"><strong>محل:</strong> <?= e((string) $workshop['location']) ?></p>
            <?php endif; ?>
            <?php if (!empty($workshop['group_url'])): ?>
              <p style="margin:.55rem 0 0"><strong>گروه:</strong> <a href="<?= e((string) $workshop['group_url']) ?>" target="_blank" rel="noopener" dir="ltr"><?= e((string) $workshop['group_url']) ?></a></p>
            <?php endif; ?>
            <?php if (!empty($workshop['items_to_bring'])): ?>
              <p style="margin:.55rem 0 0"><strong>موارد همراه:</strong> <?= e((string) $workshop['items_to_bring']) ?></p>
            <?php endif; ?>
            <?php if (!empty($workshop['notes'])): ?>
              <p class="muted" style="margin:.55rem 0 0"><strong>یادداشت:</strong> <?= e((string) $workshop['notes']) ?></p>
            <?php endif; ?>
            <?php if (empty($workshop['items_to_bring']) && empty($workshop['notes']) && empty($workshop['location']) && empty($workshop['group_url'])): ?>
              <p class="muted" style="margin:.35rem 0 0">جزئیات دیگری ثبت نشده است.</p>
            <?php endif; ?>
            <?php if ($archived): ?>
              <p class="muted" style="font-size:.85rem;margin:.7rem 0 0">این کارگاه در آرشیو است.</p>
            <?php elseif (!$workshop['is_published'] || $workshop['status'] !== 'PUBLISHED'): ?>
              <p style="color:var(--danger);font-size:.85rem;margin:.7rem 0 0">مراجعه‌کنندگان این کارگاه را نمی‌بینند — دکمه «انتشار» را بزنید.</p>
            <?php elseif (empty($workshop['enrollment_open'])): ?>
              <p style="color:var(--warning,#b45309);font-size:.85rem;margin:.7rem 0 0">ثبت‌نام بسته است.</p>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
