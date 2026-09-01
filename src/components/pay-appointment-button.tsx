"use client";

import { useState } from "react";

export function PayAppointmentButton({
  appointmentId,
  termsAccepted = false,
}: {
  appointmentId: string;
  termsAccepted?: boolean;
}) {
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  async function pay() {
    setError("");
    if (!termsAccepted) {
      setError("لطفاً شرایط رزرو و پرداخت را بپذیرید.");
      return;
    }
    setLoading(true);
    const res = await fetch("/api/appointments/pay", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ appointmentId, acceptTerms: true }),
    });
    const data = await res.json();
    setLoading(false);

    if (!res.ok) {
      setError(data.error || "پرداخت ناموفق بود");
      return;
    }

    if (data.paymentUrl) {
      window.location.href = data.paymentUrl;
    }
  }

  return (
    <div className="mt-3">
      <button
        type="button"
        className="btn btn-primary btn-sm"
        onClick={pay}
        disabled={loading || !termsAccepted}
      >
        {loading ? "در حال اتصال..." : "پرداخت آنلاین"}
      </button>
      {error && <p className="mt-2 text-sm text-danger">{error}</p>}
    </div>
  );
}
