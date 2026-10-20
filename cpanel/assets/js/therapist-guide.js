(() => {
  'use strict';
  const dialog = document.getElementById('mana-guide-dialog');
  const root = document.getElementById('mana-guide-content');
  const trigger = document.querySelector('[data-guide-start]');
  if (!dialog || !root || !trigger || !dialog.showModal) return;
  const data = JSON.parse(document.getElementById('mana-guide-data').textContent);
  const questions = [
    {key:'domain', title:'برای چه کسی و چه موضوعی دنبال کمک هستی؟', options:[['individual','برای حال خودم'],['child','برای فرزندم'],['couples','برای رابطه عاطفی یا زندگی مشترک'],['premarital','برای تصمیم پیش از ازدواج'],['family','برای مسائل خانوادگی']]},
    {key:'age', title:'سن کسی که قرار است مشاوره بگیرد چقدر است؟', options:[['child','زیر ۱۲ سال'],['teen','۱۲ تا ۱۷ سال'],['adult','۱۸ سال یا بیشتر']]},
    {key:'focus', multi:true, title:'این روزها چه چیزهایی بیشتر ذهنت را درگیر کرده؟', hint:'می‌توانی چند گزینه انتخاب کنی؛ در مرحلهٔ بعد اصلی‌ترین دغدغه را مشخص می‌کنی.', options:[['anxiety','نگرانی و دلشوره'],['panic','حملات ترس و اضطراب شدید'],['depression','افت انرژی، انگیزه یا لذت'],['ocd','افکار مزاحم یا رفتارهای تکراری'],['relationships','چالش در روابط عاطفی'],['grief','سوگ، فقدان یا تغییر مهم زندگی'],['trauma','تجربه‌های آسیب‌زا'],['growth','خودشناسی، اعتمادبه‌نفس و رشد فردی'],['other','مسئلهٔ دیگری دارم یا هنوز مطمئن نیستم']]},
    {key:'primary', title:'دوست داری ابتدا روی کدام موضوع کار کنی؟', options:[]},
    {key:'impact', title:'این موضوع چقدر روی زندگی روزمره‌ات اثر گذاشته؟', options:[['mild','گاهی پیش می‌آید و معمولاً از پسش برمی‌آیم'],['medium','تکرار می‌شود و ذهنم را درگیر می‌کند'],['high','روی خواب، کار، تحصیل یا روابطم اثر گذاشته'],['severe','مدتی است به‌تنهایی از پس آن برنمی‌آیم']]},
    {key:'goal', title:'الان از این مسیر چه انتظاری داری؟', options:[['therapy','گفت‌وگوی شخصی و کمک برای مسئله‌ام'],['learn','یادگیری مهارت و شرکت در دوره یا کارگاه'],['unsure','هنوز نمی‌دانم؛ برای شروع راهنمایی می‌خواهم']]}
  ];
  let step = 0, answers = {};
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; };
  const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  function button(text, action, cls='btn btn-outline') { const b=el('button',text,cls); b.type='button'; b.addEventListener('click',action); return b; }
  function link(text, href, cls='mana-guide-result') { const a=el('a',text,cls); a.href=href; return a; }
  function focusTitle() { root.querySelector('h2').focus(); }
  function render() {
    root.replaceChildren();
    const q = questions[step];
    if (q.key === 'primary') q.options = questions[2].options.filter(([key]) => (answers.focus || []).includes(key));
    root.append(el('p',`سؤال ${fa(step+1)} از ${fa(questions.length)}`,'mana-guide-small'));
    const progress=el('progress',null,'mana-guide-progress'); progress.max=questions.length; progress.value=step+1; progress.setAttribute('aria-label','پیشرفت راهنما'); root.append(progress);
    const title=el('h2',q.title); title.id='mana-guide-dialog-title'; title.tabIndex=-1; root.append(title);
    if(q.hint) root.append(el('p',q.hint,'mana-guide-small'));
    const fields=el('fieldset',null,'mana-guide-options'); fields.setAttribute('aria-labelledby',title.id);
    q.options.forEach(([value,label]) => { const item=el('label',null,'mana-guide-option'); const input=el('input'); input.type=q.multi?'checkbox':'radio'; input.name=q.key; input.value=value; input.checked=q.multi?(answers[q.key]||[]).includes(value):answers[q.key]===value; item.append(input,el('span',label)); fields.append(item); });
    root.append(fields);
    const error=el('p',null,'mana-guide-error'); error.setAttribute('role','alert'); root.append(error);
    const nav=el('div',null,'mana-guide-actions');
    nav.append(button(step===questions.length-1?'دیدن مسیر پیشنهادی':'ادامه',() => {
      const selected=Array.from(fields.querySelectorAll('input:checked')).map(i=>i.value);
      if(!selected.length) { error.textContent='برای ادامه یک گزینه انتخاب کن.'; return; }
      answers[q.key]=q.multi?selected:selected[0];
      if(q.key==='focus' && !selected.includes(answers.primary)) delete answers.primary;
      if(step===questions.length-1) result(); else { step++; render(); }
    },'btn btn-primary'));
    if(step>0) nav.append(button('قبلی',()=>{step--;render();}));
    root.append(nav,el('p','این راهنما تشخیص یا ارزیابی بالینی نیست. در خطر فوری، به خدمات اورژانسی محل زندگی‌ات مراجعه کن.','mana-guide-small'));
    focusTitle();
  }
  function result() {
    root.replaceChildren();
    const title=el('h2','یک نقطهٔ شروع برای تو'); title.id='mana-guide-dialog-title'; title.tabIndex=-1; root.append(title);
    const domain = answers.age!=='adult' && answers.domain==='individual' ? 'child' : answers.domain;
    const focus=answers.primary==='other'?'':answers.primary;
    root.append(el('p',`مسیر پیشنهادی: ${data.domains[domain]}${focus ? ' · '+data.focus[focus] : ''}`));
    root.append(el('p','این پیشنهاد از موضوع مراجعه و دغدغهٔ اصلی تو ساخته شده است. گروه سنی دقیق، شیوهٔ جلسه و تناسب نهایی را پیش از رزرو با کلینیک هماهنگ کن.','mana-guide-small'));
    const matches=data.doctors.filter(doc=>doc.domains.includes(domain) && (!focus || doc.focus.includes(focus)));
    if(matches.length) {
      root.append(el('p','درمانگران دارای حوزهٔ ثبت‌شدهٔ مرتبط:'));
      matches.slice(0,3).forEach(doc=>{const a=link(doc.name,doc.href); a.append(el('span',`مشاهدهٔ پروفایل · ${data.domains[domain]}${focus ? ' · '+data.focus[focus] : ''}`));root.append(a);});
    } else root.append(el('p','در فهرست فعلی، درمانگری با این ترکیب حوزه‌های ثبت‌شده پیدا نشد. برای راهنمایی بیشتر با کلینیک هماهنگ کن یا همهٔ درمانگران را ببین.'));
    if(answers.impact==='high'||answers.impact==='severe') root.append(el('p','چون این موضوع روی زندگی‌ات اثر قابل‌توجهی گذاشته، گفت‌وگو با متخصص را در اولویت بگذار. کارگاه جایگزین ارزیابی فردی نیست.'));
    if(answers.goal!=='therapy') {
      const words={anxiety:['اضطراب','نگرانی'],panic:['پانیک'],depression:['افسردگی'],ocd:['وسواس'],relationships:['رابطه','روابط','زوج'],grief:['سوگ','فقدان'],trauma:['تروما','آسیب'],growth:['خودشناسی','رشد فردی','اعتمادبه‌نفس']};
      const domainWords={child:['کودک','نوجوان','فرزند'],couples:['زوج','رابطه','روابط'],premarital:['ازدواج'],family:['خانواده']};
      const terms=[...(words[focus]||[]),...(domainWords[domain]||[])];
      const courses=(data.courses||[]).filter(course=>terms.some(word=>course.title.includes(word))).slice(0,3);
      if(courses.length) {
        root.append(el('p','کارگاه‌های قابل بررسی، بر اساس موضوع عنوان:'));
        courses.forEach(course=>root.append(link(course.title+' ←',data.workshops)));
      }
      root.append(el('p','برای یادگیری و رشد، می‌توانی دوره‌ها و کارگاه‌های موجود را هم بررسی کنی. این فهرست عمومی است؛ تناسب هر دوره با شرایطت نیاز به بررسی دارد.'));
      root.append(link('بررسی دوره‌ها و کارگاه‌ها ←',data.workshops));
    }
    root.append(link('مشاهدهٔ همهٔ درمانگران ←',data.directory),link('هماهنگی با کلینیک ←',data.contact));
    const nav=el('div',null,'mana-guide-actions'); nav.append(button('ویرایش پاسخ‌ها',()=>{step=questions.length-1;render();}),button('شروع دوباره',()=>{step=0;answers={};render();})); root.append(nav);focusTitle();
  }
  trigger.hidden=false;
  trigger.addEventListener('click',()=>{step=0;answers={};dialog.showModal();render();});
  document.querySelector('[data-guide-close]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{answers={};root.replaceChildren();trigger.focus();});
})();
