"""Extract the clinic-authorized BDI-II appendix; never generate questionnaire text."""
from pathlib import Path
import hashlib, json, re, unicodedata
import pdfplumber

root = Path('D:/Psychology')
pdf = root / 'output/bdi/source/paloalto-persian-cbt-manual.pdf'
doc = pdfplumber.open(pdf)
lines = []
def clean_typography(text):
    # Repair PDF visual-text extraction artifacts, without changing option wording.
    for old, new in [('جمالت','جملات'),('باالتر','بالاتر'),('عالقه','علاقه'),('کامال','کاملاً'),
                     ('اصال','اصلاً'),('قبال','قبلاً'),('الزم','لازم'),('طوالنی','طولانی'),
                     ('نم ی','نمی\u200c'),('م ی','می\u200c'),('ب ی','بی\u200c'),
                     ('کرد هاید','کرده\u200cاید'),('شمار های','شماره\u200cای')]:
        text = text.replace(old, new)
    return text
for page in doc.pages[145:151]:
    # Exclude the running header. Reverse visual-order text back into logical Persian.
    cropped = page.crop((0, page.height * 0.1, page.width, page.height))
    for line in cropped.extract_text().splitlines():
        logical = unicodedata.normalize('NFKC', line[::-1]).strip()
        logical = re.sub(r'[۰-۹0-9]+', lambda m: m[0][::-1], logical)
        lines.append(clean_typography(logical))

items = {}
current = None
intro = []
for line in lines:
    match = re.match(r'^([۰-۹0-9]{1,2})\s*[-ـ–]\s*(.+?)\s*[:؛]?$', line)
    if match:
        number = int(match[1].translate(str.maketrans('۰۱۲۳۴۵۶۷۸۹', '0123456789')))
        assert number == len(items) + 1, ('Unexpected item sequence', number)
        current = {'text': match[2].rstrip(':؛').strip(), 'raw': []}
        items[number] = current
    elif current is not None:
        current['raw'].append(line)
    else:
        intro.append(line)

assert list(items) == list(range(1, 22))
for number, item in items.items():
    options = []
    for line in item.pop('raw'):
        mark = re.match(r'^(الف|ب|ج|د)\s*[):]\s*(.*)$', line)
        if mark:
            options.append(mark[2])
        elif not options and number in (16, 18):
            options.append(line)
        elif options:
            options[-1] += ' ' + line
        else:
            raise ValueError(('Unrecognized option boundary', number))
    assert len(options) == (7 if number in (16, 18) else 4), (number, len(options))
    weights = [0, 1, 1, 2, 2, 3, 3] if number in (16, 18) else [0, 1, 2, 3]
    item['options'] = [{'score': weight, 'text': ' '.join(text.split())} for weight, text in zip(weights, options)]
    assert all(option['text'] for option in item['options'])

# Preserve the authorized source instructions, excluding the appendix title and English heading.
start = next(i for i, line in enumerate(intro) if line.startswith('این پرسشنامه'))
instructions = ' '.join(intro[start:])
assert ('۲' in instructions or '2' in instructions) and 'هفته' in instructions
form = {'version': 'BDI-II', 'online_use_authorized': True,
        'source': 'پیوست راهنمای فارسی درمان شناختی ـ رفتاری افسردگی، میزبانی i4Health دانشگاه پالو آلتو؛ ترجمه راهنما: محمد قهرمانی خرم، زهره قادری و معصومه زنگنه؛ صفحات چاپی ۱۴۷ تا ۱۵۲',
        'source_url': 'https://i4health.paloaltou.edu/downloads/Mu%C3%B1oz.Miranda.CBTforDepressionManual.PersianVersion.pdf',
        'source_sha256': hashlib.sha256(pdf.read_bytes()).hexdigest(),
        'permission_basis': 'Clinic owner confirmed permission and instructed downloading the form from the internet on 2026-10-21.',
        'instructions': instructions, 'items': items}
content = json.dumps(form, ensure_ascii=False, indent=2)
php = "<?php\ndeclare(strict_types=1);\n// Clinic-authorized source content. Direct access is disabled.\nif (!defined('MANA_BDI_CONTENT')) { http_response_code(404); exit; }\nreturn json_decode(<<<'BDI_FORM_JSON'\n" + content + "\nBDI_FORM_JSON, true, 512, JSON_THROW_ON_ERROR);\n"
(root / 'cpanel/includes/bdi_form_fa.php').write_text(php, encoding='utf-8')
(root / 'output/bdi/source/form-extracted.json').write_text(content, encoding='utf-8')
print('PASS 21 extracted items; 19 x 4 options and 2 x 7 options; source hash', form['source_sha256'])
print('Question titles:', ', '.join(str(i)+': '+item['text'] for i, item in items.items()))
