"use client";

import Link from "next/link";
import { signIn } from "next-auth/react";
import { useRouter, useSearchParams } from "next/navigation";
import { FormEvent, Suspense, useState } from "react";

function LoginForm() {
  const router = useRouter();
  const search = useSearchParams();
  const callbackUrl = search.get("callbackUrl") || "/";
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  async function onSubmit(e: FormEvent<HTMLFormElement>) {
    e.preventDefault();
    setLoading(true);
    setError("");
    const form = new FormData(e.currentTarget);
    const res = await signIn("credentials", {
      email: String(form.get("email")),
      password: String(form.get("password")),
      redirect: false,
    });
    setLoading(false);
    if (res?.error) {
      if (res.error === "PENDING_APPROVAL") {
        setError("حساب درمانگر شما هنوز توسط مدیر سایت تأیید نشده است.");
      } else {
        setError("ایمیل یا رمز عبور نادرست است.");
      }
      return;
    }

    if (callbackUrl && callbackUrl !== "/") {
      router.push(callbackUrl);
    } else {
      const sessionRes = await fetch("/api/auth/session");
      const session = await sessionRes.json();
      const role = session?.user?.role;
      if (role === "ADMIN") router.push("/admin");
      else if (role === "DOCTOR") router.push("/doctor");
      else if (role === "PATIENT") router.push("/dashboard");
      else router.push("/");
    }
    router.refresh();
  }

  return (
    <div className="container-page flex min-h-[70vh] items-center justify-center py-12">
      <form onSubmit={onSubmit} className="panel w-full max-w-md space-y-4">
        <div>
          <h1 className="text-2xl font-bold">ورود به مانا کلینیک</h1>
          <p className="mt-2 text-sm text-muted">
            حساب ندارید؟{" "}
            <Link href="/register" className="font-semibold text-primary">
              ثبت‌نام
            </Link>
          </p>
        </div>
        <div>
          <label className="label" htmlFor="email">
            ایمیل
          </label>
          <input
            id="email"
            name="email"
            type="email"
            required
            className="input"
            dir="ltr"
          />
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
            className="input"
            dir="ltr"
          />
        </div>
        {error && <p className="text-sm text-danger">{error}</p>}
        <button type="submit" className="btn btn-primary w-full" disabled={loading}>
          {loading ? "در حال ورود..." : "ورود"}
        </button>
        <p className="rounded-lg bg-[var(--bg-soft)] p-3 text-xs leading-6 text-muted">
          نمونه: admin@ravansara.ir / admin123 — doctor@ravansara.ir / doctor123 —
          patient@ravansara.ir / patient123
        </p>
      </form>
    </div>
  );
}

export default function LoginPage() {
  return (
    <Suspense>
      <LoginForm />
    </Suspense>
  );
}
