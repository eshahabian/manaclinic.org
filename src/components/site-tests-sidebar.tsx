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

export function SiteTestsSidebar() {
  const pathname = usePathname();

  if (hiddenPrefixes.some((prefix) => pathname.startsWith(prefix))) {
    return null;
  }

  return (
    <aside
      className="tests-sidebar hidden xl:block"
      aria-label="آزمون‌های روانشناسی"
    >
      <div className="tests-sidebar-inner">
        <p className="tests-sidebar-title">
          <Link href="/tests">آزمون‌ها</Link>
        </p>
        <nav className="tests-sidebar-nav">
          {psychTestsCatalog.map((test) => {
            const href = `/tests/${test.slug}`;
            const isActive = pathname === href;

            return (
              <Link
                key={test.slug}
                href={href}
                className={`tests-sidebar-link${isActive ? " is-active" : ""}`}
                title={test.abbr}
              >
                <span className="tests-sidebar-link-title">{test.title}</span>
                <span className="tests-sidebar-link-abbr">{test.abbr}</span>
              </Link>
            );
          })}
        </nav>
      </div>
    </aside>
  );
}
