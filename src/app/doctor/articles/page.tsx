import Link from "next/link";
import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireDoctorProfile } from "@/lib/doctor";
import { slugify } from "@/lib/utils";

async function createArticle(formData: FormData) {
  "use server";
  const { session } = await requireDoctorProfile();
  const title = String(formData.get("title") || "").trim();
  const excerpt = String(formData.get("excerpt") || "").trim();
  const content = String(formData.get("content") || "").trim();
  const published = formData.get("published") === "on";
  if (!title || !content) return;

  let slug = slugify(title) || `article-${Date.now()}`;
  const exists = await prisma.article.findUnique({ where: { slug } });
  if (exists) slug = `${slug}-${Date.now()}`;

  await prisma.article.create({
    data: {
      title,
      slug,
      excerpt,
      content,
      published,
      publishedAt: published ? new Date() : null,
      authorId: session.user.id,
    },
  });
  revalidatePath("/doctor/articles");
  revalidatePath("/articles");
}

async function togglePublish(formData: FormData) {
  "use server";
  const { session } = await requireDoctorProfile();
  const id = String(formData.get("id") || "");
  const article = await prisma.article.findFirst({
    where: { id, authorId: session.user.id },
  });
  if (!article) return;
  const published = !article.published;
  await prisma.article.update({
    where: { id },
    data: {
      published,
      publishedAt: published ? article.publishedAt || new Date() : article.publishedAt,
    },
  });
  revalidatePath("/doctor/articles");
  revalidatePath("/articles");
}

async function deleteArticle(formData: FormData) {
  "use server";
  const { session } = await requireDoctorProfile();
  const id = String(formData.get("id") || "");
  await prisma.article.deleteMany({
    where: { id, authorId: session.user.id },
  });
  revalidatePath("/doctor/articles");
  revalidatePath("/articles");
}

export default async function DoctorArticlesPage() {
  const { session } = await requireDoctorProfile();
  const articles = await prisma.article.findMany({
    where: { authorId: session.user.id },
    orderBy: { createdAt: "desc" },
  });

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">مقالات من</h1>

      <form action={createArticle} className="panel space-y-4">
        <h2 className="font-bold">مقاله جدید</h2>
        <div>
          <label className="label">عنوان</label>
          <input name="title" className="input" required />
        </div>
        <div>
          <label className="label">خلاصه</label>
          <input name="excerpt" className="input" />
        </div>
        <div>
          <label className="label">متن</label>
          <textarea name="content" className="input min-h-48" required />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" name="published" defaultChecked />
          انتشار فوری
        </label>
        <button type="submit" className="btn btn-primary">
          ذخیره مقاله
        </button>
      </form>

      <div className="space-y-3">
        {articles.map((a) => (
          <div key={a.id} className="panel flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="font-bold">{a.title}</p>
              <p className="text-sm text-muted">
                {a.published ? "منتشر شده" : "پیش‌نویس"}
              </p>
              {a.published && (
                <Link href={`/articles/${a.slug}`} className="text-sm text-primary">
                  مشاهده
                </Link>
              )}
            </div>
            <div className="flex gap-2">
              <form action={togglePublish}>
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
      </div>
    </div>
  );
}
