"use client";

import Link from "next/link";
import { signIn } from "next-auth/react";
import { useRouter } from "next/navigation";
import { FormEvent, useState } from "react";

type AccountType = "PATIENT" | "DOCTOR";

export default function RegisterPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [loading, setLoading] = useState(false);
  const [accountType, setAccountType] = useState<AccountType>("PATIENT");

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    setSuccess("");
    const form = new FormData(e.currentTarget);
    const payload = {
      name: String(form.get("name")),
      email: String(form.get("email")),
      phone: String(form.get("phone")),
      password: String(form.get("password")),
      role: accountType,
      specialty: accountType === "DOCTOR" ? String(form.get("specialty")) : "",
    };

    const res = await fetch("/api/register", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json();
    if (!res.ok) {
      setLoading(false);
      setError(data.error || "ثبت‌نام ناموفق بود");
      return;
    }

    if (data.pendingApproval) {
      setLoading(false);
      setSuccess(data.message);
      return;
    }

    await signIn("credentials", {
      email: payload.email,
      password: payload.password,
      redirect: false,
    });
    setLoading(false);
    router.push("/dashboard");
    router.refresh();
  }

  return (
    <div className="container-page flex min-h-[70vh] items-center justify-center py-12">
      <form onSubmit={onSubmit} className="panel w-full max-w-md space-y-4">
        <div>
          <h1 className="text-2xl font-bold">ثبت‌نام</h1>
          <p className="mt-2 text-sm text-muted">
            قبلاً ثبت‌نام کرده‌اید؟{" "}
            <Link href="/login" className="font-semibold text-primary">
              ورود
            </Link>
          </p>
        </div>

        <div>
          <label className="label" htmlFor="accountType">
            نوع حساب
          </label>
          <select
            id="accountType"
            name="accountType"
            className="input"
            value={accountType}
            onChange={(e) => setAccountType(e.target.value as AccountType)}
          >
            <option value="PATIENT">مراجع</option>
            <option value="DOCTOR">درمانگر</option>
          </select>
          {accountType === "DOCTOR" && (
            <p className="mt-2 text-xs leading-6 text-muted">
              حساب درمانگر پس از بررسی و تأیید مدیر سایت فعال می‌شود.
            </p>
          )}
        </div>

        <div>
          <label className="label" htmlFor="name">
            نام و نام خانوادگی
          </label>
          <input id="name" name="name" required className="input" />
        </div>
        <div>
          <label className="label" htmlFor="email">
            ایمیل
          </label>
          <input id="email" name="email" type="email" required className="input" dir="ltr" />
        </div>
        <div>
          <label className="label" htmlFor="phone">
            موبایل
          </label>
          <input id="phone" name="phone" className="input" dir="ltr" placeholder="0912..." />
        </div>

        {accountType === "DOCTOR" && (
          <div>
            <label className="label" htmlFor="specialty">
              تخصص
            </label>
            <input
              id="specialty"
              name="specialty"
              required
              className="input"
              placeholder="مثلاً روان‌درمانی شناختی-رفتاری"
            />
          </div>
        )}

        <div>
          <label className="label" htmlFor="password">
            رمز عبور
          </label>
          <input
            id="password"
            name="password"
            type="password"
            required
            minLength={6}
            className="input"
            dir="ltr"
          />
        </div>

        {error && <p className="text-sm text-danger">{error}</p>}
        {success && (
          <div className="space-y-2 rounded-lg bg-[var(--bg-soft)] p-3 text-sm leading-7">
            <p className="text-primary">{success}</p>
            <Link href="/login" className="font-semibold text-primary underline">
              رفتن به صفحه ورود
            </Link>
          </div>
        )}

        <button type="submit" className="btn btn-primary w-full" disabled={loading || !!success}>
          {loading ? "در حال ثبت‌نام..." : accountType === "DOCTOR" ? "ارسال درخواست" : "ایجاد حساب"}
        </button>
      </form>
    </div>
  );
}
