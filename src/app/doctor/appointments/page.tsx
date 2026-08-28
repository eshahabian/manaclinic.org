import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireDoctorProfile } from "@/lib/doctor";
import {
  appointmentStatusLabel,
  formatJalaliDate,
  formatPrice,
  paymentStatusLabel,
} from "@/lib/utils";

async function updateStatus(formData: FormData) {
  "use server";
  const { profile } = await requireDoctorProfile();
  const id = String(formData.get("id") || "");
  const status = String(formData.get("status") || "");
  if (!["CONFIRMED", "CANCELLED", "COMPLETED"].includes(status)) return;

  await prisma.appointment.updateMany({
    where: { id, doctorId: profile.id },
    data: { status: status as "CONFIRMED" | "CANCELLED" | "COMPLETED" },
  });
  revalidatePath("/doctor/appointments");
}

export default async function DoctorAppointmentsPage() {
  const { profile } = await requireDoctorProfile();
  const appointments = await prisma.appointment.findMany({
    where: { doctorId: profile.id },
    include: { patient: true, payment: true },
    orderBy: { startsAt: "desc" },
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">نوبت‌های بیماران</h1>
      {appointments.map((a) => (
        <div key={a.id} className="panel space-y-3">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="font-bold">{a.patient.name}</p>
              <p className="text-sm text-muted">{a.patient.phone || a.patient.email}</p>
              <p className="mt-2 text-sm">
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
                </p>
              )}
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {a.status !== "CANCELLED" && (
              <form action={updateStatus}>
                <input type="hidden" name="id" value={a.id} />
                <input type="hidden" name="status" value="CANCELLED" />
                <button className="btn btn-danger !py-2 !text-sm" type="submit">
                  لغو
                </button>
              </form>
            )}
            {a.status === "CONFIRMED" && (
              <form action={updateStatus}>
                <input type="hidden" name="id" value={a.id} />
                <input type="hidden" name="status" value="COMPLETED" />
                <button className="btn btn-outline !py-2 !text-sm" type="submit">
                  انجام شد
                </button>
              </form>
            )}
          </div>
        </div>
      ))}
      {appointments.length === 0 && (
        <p className="text-muted">نوبتی ثبت نشده است.</p>
      )}
    </div>
  );
}
