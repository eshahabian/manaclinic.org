import Link from "next/link";
import { notFound } from "next/navigation";
import { BookingForm } from "@/components/booking-form";
import { PatientPanelShell } from "@/components/patient-panel-shell";
import { prisma } from "@/lib/prisma";
import { formatPrice } from "@/lib/utils";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const doctor = await prisma.doctorProfile.findUnique({
    where: { id },
    include: { user: true },
  });
  return { title: doctor?.user.name || "روانشناس" };
}

export default async function DoctorDetailPage({
  params,
}: {
  params: Promise<{ id: string }>;
}) {
  const { id } = await params;
  const doctor = await prisma.doctorProfile.findUnique({
    where: { id },
    include: {
      user: true,
      availabilities: { orderBy: { date: "asc" } },
    },
  });

  if (!doctor || !doctor.isActive || !doctor.isApproved) notFound();

  const articles = await prisma.article.findMany({
    where: { authorId: doctor.userId, published: true },
    take: 3,
    orderBy: { publishedAt: "desc" },
  });

  return (
    <PatientPanelShell>
      <div className="py-2">
        <Link href="/doctors" className="text-sm text-primary">
          ← بازگشت به لیست
        </Link>

        <div className="mt-6 grid gap-6 lg:grid-cols-[1.2fr_0.8fr]">
          <div className="panel">
            <div className="flex items-start gap-4">
              <div className="flex h-20 w-20 items-center justify-center rounded-full bg-[var(--bg-soft)] text-2xl font-bold text-primary">
                {doctor.user.name.slice(0, 1)}
              </div>
              <div>
                <h1 className="text-3xl font-bold">{doctor.user.name}</h1>
                <p className="mt-1 text-primary">{doctor.specialty}</p>
                <p className="mt-2 font-semibold">
                  هزینه هر جلسه: {formatPrice(doctor.sessionPrice)}
                </p>
              </div>
            </div>
            <div className="mt-6 space-y-2 leading-8 text-muted">
              {doctor.bio.split(/\n+/).map((line) => (
                <p key={line}>{line}</p>
              ))}
            </div>

            {articles.length > 0 && (
              <div className="mt-8 border-t border-line pt-6">
                <h2 className="mb-3 text-lg font-bold">مقالات این متخصص</h2>
                <ul className="space-y-2">
                  {articles.map((a) => (
                    <li key={a.id}>
                      <Link href={`/articles/${a.slug}`} className="text-primary">
                        {a.title}
                      </Link>
                    </li>
                  ))}
                </ul>
              </div>
            )}
          </div>

          <BookingForm
            doctorId={doctor.id}
            sessionPrice={doctor.sessionPrice}
            availabilities={doctor.availabilities.map((a) => ({
              id: a.id,
              date: a.date,
              startTime: a.startTime,
              endTime: a.endTime,
              slotMinutes: a.slotMinutes,
            }))}
          />
        </div>
      </div>
    </PatientPanelShell>
  );
}
