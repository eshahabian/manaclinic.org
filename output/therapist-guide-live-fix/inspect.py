from pathlib import Path
import ftplib,io,json,urllib.request,re,time
root=Path.cwd(); cfg=json.loads((root/'.vscode/sftp.json').read_text(encoding='utf-8-sig'))
folder=root/'output/therapist-guide-live-fix'/('before-'+time.strftime('%Y%m%d-%H%M%S'));folder.mkdir()
files=['index.php','includes/home_therapist_guide.php','includes/staff_app.php','pages/staff_app.php','assets/js/therapist-guide.js','includes/therapist_guide_questions.php','includes/therapist_guide_results.php','actions/therapist_guide_submit.php','pages/staff_therapist_guide.php']
ftp=ftplib.FTP(cfg['host'],timeout=40);ftp.login(cfg['username'],cfg['password']);ftp.cwd('/public_html')
report={}
for name in files:
 out=io.BytesIO()
 try:ftp.retrbinary('RETR '+name,out.write)
 except ftplib.error_perm as e:
  if str(e).startswith('550'):report[name]={'exists':False};continue
  raise
 data=out.getvalue();(folder/name.replace('/','_')).write_bytes(data)
 report[name]={'exists':True,'same_as_local':data==(root/'cpanel'/name).read_bytes()}
ftp.quit()
with urllib.request.urlopen('https://manaclinic.org/?guide-inspect='+str(time.time_ns()),timeout=40) as r:html=r.read().decode('utf-8-sig')
m=re.search(r'<script type="application/json" id="mana-guide-data">(.*?)</script>',html,re.S);payload=json.loads(m.group(1)) if m else {}
report['live']={'data_keys':list(payload),'script':re.findall(r'<script[^>]+src="([^"]*therapist-guide[^\"]*)"',html)}
(folder/'report.json').write_text(json.dumps(report,indent=2),encoding='utf-8')
print(str(folder));print(json.dumps(report,indent=2))
