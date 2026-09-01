"use client";

import { useState } from "react";
import { BookingTermsAcceptance } from "@/components/booking-terms-acceptance";
import { PayAppointmentButton } from "@/components/pay-appointment-button";

function refundHint(startsAtIso: string): string {
  const hoursLeft = (new Date(startsAtIso).getTime() - Date.now()) / (3600 * 1000);
  if (hoursLeft >= 24) return "کل مبلغ به کیف پول شما بازمی‌گردد.";
  if (hoursLeft >= 3) return "۵۰٪ مبلغ به کیف پول شما بازمی‌گردد.";
  return "بازگشت وجه امکان‌پذیر نیست.";
}

export function AppointmentsPaymentPanel({
  appointments,
}: {
  appointments: Array<{
    id: string;
    doctorName: string;
    specialty: string;
    startsAtLabel: string;
    startsAtIso: string;
    status: string;
    statusLabel: string;
    amountLabel: string | null;
    canPay: boolean;
    canCancel: boolean;
    isPaid: boolean;
  }>;
}) {
  const [termsAccepted, setTermsAccepted] = useState(false);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const hasPendingPay = appointments.some((a) => a.canPay);

  async function cancelAppointment(id: string) {
    if (!confirm("نوبت لغو شود؟")) return;
    setError("");
    setMessage("");
    const res = await fetch("/api/appointments/cancel", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ appointmentId: id }),
    });
    const data = await res.json();
    if (!res.ok) {
      setError(data.error || "لغو ناموفق بود");
      return;
    }
    setMessage(data.message || "نوبت لغو شد.");
    setTimeout(() => window.location.reload(), 1200);
  }

  return (
    <div className="space-y-3">
      {message && <div className="panel border-success text-sm text-success">{message}</div>}
      {error && <div className="panel border-danger text-sm text-danger">{error}</div>}
      {hasPendingPay && (
        <div className="panel">
          <BookingTermsAcceptance checked={termsAccepted} onChange={setTermsAccepted} />
        </div>
      )}
      {appointments.map((a) => (
        <div key={a.id} className="panel">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <p className="text-lg font-bold">{a.doctorName}</p>
              <p className="mt-1 text-sm text-muted">{a.specialty}</p>
              <p className="mt-2 text-sm">{a.startsAtLabel}</p>
            </div>
            <div className="text-left text-sm">
              <span className="badge">{a.statusLabel}</span>
              {a.amountLabel && <p className="mt-2 text-muted">{a.amountLabel}</p>}
              {a.canPay && (
                <PayAppointmentButton appointmentId={a.id} termsAccepted={termsAccepted} />
              )}
              {a.canCancel && (
                <div className="mt-3">
                  <button
                    type="button"
                    className="btn btn-outline btn-sm"
                    onClick={() => cancelAppointment(a.id)}
                  >
                    لغو نوبت
                  </button>
                  {a.status === "CONFIRMED" && a.isPaid && (
                    <p className="mt-2 max-w-[14rem] text-xs text-muted">
                      {refundHint(a.startsAtIso)}
                    </p>
                  )}
                </div>
              )}
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
