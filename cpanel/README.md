# مانا کلینیک — نسخه PHP برای هاست cPanel (بدون Node.js)

این پوشه (`cpanel/`) را روی هاست لینوکس/وردپرس آپلود کنید. نیازی به نصب وردپرس نیست.

## مراحل نصب

1. در cPanel یک **MySQL Database** و **User** بسازید و به هم وصل کنید.
2. محتویات پوشه `cpanel` را داخل `public_html` آپلود کنید  
   (یعنی `index.php` و `.htaccess` مستقیم داخل `public_html` باشند).
3. فایل `config.php` را باز کنید و این‌ها را پر کنید:
   - `db_name` / `db_user` / `db_pass`
   - `app_url` مثلاً `https://manaclinic.org`
4. در مرورگر باز کنید: `https://manaclinic.org/install`
5. بعد از موفقیت، فایل `install.php` را از هاست **حذف** کنید.

## حساب‌های نمونه (بعد از `/install`)

| نقش | نام کاربری | رمز اولیه |
|---|---|---|
| ادمین | `admin` | `123` |
| دکتر | `doctor` | `123` |
| منشی | `secretary` | `123` |
| مراجعه‌کننده | `patient` | `123` |

بعد از اولین ورود، سایت اجبار می‌کند رمز را عوض کنید.

## زرین‌پال

در `config.php`:
- `zarinpal_merchant_id` را بگذارید
- برای تست: `zarinpal_sandbox => true`
- برای واقعی: `zarinpal_sandbox => false`

## نیازمندی‌ها

- PHP 8.0+ (ترجیحاً 8.1/8.2)
- MySQL / MariaDB
- افزونه PDO MySQL
- mod_rewrite (برای `.htaccess`)

## نکته

پروژه Next.js قبلی برای توسعه محلی است.  
برای هاست بدون Node، **همین نسخه PHP** را استفاده کنید.
