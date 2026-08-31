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
        <div className="section-head tests-rail-head">
          <div>
            <h2>
              <Link href="/tests">آزمون‌ها</Link>
            </h2>
            <p className="muted">ابزارهای خودارزیابی روانشناسی</p>
          </div>
        </div>
        <nav className="tests-rail-nav">
          {psychTestsCatalog.map((test) => {
            const href = `/tests/${test.slug}`;
            const isActive = pathname === href;

            return (
              <Link
                key={test.slug}
                href={href}
                className={`tests-chip panel card-link transition hover:-translate-y-1 hover:shadow-md${isActive ? " is-active" : ""}`}
                title={test.abbr}
              >
                <span className="tests-chip-title">{test.title}</span>
                <span className="tests-chip-desc text-muted">
                  {test.description}
                </span>
              </Link>
            );
          })}
        </nav>
      </aside>
    </div>
  );
}
