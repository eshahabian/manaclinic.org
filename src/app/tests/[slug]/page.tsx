import Link from "next/link";
import { notFound } from "next/navigation";
import { getPsychTestBySlug } from "@/lib/psych-tests";

type Props = { params: Promise<{ slug: string }> };

export async function generateMetadata({ params }: Props) {
  const { slug } = await params;
  const test = getPsychTestBySlug(slug);
  return { title: test?.title ?? "آزمون" };
}

export default async function TestDetailPage({ params }: Props) {
  const { slug } = await params;
  const test = getPsychTestBySlug(slug);

  if (!test) {
    notFound();
  }

  return (
    <div className="container-page py-12">
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <span className="badge">{test.category}</span>
        <span className="text-sm text-muted">{test.abbr}</span>
        <span className="text-sm text-muted">⏱ {test.duration}</span>
      </div>
      <h1 className="text-3xl font-bold leading-tight">{test.title}</h1>
      <p className="mt-4 max-w-2xl leading-8 text-muted">{test.description}</p>

      <div className="panel mt-8 p-6">
        {test.ready ? (
          <p>فرم آزمون اینجا قرار می‌گیرد.</p>
        ) : (
          <p className="leading-8 text-muted">
            محتوای این آزمون هنوز بارگذاری نشده است. به‌زودی سوالات و تفسیر
            نتایج اضافه می‌شود.
          </p>
        )}
      </div>

      <p className="mt-6 text-sm leading-7 text-muted">
        این آزمون‌ها جایگزین تشخیص بالینی نیستند. در صورت نگرانی، با متخصص
        مشورت کنید.{" "}
        <Link href="/doctors" className="font-semibold text-primary">
          رزرو نوبت
        </Link>
      </p>
    </div>
  );
}
