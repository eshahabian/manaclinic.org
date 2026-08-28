import { NextResponse } from "next/server";
import { prisma } from "@/lib/prisma";
import { combineDateTime, generateSlots } from "@/lib/utils";

export async function GET(req: Request) {
  const { searchParams } = new URL(req.url);
  const doctorId = searchParams.get("doctorId");
  const date = searchParams.get("date");

  if (!doctorId || !date) {
    return NextResponse.json({ error: "پارامتر ناقص" }, { status: 400 });
  }

  const staleBefore = new Date(Date.now() - 20 * 60_000);
  await prisma.appointment.updateMany({
    where: {
      doctorId,
      status: "PENDING_PAYMENT",
      createdAt: { lt: staleBefore },
    },
    data: { status: "CANCELLED" },
  });

  const availability = await prisma.availability.findFirst({
    where: { doctorId, date },
  });

  if (!availability) {
    return NextResponse.json({ slots: [] });
  }

  const allSlots = generateSlots(
    availability.startTime,
    availability.endTime,
    availability.slotMinutes
  );

  const dayStart = combineDateTime(date, "00:00");
  const dayEnd = combineDateTime(date, "23:59");

  const taken = await prisma.appointment.findMany({
    where: {
      doctorId,
      startsAt: { gte: dayStart, lte: dayEnd },
      status: { in: ["PENDING_PAYMENT", "CONFIRMED", "COMPLETED"] },
    },
  });

  const takenTimes = new Set(
    taken.map((a) => {
      const h = a.startsAt.getHours().toString().padStart(2, "0");
      const m = a.startsAt.getMinutes().toString().padStart(2, "0");
      return `${h}:${m}`;
    })
  );

  const free = allSlots.filter((s) => {
    if (takenTimes.has(s)) return false;
    const start = combineDateTime(date, s);
    return start.getTime() > Date.now();
  });

  return NextResponse.json({ slots: free });
}
