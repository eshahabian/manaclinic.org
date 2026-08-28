"use client";

import { useEffect, useMemo, useState } from "react";
import { useSession } from "next-auth/react";
import { useRouter } from "next/navigation";
import DatePicker from "react-multi-date-picker";
import persian from "react-date-object/calendars/persian";
import gregorian from "react-date-object/calendars/gregorian";
import persian_fa from "react-date-object/locales/persian_fa";
import DateObject from "react-date-object";
import { formatPrice } from "@/lib/utils";

function toIsoDate(date: DateObject) {
  const g = date.convert(gregorian);
  return `${g.year}-${String(g.month.number).padStart(2, "0")}-${String(g.day).padStart(2, "0")}`;
}

type Availability = {
  id: string;
  date: string;
  startTime: string;
  endTime: string;
  slotMinutes: number;
};

export function BookingForm({
  doctorId,
  sessionPrice,
  availabilities,
}: {
  doctorId: string;
  sessionPrice: number;
  availabilities: Availability[];
}) {
  const { data: session, status } = useSession();
  const router = useRouter();
  const [selectedDate, setSelectedDate] = useState<string>("");
  const [slots, setSlots] = useState<string[]>([]);
  const [selectedSlot, setSelectedSlot] = useState("");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  const availableDates = useMemo(
    () => new Set(availabilities.map((a) => a.date)),
    [availabilities]
  );

  useEffect(() => {
    if (!selectedDate) {
      setSlots([]);
      return;
    }
    fetch(`/api/slots?doctorId=${doctorId}&date=${selectedDate}`)
      .then((r) => r.json())
      .then((data) => {
        setSlots(data.slots || []);
        setSelectedSlot("");
      })
      .catch(() => setSlots([]));
  }, [doctorId, selectedDate]);

  async function book() {
    setError("");
    if (status !== "authenticated") {
      router.push(`/login?callbackUrl=/doctors/${doctorId}`);
      return;
    }
    if (session?.user.role !== "PATIENT") {
      setError("فقط بیماران می‌توانند نوبت رزرو کنند.");
      return;
    }
    if (!selectedDate || !selectedSlot) {
      setError("تاریخ و ساعت را انتخاب کنید.");
      return;
    }

    setLoading(true);
    const res = await fetch("/api/appointments", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        doctorId,
        date: selectedDate,
        time: selectedSlot,
      }),
    });
    const data = await res.json();
    setLoading(false);

    if (!res.ok) {
      setError(data.error || "رزرو ناموفق بود");
      return;
    }

    if (data.paymentUrl) {
      window.location.href = data.paymentUrl;
      return;
    }
    router.push("/dashboard/appointments");
  }

  return (
    <div className="panel space-y-4">
      <h2 className="text-xl font-bold">رزرو نوبت آنلاین</h2>
      <p className="text-sm text-muted">هزینه جلسه: {formatPrice(sessionPrice)}</p>

      <div>
        <label className="label">انتخاب تاریخ</label>
        <DatePicker
          calendar={persian}
          locale={persian_fa}
          inputClass="input"
          containerClassName="w-full"
          calendarPosition="bottom-right"
          value={
            selectedDate
              ? new DateObject({
                  date: selectedDate,
                  calendar: gregorian,
                  format: "YYYY-MM-DD",
                }).convert(persian)
              : undefined
          }
          onChange={(date: DateObject | null) => {
            if (!date) {
              setSelectedDate("");
              return;
            }
            const iso = toIsoDate(date);
            if (!availableDates.has(iso)) {
              setError("در این روز نوبت خالی وجود ندارد.");
              setSelectedDate("");
              return;
            }
            setError("");
            setSelectedDate(iso);
          }}
          mapDays={({ date }) => {
            const iso = toIsoDate(date);
            if (!availableDates.has(iso)) {
              return {
                disabled: true,
                style: { color: "#ccc" },
              };
            }
            return {
              style: { color: "#1b5e4b", fontWeight: "bold" },
            };
          }}
        />
      </div>

      {selectedDate && (
        <div>
          <label className="label">ساعت‌های خالی</label>
          <div className="flex flex-wrap gap-2">
            {slots.map((slot) => (
              <button
                key={slot}
                type="button"
                onClick={() => setSelectedSlot(slot)}
                className={`rounded-lg border px-3 py-2 text-sm ${
                  selectedSlot === slot
                    ? "border-primary bg-primary text-white"
                    : "border-line bg-white"
                }`}
              >
                {slot}
              </button>
            ))}
            {slots.length === 0 && (
              <p className="text-sm text-muted">ساعت خالی برای این روز نیست.</p>
            )}
          </div>
        </div>
      )}

      {error && <p className="text-sm text-danger">{error}</p>}

      <button
        type="button"
        className="btn btn-primary w-full"
        onClick={book}
        disabled={loading}
      >
        {loading ? "در حال انتقال به پرداخت..." : "رزرو و پرداخت"}
      </button>
    </div>
  );
}
