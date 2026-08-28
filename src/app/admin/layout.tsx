import { PanelNav } from "@/components/panel-nav";
import { requireUser } from "@/lib/session";

export default async function AdminLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  await requireUser(["ADMIN"]);

  return (
    <div className="container-page grid gap-6 py-10 md:grid-cols-[240px_1fr]">
      <PanelNav
        title="پنل ادمین"
        items={[
          { href: "/admin", label: "خلاصه" },
          { href: "/admin/doctors", label: "مدیریت دکترها" },
          { href: "/admin/users", label: "کاربران" },
          { href: "/admin/articles", label: "مقالات" },
          { href: "/admin/appointments", label: "نوبت‌ها و پرداخت‌ها" },
        ]}
      />
      <div>{children}</div>
    </div>
  );
}
