import Link from "next/link";

type Item = { href: string; label: string };

export function PanelNav({
  title,
  items,
}: {
  title: string;
  items: Item[];
}) {
  return (
    <aside className="panel h-fit md:sticky md:top-24">
      <p className="mb-4 text-lg font-bold text-primary">{title}</p>
      <nav className="flex flex-col gap-2">
        {items.map((item) => (
          <Link
            key={item.href}
            href={item.href}
            className="rounded-lg px-3 py-2 text-sm text-muted transition hover:bg-[var(--bg-soft)] hover:text-primary"
          >
            {item.label}
          </Link>
        ))}
      </nav>
    </aside>
  );
}
