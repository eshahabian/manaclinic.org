import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";

async function updateProfile(formData: FormData) {
  "use server";
  const session = await requireUser(["PATIENT"]);
  const name = String(formData.get("name") || "").trim();
  const phone = String(formData.get("phone") || "").trim();
  if (!name) return;
  await prisma.user.update({
    where: { id: session.user.id },
    data: { name, phone: phone || null },
  });
  revalidatePath("/dashboard/profile");
}

export default async function PatientProfilePage() {
  const session = await requireUser(["PATIENT"]);
  const user = await prisma.user.findUniqueOrThrow({
    where: { id: session.user.id },
  });

  return (
    <div className="max-w-lg space-y-4">
      <h1 className="text-2xl font-bold">پروفایل</h1>
      <form action={updateProfile} className="panel space-y-4">
        <div>
          <label className="label">نام</label>
          <input name="name" className="input" defaultValue={user.name} required />
        </div>
        <div>
          <label className="label">ایمیل</label>
          <input className="input" value={user.email} disabled dir="ltr" />
        </div>
        <div>
          <label className="label">موبایل</label>
          <input
            name="phone"
            className="input"
            defaultValue={user.phone || ""}
            dir="ltr"
          />
        </div>
        <button type="submit" className="btn btn-primary">
          ذخیره تغییرات
        </button>
      </form>
    </div>
  );
}
