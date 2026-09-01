import { NextResponse } from "next/server";
import { getServerSession } from "next-auth";
import { authOptions } from "@/lib/auth";
import { prisma } from "@/lib/prisma";

function refundRate(startsAt: Date): number {
  const hoursLeft = (startsAt.getTime() - Date.now()) / (3600 * 1000);
  if (hoursLeft >= 24) return 1;
  if (hoursLeft >= 3) return 0.5;
  return 0;
}

export async function POST(req: Request) {
  const session = await getServerSession(authOptions);
  if (!session?.user || session.user.role !== "PATIENT") {
    return NextResponse.json({ error: "لطفاً با حساب مراجع وارد شوید." }, { status: 401 });
  }

  const body = await req.json();
  const appointmentId = String(body.appointmentId || "");
  if (!appointmentId) {
    return NextResponse.json({ error: "شناسه نوبت نامعتبر است." }, { status: 400 });
  }

  const appointment = await prisma.appointment.findFirst({
    where: { id: appointmentId, patientId: session.user.id },
    include: { payment: true },
  });

  if (!appointment) {
    return NextResponse.json({ error: "نوبت یافت نشد." }, { status: 404 });
  }

  if (!["PENDING_PAYMENT", "CONFIRMED"].includes(appointment.status)) {
    return NextResponse.json({ error: "این نوبت قابل لغو نیست." }, { status: 400 });
  }

  let refundAmount = 0;
  if (appointment.status === "CONFIRMED" && appointment.payment?.status === "PAID") {
    refundAmount = Math.round(appointment.payment.amount * refundRate(appointment.startsAt));
  }

  await prisma.$transaction(async (tx) => {
    if (refundAmount > 0) {
      const wallet = await tx.wallet.upsert({
        where: { userId: session.user.id },
        create: { userId: session.user.id, balance: refundAmount },
        update: { balance: { increment: refundAmount } },
      });
      await tx.walletTransaction.create({
        data: {
          walletId: wallet.id,
          kind: "REFUND",
          amount: refundAmount,
          balanceAfter: wallet.balance + (wallet.balance === refundAmount ? 0 : refundAmount),
          referenceType: "appointment",
          referenceId: appointmentId,
          description: "لغو نوبت",
        },
      });
    }
    if (appointment.payment && appointment.status === "PENDING_PAYMENT") {
      await tx.payment.update({
        where: { id: appointment.payment.id },
        data: { status: "FAILED" },
      });
    }
    await tx.appointment.update({
      where: { id: appointmentId },
      data: { status: "CANCELLED" },
    });
  });

  const message =
    refundAmount > 0
      ? `نوبت لغو شد. ${refundAmount.toLocaleString("fa-IR")} تومان به کیف پول شما واریز شد.`
      : "نوبت لغو شد.";

  return NextResponse.json({ success: true, message });
}
