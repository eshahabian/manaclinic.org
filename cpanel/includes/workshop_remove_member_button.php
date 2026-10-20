<?php
declare(strict_types=1);
if (workshop_can_add_member(current_user()) && in_array((string) ($enr['status'] ?? ''), ['PENDING_PAYMENT','CONFIRMED'], true)):
?>
<form method="post" action="<?= e(url('/staff/workshops/remove-member')) ?>" onsubmit="return confirm('عضویت این فرد در کارگاه حذف شود؟ سوابق پرداخت حفظ می‌شوند.')">
  <?= csrf_field() ?>
  <input type="hidden" name="enrollment_id" value="<?= e((string) $enr['id']) ?>">
  <button type="submit" class="btn btn-danger btn-sm workshop-remove-member">حذف عضو</button>
</form>
<?php endif; ?>
