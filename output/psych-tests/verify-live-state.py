from pathlib import Path
import json,re,urllib.request,urllib.parse,urllib.error,http.cookiejar
root=Path('D:/Psychology');base='https://manaclinic.org'
client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
html=client.open(base+'/tests/bdi',timeout=60).read().decode('utf-8-sig')
token=re.search(r'name="_csrf" value="([^"]+)"',html).group(1)
assert 'data-bdi-questionnaire' in html and html.count('class="bdi-option"')==90
def post(slug, data):
 data=dict(data);data['_csrf']=token
 req=urllib.request.Request(base+'/tests/'+slug,data=urllib.parse.urlencode(data).encode(),headers={'Accept':'application/json','Referer':base+'/tests/'+slug})
 try:r=client.open(req,timeout=60)
 except urllib.error.HTTPError as ex:r=ex
 return r.status,r.read().decode('utf-8-sig')
choices={i:0 for i in range(1,22)}
choices.update({1:3,2:3,3:3,4:3,5:3,6:3,7:2})
data={'adult':'1','bdi_action':'score'};data.update({'choices['+str(i)+']':n for i,n in choices.items()})
code,html=post('bdi',data)
assert code==200 and 'bdi-band">متوسط' in html and '>۲۰</text>' in html and 'bdi-report-grid' in html and 'ورود برای تحلیل' in html
assert 'no-store' in client.open(base+'/tests/bdi',timeout=60).headers.get('Cache-Control','')
bad=dict(data);del bad['choices[21]'];assert post('bdi',bad)[0]==422
sensitive=dict(data);sensitive['choices[9]']=1
code,html=post('bdi',sensitive);assert code==200 and 'همراهی انسانی در اولویت است' in html and 'data-bdi-ai>' not in html
ai={'adult':'1','bdi_action':'ai','ai_consent':'1'};ai.update({'answers['+str(i)+']':n for i,n in choices.items()})
assert post('bdi',ai)[0]==401
for slug in ['bai','dass-21','ghq','scl-90','pss','neo','swls','mmpi','ybocs']:
 code,body=post(slug,{'assessment_action':'ai','adult':'1','ai_consent':'1','existing_form':'1'})
 assert code==404 and json.loads(body)['error']=='این آزمون هنوز فعال نشده است.',slug
result='PASS BDI live full-form scoring, chart, missing-answer validation, safety handling and AI login gate; other nine POST/AI endpoints blocked'
(root/'output/psych-tests/live-state-verification.txt').write_text(result,encoding='utf-8')
print(result)
