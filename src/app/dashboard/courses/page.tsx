import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatJalaliDate, formatPrice } from "@/lib/utils";

const SECTIONS = {
  "in-person": { label: "دوره‌های حضوری", type: "IN_PERSON" as const },
  online: { label: "دوره‌های آنلاین", type: "ONLINE" as const },
  offline: { label: "دوره‌های آفلاین", type: "OFFLINE" as const },
};

const enrollmentLabels: Record<string, string> = {
  PENDING_PAYMENT: "در انتظار پرداخت",
  CONFIRMED: "تأیید شده",
  CANCELLED: "لغو شده",
  REFUNDED: "بازپرداخت شده",
  COMPLETED: "برگزار شده",
};

export default async function PatientCoursesPage({
  searchParams,
}: {
  searchParams: Promise<{ type?: string }>;
}) {
  const session = await requireUser(["PATIENT"]);
  const { type } = await searchParams;
  const activeKey = type && type in SECTIONS ? type : "in-person";
  const active = SECTIONS[activeKey as keyof typeof SECTIONS];

  const available = await prisma.workshop.findMany({
    where: {
      type: active.type,
      isPublished: true,
      status: "PUBLISHED",
      startsAt: { gt: new Date() },
    },
    include: { doctor: { include: { user: true } } },
    orderBy: { startsAt: "asc" },
  });

  const myEnrollments = await prisma.workshopEnrollment.findMany({
    where: {
      patientId: session.user.id,
      workshop: { type: active.type },
    },
    include: {
      workshop: { include: { doctor: { include: { user: true } } } },
      payment: true,
    },
    orderBy: { enrolledAt: "desc" },
  });

  const enrolledIds = new Set(
    myEnrollments
      .filter((e) => ["PENDING_PAYMENT", "CONFIRMED", "COMPLETED"].includes(e.status))
      .map((e) => e.workshopId)
  );

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">دوره‌های من</h1>
      <p className="text-muted">کارگاه‌های حضوری، آنلاین و آفلاین.</p>

      <nav className="flex flex-wrap gap-2">
        {Object.entries(SECTIONS).map(([key, { label }]) => (
          <Link
            key={key}
            href={`/dashboard/courses?type=${key}`}
            className={`rounded-lg border px-3 py-2 text-sm ${
              activeKey === key
                ? "border-primary bg-primary text-white"
                : "border-line bg-white text-muted"
            }`}
          >
            {label}
          </Link>
        ))}
      </nav>

      <section className="panel space-y-3">
        <h2 className="text-lg font-bold">کارگاه‌های قابل ثبت‌نام</h2>
        {available.map((w) => (
          <div key={w.id} className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-line p-3">
            <div>
              <p className="font-bold">{w.title}</p>
              <p className="text-sm text-muted">{w.doctor.user.name}</p>
              <p className="mt-1 text-sm">
                {formatJalaliDate(w.startsAt)} — {formatJalaliDate(w.endsAt)}
              </p>
              <p className="text-sm text-muted">{formatPrice(w.price)}</p>
            </div>
            {enrolledIds.has(w.id) ? (
              <span className="badge">ثبت‌نام شده</span>
            ) : (
              <p className="text-sm text-muted">ثبت‌نام از نسخه PHP فعال است.</p>
            )}
          </div>
        ))}
        {available.length === 0 && <p className="text-muted">کارگاه فعالی نیست.</p>}
      </section>

      <section className="panel space-y-3">
        <h2 className="text-lg font-bold">ثبت‌نام‌های من</h2>
        {myEnrollments.map((e) => (
          <div key={e.id} className="rounded-lg border border-line p-3">
            <p className="font-bold">{e.workshop.title}</p>
            <p className="text-sm text-muted">{e.workshop.doctor.user.name}</p>
            <p className="mt-1 text-sm">{formatJalaliDate(e.workshop.startsAt)}</p>
            <span className="badge mt-2 inline-block">{enrollmentLabels[e.status] ?? e.status}</span>
          </div>
        ))}
        {myEnrollments.length === 0 && <p className="text-muted">ثبت‌نامی ندارید.</p>}
      </section>
    </div>
  );
}
