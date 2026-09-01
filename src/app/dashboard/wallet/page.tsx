import { prisma } from "@/lib/prisma";
import { requireUser } from "@/lib/session";
import { formatJalaliDate, formatPrice } from "@/lib/utils";

const kindLabels: Record<string, string> = {
  TOPUP: "شارژ",
  PAYMENT: "پرداخت",
  REFUND: "بازگشت وجه",
  HOLD: "امانی",
  RELEASE: "آزادسازی امانی",
  SETTLE: "تسویه",
  ADJUSTMENT: "تعدیل",
};

export default async function PatientWalletPage() {
  const session = await requireUser(["PATIENT"]);

  let wallet = await prisma.wallet.findUnique({
    where: { userId: session.user.id },
    include: {
      transactions: { orderBy: { createdAt: "desc" }, take: 30 },
    },
  });

  if (!wallet) {
    wallet = await prisma.wallet.create({
      data: { userId: session.user.id },
      include: {
        transactions: { orderBy: { createdAt: "desc" }, take: 30 },
      },
    });
  }

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">کیف پول</h1>
      <p className="text-muted">موجودی برای پرداخت کارگاه‌ها و بازگشت وجه لغو قبل از ۲۴ ساعت.</p>

      <div className="panel grid gap-4 sm:grid-cols-2">
        <div>
          <p className="text-sm text-muted">موجودی قابل استفاده</p>
          <p className="mt-1 text-2xl font-bold text-primary">{formatPrice(wallet.balance)}</p>
        </div>
        <div>
          <p className="text-sm text-muted">امانی (در انتظار تسویه)</p>
          <p className="mt-1 text-lg font-bold">{formatPrice(wallet.heldBalance)}</p>
        </div>
      </div>

      <section className="panel">
        <h2 className="mb-4 text-lg font-bold">آخرین تراکنش‌ها</h2>
        {wallet.transactions.length === 0 ? (
          <p className="text-muted">تراکنشی ثبت نشده است.</p>
        ) : (
          <div className="space-y-2">
            {wallet.transactions.map((t) => (
              <div key={t.id} className="flex flex-wrap justify-between gap-2 border-t border-line pt-2 text-sm">
                <div>
                  <span>{kindLabels[t.kind] ?? t.kind}</span>
                  {t.description && <span className="mr-2 text-muted"> — {t.description}</span>}
                </div>
                <div className="text-left">
                  <span className={t.amount >= 0 ? "text-success" : "text-danger"}>
                    {t.amount >= 0 ? "+" : ""}
                    {formatPrice(Math.abs(t.amount))}
                  </span>
                  <span className="mr-2 text-muted">{formatJalaliDate(t.createdAt)}</span>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  );
}
