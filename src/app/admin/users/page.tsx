import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatJalaliDate, roleLabel } from "@/lib/utils";

export default async function AdminUsersPage() {
  await requireUser(["ADMIN"]);
  const users = await prisma.user.findMany({
    orderBy: { createdAt: "desc" },
    include: { doctorProfile: true },
  });

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">کاربران</h1>
      <div className="overflow-x-auto panel p-0">
        <table className="w-full min-w-[640px] text-sm">
          <thead className="bg-[var(--bg-soft)] text-muted">
            <tr>
              <th className="px-4 py-3 text-right font-medium">نام</th>
              <th className="px-4 py-3 text-right font-medium">ایمیل</th>
              <th className="px-4 py-3 text-right font-medium">نقش</th>
              <th className="px-4 py-3 text-right font-medium">عضویت</th>
            </tr>
          </thead>
          <tbody>
            {users.map((u) => (
              <tr key={u.id} className="border-t border-line">
                <td className="px-4 py-3">{u.name}</td>
                <td className="px-4 py-3" dir="ltr">
                  {u.email}
                </td>
                <td className="px-4 py-3">{roleLabel[u.role]}</td>
                <td className="px-4 py-3">{formatJalaliDate(u.createdAt)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
