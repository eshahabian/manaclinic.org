"use client";

import { useState } from "react";
import { BookingTermsAcceptance } from "@/components/booking-terms-acceptance";
import { PayAppointmentButton } from "@/components/pay-appointment-button";

export function AppointmentsPaymentPanel({
  appointments,
}: {
  appointments: Array<{
    id: string;
    doctorName: string;
    specialty: string;
    startsAtLabel: string;
    statusLabel: string;
    amountLabel: string | null;
    canPay: boolean;
  }>;
}) {
  const [termsAccepted, setTermsAccepted] = useState(false);
  const hasPendingPay = appointments.some((a) => a.canPay);

  return (
    <div className="space-y-3">
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
            </div>
          </div>
        </div>
      ))}
    </div>
  );
}
