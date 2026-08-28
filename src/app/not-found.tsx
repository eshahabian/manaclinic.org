import Link from "next/link";

export default function NotFound() {
  return (
    <div className="container-page flex min-h-[60vh] flex-col items-center justify-center text-center">
      <h1 className="text-3xl font-bold">صفحه پیدا نشد</h1>
      <p className="mt-3 text-muted">آدرس واردشده معتبر نیست.</p>
      <Link href="/" className="btn btn-primary mt-6">
        بازگشت به خانه
      </Link>
    </div>
  );
}
