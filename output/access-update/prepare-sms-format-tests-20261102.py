from pathlib import Path
p=Path('D:/Psychology/output/access-update/test-sms-length-20261102.py');s=p.read_text('utf-8')
start=s.index('$base=');end=s.index("$out=['now'",start)
s=s[:start]+'''$messages=[
 'date_prefix'=>'در تاریخ',
 'date_words'=>'۱۱ آبان ۱۴۰۵',
 'date_numeric'=>'۱۴۰۵/۰۸/۱۱',
 'newline'=>"کاربر آزمایشی\\nگرامی",
 'long_plain'=>str_repeat('کاربر آزمایشی گرامی ',8),
 'time_ascii'=>'ساعت 09:00',
 'time_persian'=>'ساعت ۰۹:۰۰',
 'digest_fixed'=>'',
];
'''+s[end:]
s=s.replace("if($label==='long')sleep(16);",'''if($out['results'])sleep(16);
 if($label==='digest_fixed'){
  $failed=function($k)use(&$out){$j=json_decode((string)$out['results'][$k]['raw'],true);return ($j['resultCode']??null)===7;};
  $sep=$failed('newline')?' ':"\\n";
  $date=$failed('date_words')&&!$failed('date_numeric')?'۱۴۰۵/۰۸/۱۱':'۱۱ آبان ۱۴۰۵';
  $slots=['۰۹:۰۰','۱۰:۳۰','۱۲:۰۰','۱۳:۰۰','۱۴:۳۰','۱۵:۳۰','۱۷:۰۰','۱۸:۰۰','۱۹:۰۰'];
  $body='کاربر آزمایشی گرامی'.$sep.'نوبت های رزرو شده '.($failed('date_prefix')?'':'در تاریخ ').$date.' :'.$sep.implode($sep,array_map(fn($v)=>'ساعت '.$v,$slots));
 }''')
s=s.replace("'sms-length-'","'sms-format-'").replace('timeout=100','timeout=300').replace('test-sms-length-20261102.json','test-sms-format-20261102.json')
Path('D:/Psychology/output/access-update/test-sms-format-20261102.py').write_text(s,encoding='utf-8')
print('Prepared eight authorized format tests, including an adaptive full digest.')
