import Link from "next/link";
import { prisma } from "@/lib/prisma";

export default async function HomePage() {
  const [doctors, articles] = await Promise.all([
    prisma.doctorProfile.findMany({
      where: { isActive: true, isApproved: true },
      include: { user: true },
      orderBy: { createdAt: "asc" },
    }),
    prisma.article.findMany({
      where: { published: true },
      include: { author: true },
      orderBy: { publishedAt: "desc" },
    }),
  ]);

  return (
    <>
      <section className="hero-surface">
        <div className="container-page pb-16 pt-28">
          <p className="animate-rise mb-3 text-sm tracking-[0.2em] text-white/80">
            مانا کلینیک
          </p>
          <h1 className="animate-rise max-w-2xl text-4xl font-bold leading-tight md:text-5xl">
            آرامش ذهن، مسیر روشن‌تر زندگی
          </h1>
          <p className="animate-rise-delay mt-4 max-w-xl text-base leading-8 text-white/90 md:text-lg">
            مقالات تخصصی بخوانید، روانشناس مناسب را پیدا کنید و آنلاین نوبت بگیرید.
          </p>
          <div className="animate-rise-delay mt-8 flex flex-wrap gap-3">
            <Link href="/doctors" className="btn btn-accent animate-float">
              رزرو نوبت
            </Link>
            <Link
              href="/articles"
              className="btn border border-white/40 bg-white/10 text-white hover:bg-white/20"
            >
              خواندن مقالات
            </Link>
          </div>
        </div>
      </section>

      <section className="container-page mt-16">
        <div className="mb-8">
          <h2 className="text-2xl font-bold">متخصصان ما</h2>
          <p className="mt-2 text-muted">
            متخصصان با تجربه برای همراهی در مسیر درمان
          </p>
        </div>
        <div className="grid items-start gap-5 md:grid-cols-3">
          {doctors[0] && (
            <div className="flex flex-col gap-5">
              <Link
                href={`/doctors/${doctors[0].id}`}
                className="panel transition hover:-translate-y-1 hover:shadow-md"
              >
                <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg-soft)] text-lg font-bold text-primary">
                  {doctors[0].user.name.slice(0, 1)}
                </div>
                <h3 className="text-lg font-bold">{doctors[0].user.name}</h3>
                <p className="mt-1 text-sm text-primary">{doctors[0].specialty}</p>
                <p className="mt-3 line-clamp-3 whitespace-pre-line text-sm leading-7 text-muted">
                  {doctors[0].bio}
                </p>
              </Link>
              <Link
                href="/doctors"
                className="px-1 text-base font-semibold text-primary hover:underline"
              >
                مشاهده همه
              </Link>
            </div>
          )}
          {doctors.slice(1).map((doc) => (
            <Link
              key={doc.id}
              href={`/doctors/${doc.id}`}
              className="panel transition hover:-translate-y-1 hover:shadow-md"
            >
              <div className="mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-[var(--bg-soft)] text-lg font-bold text-primary">
                {doc.user.name.slice(0, 1)}
              </div>
              <h3 className="text-lg font-bold">{doc.user.name}</h3>
              <p className="mt-1 text-sm text-primary">{doc.specialty}</p>
              <p className="mt-3 line-clamp-3 whitespace-pre-line text-sm leading-7 text-muted">
                {doc.bio}
              </p>
            </Link>
          ))}
          {doctors.length === 0 && (
            <p className="text-muted">هنوز دکتری ثبت نشده است.</p>
          )}
        </div>
      </section>

      <section className="container-page mt-20">
        <div className="mb-8">
          <h2 className="text-2xl font-bold">آخرین مقالات</h2>
          <p className="mt-2 text-muted">دانش کاربردی برای سلامت روان</p>
        </div>
        <div className="grid items-start gap-5 md:grid-cols-3">
          {articles[0] && (
            <div className="flex flex-col gap-5">
              <Link
                href={`/articles/${articles[0].slug}`}
                className="panel transition hover:-translate-y-1 hover:shadow-md"
              >
                <p className="badge mb-3">{articles[0].author.name}</p>
                <h3 className="text-lg font-bold leading-8">
                  {articles[0].title}
                </h3>
                <p className="mt-3 line-clamp-3 text-sm leading-7 text-muted">
                  {articles[0].excerpt}
                </p>
              </Link>
              <Link
                href="/articles"
                className="px-1 text-base font-semibold text-primary hover:underline"
              >
                همه مقالات
              </Link>
            </div>
          )}
          {articles.slice(1).map((article) => (
            <Link
              key={article.id}
              href={`/articles/${article.slug}`}
              className="panel transition hover:-translate-y-1 hover:shadow-md"
            >
              <p className="badge mb-3">{article.author.name}</p>
              <h3 className="text-lg font-bold leading-8">{article.title}</h3>
              <p className="mt-3 line-clamp-3 text-sm leading-7 text-muted">
                {article.excerpt}
              </p>
            </Link>
          ))}
        </div>
      </section>
    </>
  );
}
