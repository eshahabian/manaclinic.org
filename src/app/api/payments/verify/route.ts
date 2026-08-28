import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { zarinpalVerify } from "@/lib/zarinpal";

export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const authority = searchParams.get("Authority") || searchParams.get("authority");
  const status = searchParams.get("Status") || searchParams.get("status");
  const appUrl = process.env.APP_URL || "http://localhost:3000";

  if (!authority) {
    return NextResponse.redirect(`${appUrl}/dashboard/appointments?pay=missing`);
  }

  const payment = await prisma.payment.findFirst({
    where: { authority },
    include: { appointment: true },
  });

  if (!payment) {
    return NextResponse.redirect(`${appUrl}/dashboard/appointments?pay=notfound`);
  }

  if (status !== "OK") {
    await prisma.$transaction([
      prisma.payment.update({
        where: { id: payment.id },
        data: { status: "FAILED" },
      }),
      prisma.appointment.update({
        where: { id: payment.appointmentId },
        data: { status: "CANCELLED" },
      }),
    ]);
    return NextResponse.redirect(`${appUrl}/dashboard/appointments?pay=cancelled`);
  }

  const verified = await zarinpalVerify({
    authority,
    amount: payment.amount,
  });

  if (!verified.ok) {
    await prisma.$transaction([
      prisma.payment.update({
        where: { id: payment.id },
        data: { status: "FAILED" },
      }),
      prisma.appointment.update({
        where: { id: payment.appointmentId },
        data: { status: "CANCELLED" },
      }),
    ]);
    return NextResponse.redirect(`${appUrl}/dashboard/appointments?pay=failed`);
  }

  await prisma.$transaction([
    prisma.payment.update({
      where: { id: payment.id },
      data: { status: "PAID", refId: verified.refId },
    }),
    prisma.appointment.update({
      where: { id: payment.appointmentId },
      data: { status: "CONFIRMED" },
    }),
  ]);

  return NextResponse.redirect(`${appUrl}/dashboard/appointments?pay=success`);
}
