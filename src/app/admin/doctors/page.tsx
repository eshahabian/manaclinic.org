import { revalidatePath } from "next/cache";
import { hash } from "bcryptjs";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatPrice } from "@/lib/utils";

async function createDoctor(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const name = String(formData.get("name") || "").trim();
  const email = String(formData.get("email") || "").toLowerCase().trim();
  const password = String(formData.get("password") || "");
  const specialty = String(formData.get("specialty") || "").trim();
  const bio = String(formData.get("bio") || "").trim();
  const sessionPrice = Number(formData.get("sessionPrice") || 3000000);
  const phone = String(formData.get("phone") || "").trim() || null;

  if (!name || !email || password.length < 6 || !specialty) return;

  const exists = await prisma.user.findUnique({ where: { email } });
  if (exists) return;

  const passwordHash = await hash(password, 10);
  await prisma.user.create({
    data: {
      name,
      email,
      phone,
      passwordHash,
      role: "DOCTOR",
      doctorProfile: {
        create: {
          specialty,
          bio,
          sessionPrice,
          isApproved: true,
          isActive: true,
        },
      },
    },
  });
  revalidatePath("/admin/doctors");
  revalidatePath("/doctors");
}

async function approveDoctor(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const id = String(formData.get("id") || "");
  const doctor = await prisma.doctorProfile.findUnique({ where: { id } });
  if (!doctor) return;
  await prisma.doctorProfile.update({
    where: { id },
    data: { isApproved: true, isActive: true },
  });
  revalidatePath("/admin/doctors");
  revalidatePath("/doctors");
}

async function rejectDoctor(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const id = String(formData.get("id") || "");
  const doctor = await prisma.doctorProfile.findUnique({ where: { id } });
  if (!doctor || doctor.isApproved) return;
  await prisma.user.delete({ where: { id: doctor.userId } });
  revalidatePath("/admin/doctors");
  revalidatePath("/doctors");
}

async function toggleDoctor(formData: FormData) {
  "use server";
  await requireUser(["ADMIN"]);
  const id = String(formData.get("id") || "");
  const doctor = await prisma.doctorProfile.findUnique({ where: { id } });
  if (!doctor || !doctor.isApproved) return;
  await prisma.doctorProfile.update({
    where: { id },
    data: { isActive: !doctor.isActive },
  });
  revalidatePath("/admin/doctors");
  revalidatePath("/doctors");
}

export default async function AdminDoctorsPage() {
  await requireUser(["ADMIN"]);
  const doctors = await prisma.doctorProfile.findMany({
    include: { user: true },
    orderBy: { createdAt: "desc" },
  });

  const pending = doctors.filter((d) => !d.isApproved);
  const approved = doctors.filter((d) => d.isApproved);

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">مدیریت درمانگرها</h1>

      {pending.length > 0 && (
        <section className="space-y-3">
          <h2 className="text-lg font-bold text-primary">
            درخواست‌های در انتظار تأیید ({pending.length})
          </h2>
          {pending.map((d) => (
            <div
              key={d.id}
              className="panel flex flex-wrap items-center justify-between gap-3 border-2 border-primary/30"
            >
              <div>
                <p className="font-bold">{d.user.name}</p>
                <p className="text-sm text-primary">{d.specialty}</p>
                <p className="text-sm text-muted" dir="ltr">
                  {d.user.email}
                </p>
                {d.user.phone && (
                  <p className="text-sm text-muted" dir="ltr">
                    {d.user.phone}
                  </p>
                )}
                <p className="mt-1 text-xs text-muted">
                  ثبت‌نام: {new Intl.DateTimeFormat("fa-IR").format(d.createdAt)}
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <form action={approveDoctor}>
                  <input type="hidden" name="id" value={d.id} />
                  <button type="submit" className="btn btn-primary !py-2 !text-sm">
                    تأیید درمانگر
                  </button>
                </form>
                <form action={rejectDoctor}>
                  <input type="hidden" name="id" value={d.id} />
                  <button type="submit" className="btn btn-danger !py-2 !text-sm">
                    رد درخواست
                  </button>
                </form>
              </div>
            </div>
          ))}
        </section>
      )}

      <form action={createDoctor} className="panel grid gap-4 md:grid-cols-2">
        <h2 className="font-bold md:col-span-2">افزودن درمانگر جدید (مستقیم)</h2>
        <div>
          <label className="label">نام</label>
          <input name="name" className="input" required />
        </div>
        <div>
          <label className="label">ایمیل</label>
          <input name="email" type="email" className="input" required dir="ltr" />
        </div>
        <div>
          <label className="label">رمز موقت</label>
          <input name="password" type="text" className="input" required minLength={6} dir="ltr" />
        </div>
        <div>
          <label className="label">موبایل</label>
          <input name="phone" className="input" dir="ltr" />
        </div>
        <div>
          <label className="label">تخصص</label>
          <input name="specialty" className="input" required />
        </div>
        <div>
          <label className="label">هزینه جلسه</label>
          <input
            name="sessionPrice"
            type="number"
            className="input"
            defaultValue={3000000}
            required
            dir="ltr"
          />
        </div>
        <div className="md:col-span-2">
          <label className="label">بیوگرافی</label>
          <textarea name="bio" className="input min-h-24" />
        </div>
        <button type="submit" className="btn btn-primary md:col-span-2">
          ایجاد حساب درمانگر
        </button>
      </form>

      <div className="space-y-3">
        <h2 className="text-lg font-bold">درمانگرهای تأییدشده</h2>
        {approved.length === 0 && (
          <p className="text-sm text-muted">هنوز درمانگر تأییدشده‌ای وجود ندارد.</p>
        )}
        {approved.map((d) => (
          <div key={d.id} className="panel flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="font-bold">{d.user.name}</p>
              <p className="text-sm text-primary">{d.specialty}</p>
              <p className="text-sm text-muted" dir="ltr">
                {d.user.email}
              </p>
              <p className="mt-1 text-sm">{formatPrice(d.sessionPrice)}</p>
              {!d.isActive && (
                <p className="mt-1 text-xs text-danger">غیرفعال</p>
              )}
            </div>
            <form action={toggleDoctor}>
              <input type="hidden" name="id" value={d.id} />
              <button
                type="submit"
                className={`btn !py-2 !text-sm ${d.isActive ? "btn-danger" : "btn-primary"}`}
              >
                {d.isActive ? "غیرفعال کردن" : "فعال کردن"}
              </button>
            </form>
          </div>
        ))}
      </div>
    </div>
  );
}
