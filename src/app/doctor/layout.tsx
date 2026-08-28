import { PanelNav } from "@/components/panel-nav";
import { requireUser } from "@/lib/session";

export default async function DoctorLayout({
  children,
}: {
  children: React.ReactNode;
}) {
  await requireUser(["DOCTOR"]);

  return (
    <div className="container-page grid gap-6 py-10 md:grid-cols-[240px_1fr]">
      <PanelNav
        title="پنل دکتر"
        items={[
          { href: "/doctor", label: "خلاصه" },
          { href: "/doctor/profile", label: "پروفایل حرفه‌ای" },
          { href: "/doctor/availability", label: "روزهای خالی" },
          { href: "/doctor/appointments", label: "نوبت‌ها" },
          { href: "/doctor/articles", label: "مقالات" },
        ]}
      />
      <div>{children}</div>
    </div>
  );
}
