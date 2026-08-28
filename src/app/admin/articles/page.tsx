import Link from "next/link";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";

async function toggleArticle(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const id = String(formData.get("id") || "");
  const article = await prisma.article.findUnique({ where: { id } });
  if (!article) return;
  const published = !article.published;
  await prisma.article.update({
    where: { id },
    data: {
      published,
      publishedAt: published ? article.publishedAt || new Date() : article.publishedAt,
    },
  });
  revalidatePath("/admin/articles");
  revalidatePath("/articles");
}

async function deleteArticle(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const id = String(formData.get("id") || "");
  await prisma.article.delete({ where: { id } });
  revalidatePath("/admin/articles");
  revalidatePath("/articles");
}

export default async function AdminArticlesPage() {
  await requireUser(["ADMIN"]);
  const articles = await prisma.article.findMany({
    include: { author: true },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">مدیریت مقالات</h1>
      {articles.map((a) => (
        <div key={a.id} className="panel flex flex-wrap items-center justify-between gap-3">
          <div>
            <p className="font-bold">{a.title}</p>
            <p className="text-sm text-muted">
              نویسنده: {a.author.name} — {a.published ? "منتشر شده" : "پیش‌نویس"}
            </p>
            {a.published && (
              <Link href={`/articles/${a.slug}`} className="text-sm text-primary">
                مشاهده عمومی
              </Link>
            )}
          </div>
          <div className="flex gap-2">
            <form action={toggleArticle}>
              <input type="hidden" name="id" value={a.id} />
              <button type="submit" className="btn btn-outline !py-2 !text-sm">
                {a.published ? "لغو انتشار" : "انتشار"}
              </button>
            </form>
            <form action={deleteArticle}>
              <input type="hidden" name="id" value={a.id} />
              <button type="submit" className="btn btn-danger !py-2 !text-sm">
                حذف
              </button>
            </form>
          </div>
        </div>
      ))}
      {articles.length === 0 && <p className="text-muted">مقاله‌ای نیست.</p>}
    </div>
  );
}
