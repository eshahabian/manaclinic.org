from pathlib import Path
p=Path('D:/Psychology/output/access-update/test-sms-length-20261102.py')
s=p.read_text('utf-8')
start=s.index('$base=')
end=s.index("$out=['now'",start)
s=s[:start]+'''$messages=[
 'baseline'=>'مانا کلینیک: بررسی فنی ارسال پیامک ۱',
 'greeting'=>'کاربر آزمایشی گرامی',
 'reservation'=>'نوبت های رزرو شده',
 'date'=>'در تاریخ ۱۱ آبان ۱۴۰۵',
 'replacement_long'=>"مانا کلینیک: برنامه نوبت‌ها ۱۴۰۵/۰۸/۱۱\\nساعت 09:00\\nساعت 10:30\\nساعت 12:00\\nساعت 13:00\\nساعت 14:30\\nساعت 15:30\\nساعت 17:00\\nساعت 18:00\\nساعت 19:00",
 'confirmation'=>'',
];
'''+s[end:]
s=s.replace("if($label==='long')sleep(16);",'''if($out['results'])sleep(16);
 if($label==='confirmation'){
  $failed=[];foreach(['greeting','reservation','date'] as $key){$j=json_decode((string)$out['results'][$key]['raw'],true);if(($j['resultCode']??null)===7)$failed[]=$key;}
  if($failed===['date'])$body='آبان';
  elseif($failed===['reservation'])$body='رزرو';
  elseif($failed===['greeting'])$body='آزمایشی';
  else $body='کاربر آزمایشی گرامی نوبت های رزرو شده در تاریخ ۱۱ آبان ۱۴۰۵ :';
 }''')
s=s.replace("'sms-length-'","'sms-content-'").replace('timeout=100','timeout=240').replace('test-sms-length-20261102.json','test-sms-content-20261102.json')
# Mark every request in the local result before starting the next test.
s=s.replace("$out['results'][$label]=", "$out['results'][$label]=")
Path('D:/Psychology/output/access-update/test-sms-content-20261102.py').write_text(s,encoding='utf-8')
print('Prepared six explicitly authorized content tests.')
