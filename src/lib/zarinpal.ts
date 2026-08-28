const SANDBOX = process.env.ZARINPAL_SANDBOX === "true";

const BASE = SANDBOX
  ? "https://sandbox.zarinpal.com/pg/v4/payment"
  : "https://payment.zarinpal.com/pg/v4/payment";

const START_PAY = SANDBOX
  ? "https://sandbox.zarinpal.com/pg/StartPay"
  : "https://www.zarinpal.com/pg/StartPay";

export type ZarinpalRequestResult = {
  authority: string;
  paymentUrl: string;
};

export async function zarinpalRequest(params: {
  amount: number;
  description: string;
  callbackUrl: string;
  email?: string;
  mobile?: string;
}): Promise<ZarinpalRequestResult> {
  const merchant_id = process.env.ZARINPAL_MERCHANT_ID;
  if (!merchant_id) {
    throw new Error("ZARINPAL_MERCHANT_ID تنظیم نشده است");
  }

  // مبلغ زرین‌پال به ریال است؛ قیمت‌های سایت تومان هستند
  const amountRial = params.amount * 10;

  const res = await fetch(`${BASE}/request.json`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      merchant_id,
      amount: amountRial,
      callback_url: params.callbackUrl,
      description: params.description,
      metadata: {
        email: params.email,
        mobile: params.mobile,
      },
    }),
  });

  const data = await res.json();
  if (data?.data?.authority) {
    return {
      authority: data.data.authority,
      paymentUrl: `${START_PAY}/${data.data.authority}`,
    };
  }

  // در حالت sandbox اگر درگاه در دسترس نبود، پرداخت آزمایشی محلی
  if (SANDBOX) {
    const fakeAuthority = `DEV${Date.now()}`;
    const appUrl = process.env.APP_URL || "http://localhost:3000";
    return {
      authority: fakeAuthority,
      paymentUrl: `${appUrl}/api/payments/verify?Authority=${fakeAuthority}&Status=OK&dev=1`,
    };
  }

  throw new Error(
    data?.errors?.message || "خطا در اتصال به زرین‌پال"
  );
}

export async function zarinpalVerify(params: {
  authority: string;
  amount: number;
}): Promise<{ ok: boolean; refId?: string; message?: string }> {
  const merchant_id = process.env.ZARINPAL_MERCHANT_ID;
  if (!merchant_id) {
    throw new Error("ZARINPAL_MERCHANT_ID تنظیم نشده است");
  }

  if (params.authority.startsWith("DEV")) {
    return { ok: true, refId: String(Date.now()) };
  }

  const amountRial = params.amount * 10;
  const res = await fetch(`${BASE}/verify.json`, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      merchant_id,
      amount: amountRial,
      authority: params.authority,
    }),
  });

  const data = await res.json();
  const code = data?.data?.code;
  if (code === 100 || code === 101) {
    return { ok: true, refId: String(data.data.ref_id) };
  }

  return {
    ok: false,
    message: data?.errors?.message || "تأیید پرداخت ناموفق بود",
  };
}
