import Link from "next/link";
import { requireUser } from "@/lib/session";

const SECTIONS = {
  "in-person": "دوره‌های حضوری",
  online: "دوره‌های آنلاین",
  offline: "دوره‌های آفلاین",
} as const;

type SectionKey = keyof typeof SECTIONS;

export default async function PatientCoursesPage({
  searchParams,
}: {
  searchParams: Promise<{ type?: string }>;
}) {
  await requireUser(["PATIENT"]);
  const { type } = await searchParams;
  const active: SectionKey =
    type && type in SECTIONS ? (type as SectionKey) : "in-person";

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">دوره‌های من</h1>
      <p className="text-muted">
        دوره‌های ثبت‌نام‌شده شما در سه دسته حضوری، آنلاین و آفلاین.
      </p>

      <nav className="flex flex-wrap gap-2" aria-label="دسته‌بندی دوره‌ها">
        {(Object.entries(SECTIONS) as [SectionKey, string][]).map(([key, label]) => (
          <Link
            key={key}
            href={`/dashboard/courses?type=${key}`}
            className={`rounded-lg border px-3 py-2 text-sm ${
              active === key
                ? "border-primary bg-primary text-white"
                : "border-line bg-white text-muted hover:border-primary hover:text-primary"
            }`}
          >
            {label}
          </Link>
        ))}
      </nav>

      <section className="panel">
        <h2 className="text-lg font-bold">{SECTIONS[active]}</h2>
        <p className="mt-4 text-muted">هنوز دوره‌ای در این بخش ثبت نشده است.</p>
      </section>
    </div>
  );
}
