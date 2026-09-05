import { NextResponse } from "next/server";
import { getServerSession } from "next-auth";
import { authOptions } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { addMinutes, combineDateTime, generateSlots } from "@/lib/utils";
import { zarinpalRequest } from "@/lib/zarinpal";

const ONLINE_PAYMENT_ENABLED = process.env.ONLINE_PAYMENT_ENABLED === "true";

export async function POST(req: Request) {
  const session = await getServerSession(authOptions);
  if (!session?.user || session.user.role !== "PATIENT") {
    return NextResponse.json({ error: "لطفاً با حساب مراجعه‌کننده وارد شوید." }, { status: 401 });
  }

  const body = await req.json();
  if (!body.acceptTerms) {
    return NextResponse.json({ error: "لطفاً شرایط رزرو و پرداخت را مطالعه و تأیید کنید." }, { status: 400 });
  }
  const doctorId = String(body.doctorId || "");
  const date = String(body.date || "");
  const time = String(body.time || "");

  if (!doctorId || !date || !time) {
    return NextResponse.json({ error: "اطلاعات ناقص است." }, { status: 400 });
  }

  const doctor = await prisma.doctorProfile.findUnique({
    where: { id: doctorId },
  });
  if (!doctor || !doctor.isActive || !doctor.isApproved) {
    return NextResponse.json({ error: "دکتر یافت نشد." }, { status: 404 });
  }

  const availability = await prisma.availability.findFirst({
    where: { doctorId, date },
  });
  if (!availability) {
    return NextResponse.json({ error: "این روز در دسترس نیست." }, { status: 400 });
  }

  const validSlots = generateSlots(
    availability.startTime,
    availability.endTime,
    availability.slotMinutes
  );
  if (!validSlots.includes(time)) {
    return NextResponse.json({ error: "ساعت نامعتبر است." }, { status: 400 });
  }

  const startsAt = combineDateTime(date, time);
  if (startsAt.getTime() <= Date.now()) {
    return NextResponse.json({ error: "این زمان گذشته است." }, { status: 400 });
  }
  const endsAt = addMinutes(startsAt, availability.slotMinutes);

  const conflict = await prisma.appointment.findFirst({
    where: {
      doctorId,
      startsAt,
      status: { in: ["PENDING_PAYMENT", "CONFIRMED", "COMPLETED"] },
    },
  });
  if (conflict) {
    return NextResponse.json({ error: "این ساعت قبلاً رزرو شده است." }, { status: 409 });
  }

  const appointment = await prisma.appointment.create({
    data: {
      doctorId,
      patientId: session.user.id,
      startsAt,
      endsAt,
      status: "PENDING_PAYMENT",
      payment: {
        create: {
          amount: doctor.sessionPrice,
          status: "PENDING",
        },
      },
    },
    include: { payment: true },
  });

  if (!ONLINE_PAYMENT_ENABLED) {
    return NextResponse.json({
      appointmentId: appointment.id,
      paymentDisabled: true,
      message: "نوبت با موفقیت ثبت شد. برای پرداخت به بخش نوبت‌های من بروید.",
    });
  }

  const appUrl = process.env.APP_URL || "http://localhost:3000";
  try {
    const pay = await zarinpalRequest({
      amount: doctor.sessionPrice,
      description: `پرداخت نوبت مانا کلینیک - ${appointment.id}`,
      callbackUrl: `${appUrl}/api/payments/verify`,
      email: session.user.email || undefined,
    });

    await prisma.payment.update({
      where: { id: appointment.payment!.id },
      data: { authority: pay.authority },
    });

    return NextResponse.json({
      appointmentId: appointment.id,
      paymentUrl: pay.paymentUrl,
    });
  } catch (e) {
    await prisma.appointment.update({
      where: { id: appointment.id },
      data: { status: "CANCELLED" },
    });
    await prisma.payment.update({
      where: { id: appointment.payment!.id },
      data: { status: "FAILED" },
    });
    return NextResponse.json(
      { error: e instanceof Error ? e.message : "خطا در ایجاد پرداخت" },
      { status: 502 }
    );
  }
}
