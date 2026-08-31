import Link from "next/link";

export function SiteFooter() {
  return (
    <footer className="mt-20 border-t border-line bg-[#eef4f0]">
      <div className="container-page grid gap-8 py-12 md:grid-cols-3">
        <div>
          <p className="text-xl font-bold text-primary">مانا کلینیک</p>
          <p className="mt-2 text-sm leading-7 text-muted">
            فضای امن برای یادگیری، رشد و دریافت خدمات روانشناسی آنلاین.
          </p>
        </div>
        <div>
          <p className="mb-3 font-semibold">دسترسی سریع</p>
          <div className="flex flex-col gap-2 text-sm text-muted">
            <Link href="/doctors">متخصصان</Link>
            <Link href="/articles">مقالات</Link>
            <Link href="/tests">آزمون‌ها</Link>
            <Link href="/register">ثبت‌نام</Link>
          </div>
        </div>
        <div>
          <p className="mb-3 font-semibold">تماس</p>
          <p className="text-sm leading-7 text-muted">
            ایمیل: info@manaclinic.ir
            <br />
            پشتیبانی همه روزه ۹ تا ۱۸
          </p>
        </div>
      </div>
      <div className="border-t border-line py-4 text-center text-xs text-muted">
        © {new Date().getFullYear()} مانا کلینیک — همه حقوق محفوظ است.
      </div>
    </footer>
  );
}
