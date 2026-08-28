import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import {
  appointmentStatusLabel,
  formatJalaliDate,
  formatPrice,
  paymentStatusLabel,
} from "@/lib/utils";

export default async function AdminAppointmentsPage() {
  await requireUser(["ADMIN"]);
  const appointments = await prisma.appointment.findMany({
    include: {
      doctor: { include: { user: true } },
      patient: true,
      payment: true,
    },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">نوبت‌ها و پرداخت‌ها</h1>
      {appointments.map((a) => (
        <div key={a.id} className="panel">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="font-bold">
                {a.patient.name} → {a.doctor.user.name}
              </p>
              <p className="mt-1 text-sm text-muted">
                {formatJalaliDate(a.startsAt)} —{" "}
                {a.startsAt.toLocaleTimeString("fa-IR", {
                  hour: "2-digit",
                  minute: "2-digit",
                })}
              </p>
            </div>
            <div className="text-sm">
              <span className="badge">{appointmentStatusLabel[a.status]}</span>
              {a.payment && (
                <p className="mt-2 text-muted">
                  {formatPrice(a.payment.amount)} —{" "}
                  {paymentStatusLabel[a.payment.status]}
                  {a.payment.refId ? ` / ${a.payment.refId}` : ""}
                </p>
              )}
            </div>
          </div>
        </div>
      ))}
      {appointments.length === 0 && (
        <p className="text-muted">نوبتی ثبت نشده است.</p>
      )}
    </div>
  );
}
