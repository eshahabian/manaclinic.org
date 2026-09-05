# مانا کلینیک — سایت روانشناسی با نوبت‌دهی و زرین‌پال

سایت فارسی (RTL) برای انتشار مقالات، معرفی روانشناسان، رزرو نوبت آنلاین و پرداخت با زرین‌پال.

## نقش‌ها

- **ادمین:** ساخت دکتر، مدیریت کاربران/مقالات/نوبت‌ها
- **دکتر:** پروفایل، روزهای خالی، مقالات، نوبت‌ها
- **مراجعه‌کننده:** ثبت‌نام، رزرو نوبت به نام خود، پرداخت آنلاین

## اجرا

```bash
npm install
npx prisma migrate dev --name init
npm run db:seed
npm run dev
```

باز کنید: [http://localhost:3000](http://localhost:3000)

## حساب‌های نمونه

| نقش | ایمیل | رمز |
| --- | --- | --- |
| ادمین | admin@ravansara.ir | admin123 |
| دکتر | doctor@ravansara.ir | doctor123 |
| مراجعه‌کننده | patient@ravansara.ir | patient123 |

## متغیرهای محیطی

فایل `.env` را از روی `.env.example` تنظیم کنید:

- `ZARINPAL_MERCHANT_ID` — مرچنت‌آیدی زرین‌پال
- `ZARINPAL_SANDBOX=true` — در توسعه اگر درگاه در دسترس نباشد، پرداخت آزمایشی محلی فعال می‌شود
- `NEXTAUTH_SECRET` — کلید امنیتی جلسه

## استک

Next.js 15 · Prisma · SQLite · NextAuth · Tailwind · تقویم جلالی · زرین‌پال
