import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { appointmentStatusLabel, formatJalaliDate } from "@/lib/utils";

export default async function PatientDashboardPage() {
  const session = await requireUser(["PATIENT"]);
  const appointments = await prisma.appointment.findMany({
    where: { patientId: session.user.id },
    include: { doctor: { include: { user: true } } },
    orderBy: { startsAt: "desc" },
    take: 5,
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">سلام {session.user.name}</h1>
        <p className="mt-2 text-muted">نوبت‌ها و وضعیت پرداخت‌های خود را اینجا ببینید.</p>
      </div>
      <div className="panel">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="font-bold">آخرین نوبت‌ها</h2>
          <Link href="/doctors" className="btn btn-primary !py-2 !text-sm">
            رزرو جدید
          </Link>
        </div>
        <div className="space-y-3">
          {appointments.map((a) => (
            <div
              key={a.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line p-3"
            >
              <div>
                <p className="font-semibold">{a.doctor.user.name}</p>
                <p className="text-sm text-muted">
                  {formatJalaliDate(a.startsAt)} —{" "}
                  {a.startsAt.toLocaleTimeString("fa-IR", {
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </p>
              </div>
              <span className="badge">{appointmentStatusLabel[a.status]}</span>
            </div>
          ))}
          {appointments.length === 0 && (
            <p className="text-sm text-muted">هنوز نوبتی ندارید.</p>
          )}
        </div>
      </div>
    </div>
  );
}
