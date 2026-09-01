import Link from "next/link";
import { PatientPanelShell } from "@/components/patient-panel-shell";
import { prisma } from "@/lib/prisma";

export const metadata = { title: "متخصصان" };

export default async function DoctorsPage({
  searchParams,
}: {
  searchParams: Promise<{ q?: string }>;
}) {
  const { q } = await searchParams;
  const query = q?.trim();

  const doctors = await prisma.doctorProfile.findMany({
    where: {
      isActive: true,
      isApproved: true,
      ...(query
        ? {
            OR: [
              { specialty: { contains: query } },
              { bio: { contains: query } },
              { user: { name: { contains: query } } },
            ],
          }
        : {}),
    },
    include: { user: true },
    orderBy: { createdAt: "asc" },
  });

  return (
    <PatientPanelShell>
      <div className="py-2">
        <h1 className="text-3xl font-bold">متخصصان</h1>
        <p className="mt-2 text-muted">متخصص مناسب خود را پیدا کنید و نوبت بگیرید</p>

        <form className="mt-6 max-w-xl">
          <input
            name="q"
            defaultValue={query}
            className="input"
            placeholder="جستجو بر اساس نام یا تخصص..."
          />
        </form>

        <div className="mt-8 grid gap-5 md:grid-cols-2">
          {doctors.map((doc) => (
            <Link
              key={doc.id}
              href={`/doctors/${doc.id}`}
              className="panel flex gap-4 transition hover:-translate-y-1 hover:shadow-md"
            >
              <div className="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-[var(--bg-soft)] text-xl font-bold text-primary">
                {doc.user.name.slice(0, 1)}
              </div>
              <div>
                <h2 className="text-xl font-bold">{doc.user.name}</h2>
                <p className="mt-1 text-sm text-primary">{doc.specialty}</p>
                <p className="mt-2 line-clamp-3 whitespace-pre-line text-sm leading-7 text-muted">
                  {doc.bio}
                </p>
              </div>
            </Link>
          ))}
          {doctors.length === 0 && (
            <p className="text-muted">نتیجه‌ای یافت نشد.</p>
          )}
        </div>
      </div>
    </PatientPanelShell>
  );
}
