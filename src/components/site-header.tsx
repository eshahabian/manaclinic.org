import Link from "next/link";
import { getSession } from "@/lib/session";
import { SignOutButton } from "@/components/sign-out-button";

export async function SiteHeader() {
  const session = await getSession();
  const role = session?.user?.role;

  const panelHref =
    role === "ADMIN"
      ? "/admin"
      : role === "DOCTOR"
        ? "/doctor"
        : role === "PATIENT"
          ? "/dashboard"
          : null;

  return (
    <header className="sticky top-0 z-40 border-b border-line/70 bg-[rgba(243,246,244,0.88)] backdrop-blur-md">
      <div className="container-page flex h-16 items-center justify-between gap-4">
        <Link href="/" className="text-xl font-bold tracking-tight text-primary">
          مانا کلینیک
        </Link>
        <nav className="hidden items-center gap-6 text-sm text-muted md:flex">
          <Link href="/doctors" className="hover:text-primary">
            متخصصان
          </Link>
          <Link href="/articles" className="hover:text-primary">
            مقالات
          </Link>
          {panelHref && (
            <Link href={panelHref} className="hover:text-primary">
              پنل من
            </Link>
          )}
        </nav>
        <div className="flex items-center gap-2">
          {session?.user ? (
            <>
              <span className="hidden text-sm text-muted sm:inline">
                {session.user.name}
              </span>
              <SignOutButton />
            </>
          ) : (
            <>
              <Link href="/login" className="btn btn-outline !py-2 !text-sm">
                ورود
              </Link>
              <Link href="/register" className="btn btn-primary !py-2 !text-sm">
                ثبت‌نام
              </Link>
            </>
          )}
        </div>
      </div>
    </header>
  );
}
