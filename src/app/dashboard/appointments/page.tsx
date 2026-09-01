import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { PayAppointmentButton } from "@/components/pay-appointment-button";
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

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">نوبت‌های من</h1>
      {booked && (
        <div className="panel border-success text-sm text-success">
          نوبت با موفقیت ثبت شد. برای پرداخت روی دکمه «پرداخت آنلاین» کلیک کنید.
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
      <div className="space-y-3">
        {appointments.map((a) => (
          <div key={a.id} className="panel">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-lg font-bold">{a.doctor.user.name}</p>
                <p className="mt-1 text-sm text-muted">{a.doctor.specialty}</p>
                <p className="mt-2 text-sm">
                  {formatJalaliDate(a.startsAt)} —{" "}
                  {a.startsAt.toLocaleTimeString("fa-IR", {
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </p>
              </div>
              <div className="text-left text-sm">
                <span className="badge">{appointmentStatusLabel[a.status]}</span>
                {a.payment && (
                  <p className="mt-2 text-muted">
                    {formatPrice(a.payment.amount)} —{" "}
                    {paymentStatusLabel[a.payment.status]}
                    {a.payment.refId ? ` (پیگیری: ${a.payment.refId})` : ""}
                  </p>
                )}
                {a.status === "PENDING_PAYMENT" && a.payment?.status === "PENDING" && (
                  <PayAppointmentButton appointmentId={a.id} />
                )}
              </div>
            </div>
          </div>
        ))}
        {appointments.length === 0 && (
          <p className="text-muted">نوبتی ثبت نشده است.</p>
        )}
      </div>
    </div>
  );
}
