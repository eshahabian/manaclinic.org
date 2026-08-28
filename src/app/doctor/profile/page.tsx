import { revalidatePath } from "next/cache";
import { prisma } from "@/lib/prisma";
import { requireDoctorProfile } from "@/lib/doctor";
import { formatPrice } from "@/lib/utils";

async function updateDoctorProfile(formData: FormData) {
  "use server";
  const { profile } = await requireDoctorProfile();
  const specialty = String(formData.get("specialty") || "").trim();
  const bio = String(formData.get("bio") || "").trim();
  const sessionPrice = Number(formData.get("sessionPrice") || 0);
  if (!specialty || !sessionPrice) return;

  await prisma.doctorProfile.update({
    where: { id: profile.id },
    data: { specialty, bio, sessionPrice },
  });
  await prisma.user.update({
    where: { id: profile.userId },
    data: { name: String(formData.get("name") || profile.user.name).trim() },
  });
  revalidatePath("/doctor/profile");
  revalidatePath(`/doctors/${profile.id}`);
}

export default async function DoctorProfilePage() {
  const { profile } = await requireDoctorProfile();

  return (
    <div className="max-w-2xl space-y-4">
      <h1 className="text-2xl font-bold">پروفایل حرفه‌ای</h1>
      <form action={updateDoctorProfile} className="panel space-y-4">
        <div>
          <label className="label">نام نمایشی</label>
          <input name="name" className="input" defaultValue={profile.user.name} required />
        </div>
        <div>
          <label className="label">تخصص</label>
          <input
            name="specialty"
            className="input"
            defaultValue={profile.specialty}
            required
          />
        </div>
        <div>
          <label className="label">بیوگرافی</label>
          <textarea
            name="bio"
            className="input min-h-36"
            defaultValue={profile.bio}
          />
        </div>
        <div>
          <label className="label">هزینه جلسه (تومان)</label>
          <input
            name="sessionPrice"
            type="number"
            className="input"
            defaultValue={profile.sessionPrice}
            required
            dir="ltr"
          />
          <p className="mt-1 text-xs text-muted">
            فعلی: {formatPrice(profile.sessionPrice)}
          </p>
        </div>
        <button type="submit" className="btn btn-primary">
          ذخیره
        </button>
      </form>
    </div>
  );
}
