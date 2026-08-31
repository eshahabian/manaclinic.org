"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { psychTestsCatalog } from "@/lib/psych-tests";

const hiddenPrefixes = [
  "/admin",
  "/doctor",
  "/dashboard",
  "/login",
  "/register",
];

type Props = {
  children: React.ReactNode;
};

export function SiteContentShell({ children }: Props) {
  const pathname = usePathname();
  const showTestsRail = !hiddenPrefixes.some((prefix) =>
    pathname.startsWith(prefix)
  );

  if (!showTestsRail) {
    return <>{children}</>;
  }

  return (
    <div className="site-content-shell">
      <div className="site-content-main">{children}</div>
      <aside className="tests-rail" aria-label="آزمون‌های روانشناسی">
        <p className="tests-rail-title">
          <Link href="/tests">آزمون‌ها</Link>
        </p>
        <nav className="tests-rail-nav">
          {psychTestsCatalog.map((test) => {
            const href = `/tests/${test.slug}`;
            const isActive = pathname === href;

            return (
              <Link
                key={test.slug}
                href={href}
                className={`tests-chip${isActive ? " is-active" : ""}`}
                title={test.abbr}
              >
                {test.title}
              </Link>
            );
          })}
        </nav>
      </aside>
    </div>
  );
}
