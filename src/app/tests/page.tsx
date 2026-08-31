import Link from "next/link";
import { psychTestsCatalog } from "@/lib/psych-tests";

export const metadata = { title: "آزمون‌های روانشناسی" };

export default function TestsPage() {
  return (
    <div className="container-page py-12">
      <h1 className="text-3xl font-bold">آزمون‌های روانشناسی</h1>
      <p className="mt-2 max-w-xl leading-8 text-muted">
        ابزارهای استاندارد غربالگری و خودارزیابی. محتوای هر آزمون به‌زودی فعال
        می‌شود.
      </p>
      <div className="mt-8 grid gap-5 md:grid-cols-2">
        {psychTestsCatalog.map((test) => (
          <Link
            key={test.slug}
            href={`/tests/${test.slug}`}
            className="panel transition hover:-translate-y-1 hover:shadow-md"
          >
            <div className="mb-3 flex items-start justify-between gap-3">
              <span className="badge">{test.category}</span>
              <span className="text-xs text-muted">{test.abbr}</span>
            </div>
            <h2 className="text-xl font-bold leading-8">{test.title}</h2>
            <p className="mt-3 text-sm leading-7 text-muted">
              {test.description}
            </p>
            <p className="mt-3 text-sm text-muted">⏱ {test.duration}</p>
            {!test.ready && (
              <p className="mt-3 text-sm text-accent">به‌زودی</p>
            )}
          </Link>
        ))}
      </div>
    </div>
  );
}
