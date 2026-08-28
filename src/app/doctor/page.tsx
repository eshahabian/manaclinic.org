import { prisma } from "@/lib/prisma";
import { requireDoctorProfile } from "@/lib/doctor";
import { appointmentStatusLabel, formatJalaliDate } from "@/lib/utils";

export default async function DoctorHomePage() {
  const { profile, session } = await requireDoctorProfile();
  const upcoming = await prisma.appointment.findMany({
    where: {
      doctorId: profile.id,
      status: { in: ["CONFIRMED", "PENDING_PAYMENT"] },
      startsAt: { gte: new Date() },
    },
    include: { patient: true },
    orderBy: { startsAt: "asc" },
    take: 5,
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">سلام دکتر {session.user.name}</h1>
        <p className="mt-2 text-muted">نوبت‌ها و برنامه کاری خود را مدیریت کنید.</p>
      </div>
      <div className="panel space-y-3">
        <h2 className="font-bold">نوبت‌های پیش‌رو</h2>
        {upcoming.map((a) => (
          <div
            key={a.id}
            className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-line p-3"
          >
            <div>
              <p className="font-semibold">{a.patient.name}</p>
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
        {upcoming.length === 0 && (
          <p className="text-sm text-muted">نوبت پیش‌رویی نیست.</p>
        )}
      </div>
    </div>
  );
}
