export function formatPrice(amount: number) {
  return new Intl.NumberFormat("fa-IR").format(amount) + " تومان";
}

export function slugify(text: string) {
  return text
    .trim()
    .toLowerCase()
    .replace(/\s+/g, "-")
    .replace(/[^\u0600-\u06FFa-z0-9-]/g, "")
    .replace(/-+/g, "-")
    .replace(/^-|-$/g, "");
}

export function toFaDigits(value: string | number) {
  return String(value).replace(/\d/g, (d) => "۰۱۲۳۴۵۶۷۸۹"[Number(d)]);
}

/** Combine YYYY-MM-DD and HH:mm into Date (local) */
export function combineDateTime(date: string, time: string) {
  const [y, m, d] = date.split("-").map(Number);
  const [hh, mm] = time.split(":").map(Number);
  return new Date(y, m - 1, d, hh, mm, 0, 0);
}

export function addMinutes(date: Date, minutes: number) {
  return new Date(date.getTime() + minutes * 60_000);
}

export function formatTimeRange(start: Date, end: Date) {
  const opts: Intl.DateTimeFormatOptions = {
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  };
  return `${start.toLocaleTimeString("fa-IR", opts)} – ${end.toLocaleTimeString("fa-IR", opts)}`;
}

export function formatJalaliDate(date: Date) {
  return new Intl.DateTimeFormat("fa-IR", {
    year: "numeric",
    month: "long",
    day: "numeric",
  }).format(date);
}

export function generateSlots(
  startTime: string,
  endTime: string,
  slotMinutes: number
) {
  const slots: string[] = [];
  const [sh, sm] = startTime.split(":").map(Number);
  const [eh, em] = endTime.split(":").map(Number);
  let cursor = sh * 60 + sm;
  const end = eh * 60 + em;
  while (cursor + slotMinutes <= end) {
    const h = Math.floor(cursor / 60)
      .toString()
      .padStart(2, "0");
    const m = (cursor % 60).toString().padStart(2, "0");
    slots.push(`${h}:${m}`);
    cursor += slotMinutes;
  }
  return slots;
}

export const appointmentStatusLabel: Record<string, string> = {
  PENDING_PAYMENT: "در انتظار پرداخت",
  CONFIRMED: "تأیید شده",
  CANCELLED: "لغو شده",
  COMPLETED: "انجام شده",
};

export const paymentStatusLabel: Record<string, string> = {
  PENDING: "در انتظار",
  PAID: "پرداخت شده",
  FAILED: "ناموفق",
};

export const roleLabel: Record<string, string> = {
  ADMIN: "مدیر",
  DOCTOR: "درمانگر",
  PATIENT: "مراجع",
};
