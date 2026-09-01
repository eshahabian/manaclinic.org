import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { AppointmentsPaymentPanel } from "@/components/appointments-payment-panel";
import {
  appointmentStatusLabel,
  formatJalaliDate,
  formatPrice,
  paymentStatusLabel,
} from "@/lib/utils";

export default async function PatientAppointmentsPage({
  searchParams,
}: {
  searchParams: Promise<{ pay?: string; booked?: string }>;
}) {
  const session = await requireUser(["PATIENT"]);
  const { pay, booked } = await searchParams;

  const appointments = await prisma.appointment.findMany({
    where: { patientId: session.user.id },
    include: {
      doctor: { include: { user: true } },
      payment: true,
    },
    orderBy: { startsAt: "desc" },
  });

  const payMessage: Record<string, string> = {
    success: "پرداخت با موفقیت انجام شد و نوبت تأیید شد.",
    failed: "پرداخت ناموفق بود.",
    cancelled: "پرداخت لغو شد.",
    notfound: "تراکنش یافت نشد.",
    missing: "اطلاعات پرداخت ناقص بود.",
  };

  const panelItems = appointments.map((a) => ({
    id: a.id,
    doctorName: a.doctor.user.name,
    specialty: a.doctor.specialty,
    startsAtIso: a.startsAt.toISOString(),
    startsAtLabel: `${formatJalaliDate(a.startsAt)} — ${a.startsAt.toLocaleTimeString("fa-IR", {
      hour: "2-digit",
      minute: "2-digit",
    })}`,
    status: a.status,
    statusLabel: appointmentStatusLabel[a.status],
    amountLabel: a.payment
      ? `${formatPrice(a.payment.amount)} — ${paymentStatusLabel[a.payment.status]}${
          a.payment.refId ? ` (پیگیری: ${a.payment.refId})` : ""
        }`
      : null,
    canPay: a.status === "PENDING_PAYMENT" && a.payment?.status === "PENDING",
    canCancel: a.status === "PENDING_PAYMENT" || a.status === "CONFIRMED",
    isPaid: a.payment?.status === "PAID",
  }));

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">نوبت‌های من</h1>
      {booked && (
        <div className="panel border-success text-sm text-success">
          نوبت با موفقیت ثبت شد. برای پرداخت، شرایط را بپذیرید و روی «پرداخت آنلاین» کلیک کنید.
        </div>
      )}
      {pay && payMessage[pay] && (
        <div
          className={`panel text-sm ${
            pay === "success" ? "border-success text-success" : "border-danger text-danger"
          }`}
        >
          {payMessage[pay]}
        </div>
      )}
      {appointments.length === 0 ? (
        <p className="text-muted">نوبتی ثبت نشده است.</p>
      ) : (
        <AppointmentsPaymentPanel appointments={panelItems} />
      )}
    </div>
  );
}
