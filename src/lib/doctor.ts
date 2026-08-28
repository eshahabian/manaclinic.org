import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";

export async function requireDoctorProfile() {
  const session = await requireUser(["DOCTOR"]);
  const profile = await prisma.doctorProfile.findUnique({
    where: { userId: session.user.id },
    include: { user: true },
  });
  if (!profile) {
    throw new Error("پروفایل دکتر یافت نشد");
  }
  return { session, profile };
}
