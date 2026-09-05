import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireDoctorProfile } from "@/lib/doctor";
import { AvailabilityForm } from "@/components/availability-form";

async function addAvailability(formData: FormData) {
  "use server";
  const { profile } = await requireDoctorProfile();
  const date = String(formData.get("date") || "");
  const startTime = String(formData.get("startTime") || "");
  const endTime = String(formData.get("endTime") || "");
  const slotMinutes = Number(formData.get("slotMinutes") || 50);
  if (!date || !startTime || !endTime) return;

  const existing = await prisma.availability.findFirst({
    where: { doctorId: profile.id, date },
  });
  if (existing) {
    await prisma.availability.update({
      where: { id: existing.id },
      data: { startTime, endTime, slotMinutes },
    });
  } else {
    await prisma.availability.create({
      data: {
        doctorId: profile.id,
        date,
        startTime,
        endTime,
        slotMinutes,
      },
    });
  }
  revalidatePath("/doctor/availability");
}

async function deleteAvailability(formData: FormData) {
  "use server";
  const { profile } = await requireDoctorProfile();
  const id = String(formData.get("id") || "");
  await prisma.availability.deleteMany({
    where: { id, doctorId: profile.id },
  });
  revalidatePath("/doctor/availability");
}

export default async function DoctorAvailabilityPage() {
  const { profile } = await requireDoctorProfile();
  const items = await prisma.availability.findMany({
    where: { doctorId: profile.id },
    orderBy: { date: "asc" },
  });

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-bold">روزهای خالی</h1>
        <p className="mt-2 text-muted">
          تاریخ‌هایی که مراجعه‌کنندگان می‌توانند در آن‌ها نوبت بگیرند را مشخص کنید.
        </p>
      </div>

      <AvailabilityForm action={addAvailability} />

      <div className="space-y-3">
        {items.map((item) => (
          <div
            key={item.id}
            className="panel flex flex-wrap items-center justify-between gap-3"
          >
            <div>
              <p className="font-semibold" dir="ltr">
                {item.date}
              </p>
              <p className="text-sm text-muted">
                {item.startTime} تا {item.endTime} — هر اسلات {item.slotMinutes} دقیقه
              </p>
            </div>
            <form action={deleteAvailability}>
              <input type="hidden" name="id" value={item.id} />
              <button type="submit" className="btn btn-danger !py-2 !text-sm">
                حذف
              </button>
            </form>
          </div>
        ))}
        {items.length === 0 && (
          <p className="text-muted">هنوز روز خالی تعریف نشده است.</p>
        )}
      </div>
    </div>
  );
}
