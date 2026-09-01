import { getSession } from "@/lib/session";
import { PanelNav } from "@/components/panel-nav";

const PATIENT_NAV = [
  { href: "/dashboard", label: "خلاصه" },
  { href: "/dashboard/appointments", label: "نوبت‌های من" },
  { href: "/dashboard/profile", label: "پروفایل" },
  { href: "/doctors", label: "رزرو نوبت جدید" },
];

export async function PatientPanelShell({
  children,
}: {
  children: React.ReactNode;
}) {
  const session = await getSession();
  if (session?.user?.role !== "PATIENT") {
    return <>{children}</>;
  }

  return (
    <div className="container-page grid gap-6 py-10 md:grid-cols-[240px_1fr]">
      <PanelNav title="پنل مراجع" items={PATIENT_NAV} />
      <div>{children}</div>
    </div>
  );
}
