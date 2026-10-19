from pathlib import Path
from PIL import Image

root = Path('cpanel')
def edit(name, old, new):
    path = root / name
    content = path.read_text(encoding='utf-8')
    assert old in content, (name, old)
    path.write_text(content.replace(old, new), encoding='utf-8')

edit('includes/clinic_directory.php', 'هزینه فقط هنگام انتخاب ساعت دیده می‌شود.', 'تاریخ، ساعت و نوع جلسه را در صفحه درمانگر انتخاب می‌کنی.')
edit('includes/clinic_directory.php', 'هزینه فقط بعد از انتخاب ساعت رزرو دیده می‌شود.', 'زمان‌های آزاد و نوع جلسه در صفحه درمانگر مشخص است.')
edit('includes/seo.php', "url('/assets/img/hero.png')", "url('/assets/img/hero.webp')")

for key, title in [('individual', 'مشاوره فردی'), ('couples', 'زوج‌درمانی'), ('child', 'مشاوره کودک و نوجوان'), ('premarital', 'مشاوره پیش از ازدواج'), ('family', 'خانواده‌درمانی')]:
    edit('pages/service_' + key + '.php', "    <?= service_related_articles_html($pdo, '" + key + "') ?>", "    <?= service_local_booking_html('" + title + "', $doctorsHref) ?>\n\n    <?= service_related_articles_html($pdo, '" + key + "') ?>")
    if key in ('couples', 'individual', 'child', 'premarital', 'family'):
        p = root / ('pages/service_' + key + '.php')
        content = p.read_text(encoding='utf-8')
        first = content.index('$pageTitle = ')
        last = content.index(';', first)
        content = content[:first] + "$pageTitle = '" + title + " در سعادت‌آباد | مانا کلینیک'" + content[last:]
        p.write_text(content, encoding='utf-8')

im = Image.open(root / 'assets/img/hero.png').convert('RGB')
im.save(root / 'assets/img/hero.webp', 'WEBP', quality=82, method=6)
im.thumbnail((768, 512))
im.save(root / 'assets/img/hero-mobile.webp', 'WEBP', quality=80, method=6)
for name in ('hero.png', 'hero.webp', 'hero-mobile.webp'):
    path = root / 'assets/img' / name
    print(name, path.stat().st_size)
