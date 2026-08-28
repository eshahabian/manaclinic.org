"use client";

import { useState } from "react";
import DatePicker from "react-multi-date-picker";
import persian from "react-date-object/calendars/persian";
import gregorian from "react-date-object/calendars/gregorian";
import persian_fa from "react-date-object/locales/persian_fa";
import DateObject from "react-date-object";

export function AvailabilityForm({
  action,
}: {
  action: (formData: FormData) => Promise<void>;
}) {
  const [date, setDate] = useState("");

  return (
    <form action={action} className="panel grid gap-4 md:grid-cols-2">
      <div className="md:col-span-2">
        <label className="label">تاریخ (جلالی)</label>
        <input type="hidden" name="date" value={date} required />
        <DatePicker
          calendar={persian}
          locale={persian_fa}
          inputClass="input"
          containerClassName="w-full"
          onChange={(d: DateObject | null) => {
            if (!d) {
              setDate("");
              return;
            }
            const g = d.convert(gregorian);
            setDate(
              `${g.year}-${String(g.month.number).padStart(2, "0")}-${String(g.day).padStart(2, "0")}`
            );
          }}
        />
        {date && (
          <p className="mt-1 text-xs text-muted" dir="ltr">
            ذخیره به‌صورت: {date}
          </p>
        )}
      </div>
      <div>
        <label className="label">از ساعت</label>
        <input name="startTime" type="time" className="input" defaultValue="10:00" required />
      </div>
      <div>
        <label className="label">تا ساعت</label>
        <input name="endTime" type="time" className="input" defaultValue="14:00" required />
      </div>
      <div>
        <label className="label">مدت هر جلسه (دقیقه)</label>
        <input
          name="slotMinutes"
          type="number"
          className="input"
          defaultValue={50}
          min={20}
          max={180}
          required
        />
      </div>
      <div className="flex items-end">
        <button type="submit" className="btn btn-primary w-full" disabled={!date}>
          افزودن / به‌روزرسانی
        </button>
      </div>
    </form>
  );
}
