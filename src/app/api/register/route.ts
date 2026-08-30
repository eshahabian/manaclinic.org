import { NextResponse } from "next/server";
import { hash } from "bcryptjs";
import { prisma } from "@/lib/prisma";
import type { Role } from "@prisma/client";

export async function POST(req: Request) {
  try {
    const body = await req.json();
    const name = String(body.name || "").trim();
    const email = String(body.email || "").toLowerCase().trim();
    const phone = String(body.phone || "").trim() || null;
    const password = String(body.password || "");
    const role = String(body.role || "PATIENT") as Role;
    const specialty = String(body.specialty || "").trim();

    if (!name || !email || password.length < 6) {
      return NextResponse.json(
        { error: "اطلاعات ناقص است. رمز حداقل ۶ کاراکتر باشد." },
        { status: 400 }
      );
    }

    if (role !== "PATIENT" && role !== "DOCTOR") {
      return NextResponse.json({ error: "نوع حساب نامعتبر است." }, { status: 400 });
    }

    if (role === "DOCTOR" && !specialty) {
      return NextResponse.json(
        { error: "برای ثبت‌نام به‌عنوان درمانگر، تخصص الزامی است." },
        { status: 400 }
      );
    }

    const exists = await prisma.user.findUnique({ where: { email } });
    if (exists) {
      return NextResponse.json(
        { error: "این ایمیل قبلاً ثبت شده است." },
        { status: 409 }
      );
    }

    const passwordHash = await hash(password, 10);

    if (role === "DOCTOR") {
      const user = await prisma.user.create({
        data: {
          name,
          email,
          phone,
          passwordHash,
          role: "DOCTOR",
          doctorProfile: {
            create: {
              specialty,
              isApproved: false,
              isActive: false,
            },
          },
        },
        select: { id: true, email: true, name: true, role: true },
      });

      return NextResponse.json({
        user,
        pendingApproval: true,
        message:
          "درخواست ثبت‌نام شما ثبت شد. پس از تأیید مدیر سایت می‌توانید وارد شوید.",
      });
    }

    const user = await prisma.user.create({
      data: {
        name,
        email,
        phone,
        passwordHash,
        role: "PATIENT",
      },
      select: { id: true, email: true, name: true, role: true },
    });

    return NextResponse.json({ user, pendingApproval: false });
  } catch {
    return NextResponse.json({ error: "خطای سرور" }, { status: 500 });
  }
}
