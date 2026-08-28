"use client";

import Link from "next/link";
import { signIn } from "next-auth/react";
import { useRouter } from "next/navigation";
import { FormEvent, useState } from "react";

export default function RegisterPage() {
  const router = useRouter();
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    const payload = {
      name: String(form.get("name")),
      email: String(form.get("email")),
      phone: String(form.get("phone")),
      password: String(form.get("password")),
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
          <h1 className="text-2xl font-bold">ثبت‌نام بیمار</h1>
          <p className="mt-2 text-sm text-muted">
            قبلاً ثبت‌نام کرده‌اید؟{" "}
            <Link href="/login" className="font-semibold text-primary">
              ورود
            </Link>
          </p>
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
        <button type="submit" className="btn btn-primary w-full" disabled={loading}>
          {loading ? "در حال ثبت‌نام..." : "ایجاد حساب"}
        </button>
      </form>
    </div>
  );
}
