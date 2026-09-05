import { NextResponse } from "next/server";
import { getServerSession } from "next-auth";
import { authOptions } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { zarinpalRequest } from "@/lib/zarinpal";

const ONLINE_PAYMENT_ENABLED = process.env.ONLINE_PAYMENT_ENABLED === "true";
const PAYMENT_DISABLED_MESSAGE = "پرداخت آنلاین فعلاً فعال نیست.";

export async function POST(req: Request) {
  const session = await getServerSession(authOptions);
  if (!session?.user || session.user.role !== "PATIENT") {
    return NextResponse.json({ error: "لطفاً با حساب مراجعه‌کننده وارد شوید." }, { status: 401 });
  }

  if (!ONLINE_PAYMENT_ENABLED) {
    return NextResponse.json({ error: PAYMENT_DISABLED_MESSAGE }, { status: 503 });
  }

  const body = await req.json();
  if (!body.acceptTerms) {
    return NextResponse.json({ error: "لطفاً شرایط رزرو و پرداخت را مطالعه و تأیید کنید." }, { status: 400 });
  }
  const appointmentId = String(body.appointmentId || "");
  if (!appointmentId) {
    return NextResponse.json({ error: "شناسه نوبت نامعتبر است." }, { status: 400 });
  }

  const appointment = await prisma.appointment.findFirst({
    where: { id: appointmentId, patientId: session.user.id },
    include: { payment: true },
  });
  if (!appointment || !appointment.payment) {
    return NextResponse.json({ error: "نوبت یافت نشد." }, { status: 404 });
  }

  if (appointment.status !== "PENDING_PAYMENT" || appointment.payment.status !== "PENDING") {
    return NextResponse.json({ error: "این نوبت قابل پرداخت نیست." }, { status: 400 });
  }

  const appUrl = process.env.APP_URL || "http://localhost:3000";
  try {
    const pay = await zarinpalRequest({
      amount: appointment.payment.amount,
      description: `پرداخت نوبت مانا کلینیک - ${appointment.id}`,
      callbackUrl: `${appUrl}/api/payments/verify`,
      email: session.user.email || undefined,
    });

    await prisma.payment.update({
      where: { id: appointment.payment.id },
      data: { authority: pay.authority },
    });

    return NextResponse.json({ paymentUrl: pay.paymentUrl });
  } catch (e) {
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "خطا در ایجاد پرداخت" },
      { status: 502 }
    );
  }
}
