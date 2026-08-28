import Link from "next/link";
import { prisma } from "@/lib/prisma";
import { formatJalaliDate } from "@/lib/utils";

export const metadata = { title: "مقالات" };

export default async function ArticlesPage() {
  const articles = await prisma.article.findMany({
    where: { published: true },
    include: { author: true },
    orderBy: { publishedAt: "desc" },
  });

  return (
    <div className="container-page py-12">
      <h1 className="text-3xl font-bold">مقالات روانشناسی</h1>
      <p className="mt-2 text-muted">محتوای تخصصی از تیم مانا کلینیک</p>
      <div className="mt-8 grid gap-5 md:grid-cols-2">
        {articles.map((article) => (
          <Link
            key={article.id}
            href={`/articles/${article.slug}`}
            className="panel transition hover:-translate-y-1 hover:shadow-md"
          >
            <div className="mb-3 flex items-center gap-2 text-xs text-muted">
              <span className="badge">{article.author.name}</span>
              {article.publishedAt && (
                <span>{formatJalaliDate(article.publishedAt)}</span>
              )}
            </div>
            <h2 className="text-xl font-bold leading-8">{article.title}</h2>
            <p className="mt-3 line-clamp-3 text-sm leading-7 text-muted">
              {article.excerpt}
            </p>
          </Link>
        ))}
        {articles.length === 0 && (
          <p className="text-muted">هنوز مقاله‌ای منتشر نشده است.</p>
        )}
      </div>
    </div>
  );
}
