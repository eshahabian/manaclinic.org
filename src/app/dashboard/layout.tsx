import { PanelNav } from "@/components/panel-nav";
import { requireUser } from "@/lib/session";

export default async function DashboardLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  await requireUser(["PATIENT"]);

  return (
    <div className="container-page grid gap-6 py-10 md:grid-cols-[240px_1fr]">
      <PanelNav
        title="پنل مراجعه‌کننده"
        items={[
          { href: "/dashboard", label: "خلاصه" },
          { href: "/dashboard/appointments", label: "نوبت‌های من" },
          { href: "/dashboard/courses", label: "دوره‌های من" },
          { href: "/dashboard/wallet", label: "کیف پول" },
          { href: "/dashboard/profile", label: "پروفایل" },
          { href: "/doctors", label: "رزرو نوبت جدید" },
        ]}
      />
      <div>{children}</div>
    </div>
  );
}
