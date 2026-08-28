import Link from "next/link";
import { notFound } from "next/navigation";
import { prisma } from "@/lib/prisma";
import { formatJalaliDate } from "@/lib/utils";

export async function generateMetadata({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const article = await prisma.article.findUnique({ where: { slug } });
  return { title: article?.title || "مقاله" };
}

export default async function ArticleDetailPage({
  params,
}: {
  params: Promise<{ slug: string }>;
}) {
  const { slug } = await params;
  const article = await prisma.article.findUnique({
    where: { slug },
    include: { author: true },
  });

  if (!article || !article.published) notFound();

  return (
    <article className="container-page py-12">
      <Link href="/articles" className="text-sm text-primary">
        ← بازگشت به مقالات
      </Link>
      <h1 className="mt-4 max-w-3xl text-3xl font-bold leading-tight md:text-4xl">
        {article.title}
      </h1>
      <div className="mt-4 flex flex-wrap items-center gap-3 text-sm text-muted">
        <span className="badge">{article.author.name}</span>
        {article.publishedAt && (
          <span>{formatJalaliDate(article.publishedAt)}</span>
        )}
      </div>
      <div className="panel prose-content mt-8 max-w-3xl whitespace-pre-wrap leading-8">
        {article.content}
      </div>
    </article>
  );
}
