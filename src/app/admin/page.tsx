import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatPrice } from "@/lib/utils";

export default async function AdminHomePage() {
  await requireUser(["ADMIN"]);
  const [users, doctors, pendingDoctors, articles, appointments, paid] = await Promise.all([
    prisma.user.count(),
    prisma.doctorProfile.count({ where: { isActive: true, isApproved: true } }),
    prisma.doctorProfile.count({ where: { isApproved: false } }),
    prisma.article.count({ where: { published: true } }),
    prisma.appointment.count(),
    prisma.payment.aggregate({
      where: { status: "PAID" },
      _sum: { amount: true },
      _count: true,
    }),
  ]);

  const cards = [
    { label: "کاربران", value: users },
    { label: "درمانگرهای فعال", value: doctors },
    { label: "در انتظار تأیید", value: pendingDoctors },
    { label: "مقالات منتشرشده", value: articles },
    { label: "کل نوبت‌ها", value: appointments },
    {
      label: "پرداخت‌های موفق",
      value: `${paid._count} / ${formatPrice(paid._sum.amount || 0)}`,
    },
  ];

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">داشبورد مدیریت</h1>
        <p className="mt-2 text-muted">نمای کلی وضعیت مانا کلینیک</p>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {cards.map((c) => (
          <div key={c.label} className="panel">
            <p className="text-sm text-muted">{c.label}</p>
            <p className="mt-2 text-2xl font-bold">{c.value}</p>
          </div>
        ))}
      </div>
    </div>
  );
}
