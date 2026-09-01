"use client";

import { useEffect, useState } from "react";
import { BookingTermsSections } from "@/components/booking-terms-content";

export function BookingTermsAcceptance({
  checked,
  onChange,
}: {
  checked: boolean;
  onChange: (value: boolean) => void;
}) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === "Escape") setOpen(false);
    }
    document.body.style.overflow = "hidden";
    window.addEventListener("keydown", onKey);
    return () => {
      document.body.style.overflow = "";
      window.removeEventListener("keydown", onKey);
    };
  }, [open]);

  return (
    <>
      <label className="flex cursor-pointer items-start gap-2 text-sm leading-7">
        <input
          type="checkbox"
          className="mt-1.5"
          checked={checked}
          onChange={(e) => onChange(e.target.checked)}
        />
        <span>
          <button
            type="button"
            className="text-primary underline"
            onClick={(e) => {
              e.preventDefault();
              setOpen(true);
            }}
          >
            شرایط رزرو و پرداخت
          </button>
          {" "}را مطالعه کردم و می‌پذیرم.
        </span>
      </label>

      {open && (
        <div className="fixed inset-0 z-[200000] flex items-center justify-center p-4">
          <button
            type="button"
            className="absolute inset-0 bg-black/55 backdrop-blur-[2px]"
            aria-label="بستن"
            onClick={() => setOpen(false)}
          />
          <div className="relative z-10 flex max-h-[85vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-line px-4 py-3">
              <h2 className="text-base font-bold">قوانین و شرایط</h2>
              <button
                type="button"
                className="text-2xl leading-none text-muted"
                onClick={() => setOpen(false)}
                aria-label="بستن"
              >
                ×
              </button>
            </div>
            <div className="overflow-auto px-4 py-3 text-sm leading-8">
              <BookingTermsSections />
            </div>
            <div className="border-t border-line px-4 py-3 text-left">
              <button type="button" className="btn btn-primary btn-sm" onClick={() => setOpen(false)}>
                متوجه شدم
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
}
