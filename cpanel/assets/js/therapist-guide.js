(() => {
  'use strict';
  const dialog = document.getElementById('mana-guide-dialog');
  const root = document.getElementById('mana-guide-content');
  const trigger = document.querySelector('[data-guide-start]');
  if (!dialog || !root || !trigger || !dialog.showModal) return;
  const data = JSON.parse(document.getElementById('mana-guide-data').textContent);
  const questions = [
    {key:'domain', title:'دوست داری این گفت‌وگو برای کدام بخش از زندگی‌ات باشد؟', options:[['individual','برای خودم و تجربه‌های شخصی‌ام'],['child','برای همراهی بهتر با فرزندم'],['couples','برای رابطه عاطفی یا زندگی مشترکم'],['premarital','برای شناخت بیشتر پیش از ازدواج'],['family','برای ارتباط بین اعضای خانواده']]},
    {key:'age', title:'کسی که قرار است در جلسات همراهی شود، در کدام گروه سنی است؟', options:[['child','زیر ۱۲ سال'],['teen','۱۲ تا ۱۷ سال'],['adult','۱۸ سال یا بیشتر']]},
    ...[
      ['unwind','وقتی روزت تمام می‌شود و وقت استراحت می‌رسد، چقدر پیش می‌آید ذهنت هنوز مشغول کارهای فردا و اتفاق‌های احتمالی باشد؟','anxiety'],
      ['start','برای شروع کارهای معمول روز، چقدر پیش می‌آید بیشتر از قبل به خودت فرصت یا فشار بدهی؟','depression'],
      ['check','وقتی از خانه بیرون می‌روی یا کاری را تحویل می‌دهی، چقدر پیش می‌آید با وجود بررسی قبلی، دوباره برگردی تا مطمئن شوی؟','ocd'],
      ['plans','وقتی برنامه‌ای هنوز قطعی نیست، چقدر پیش می‌آید مرور حالت‌های مختلف، تمرکزت را از کارهای دیگر بگیرد؟','anxiety'],
      ['enjoy','وقتی فرصت کاری را داری که معمولاً دوستش داری، چقدر پیش می‌آید مثل گذشته برایت کشش نداشته باشد؟','depression'],
      ['repeat','وقتی می‌خواهی سراغ کار بعدی بروی، چقدر پیش می‌آید احساس کنی باید کار قبلی را دوباره انجام بدهی تا بتوانی رهایش کنی؟','ocd']
    ].map(([key,title,area])=>({key,title,area,hint:'با توجه به تجربهٔ چند هفتهٔ اخیرِ کسی که قرار است در جلسات شرکت کند پاسخ بده؛ پاسخ درست یا غلطی وجود ندارد.',options:[['0','هیچ‌وقت یا به‌ندرت'],['1','گاهی'],['2','بیشتر وقت‌ها'],['3','تقریباً همیشه'],['skip','مطمئن نیستم / ترجیح می‌دهم پاسخ ندهم']]})),
    {key:'gender', title:'برای گفت‌وگو با درمانگر، با کدام گزینه راحت‌تری؟', options:[['female','ترجیح می‌دهم درمانگرم خانم باشد'],['male','ترجیح می‌دهم درمانگرم آقا باشد'],['any','خانم یا آقا بودن برایم تفاوتی ندارد'],['unsure','هنوز ترجیح مشخصی ندارم']]},
    {key:'goal', title:'برای قدم بعدی، کدام مسیر به خواسته‌ات نزدیک‌تر است؟', options:[['therapy','جلسه مشاوره متناسب با موضوعی که انتخاب کردم'],['learn','آشنایی با دوره‌ها و کارگاه‌ها'],['unsure','ابتدا راهنمایی بگیرم و بعد تصمیم بگیرم']]}
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
      if(step===questions.length-1) result(); else { step++; render(); }
    },'btn btn-primary'));
    if(step>0) nav.append(button('قبلی',()=>{step--;render();}));
    root.append(nav,el('p','پاسخ‌ها برای پیشنهاد حوزهٔ کاری درمانگر استفاده می‌شوند. این راهنما تست تشخیصی معتبر نیست؛ پاسخ‌ها ذخیره یا ارسال نمی‌شوند.','mana-guide-small'));
    focusTitle();
  }
  function result() {
    root.replaceChildren();
    const title=el('h2','یک نقطهٔ شروع برای تو'); title.id='mana-guide-dialog-title'; title.tabIndex=-1; root.append(title);
    const domain = answers.age!=='adult' && answers.domain==='individual' ? 'child' : answers.domain;
    root.append(el('p','مسیر قابل بررسی: '+data.domains[domain]));
    root.append(el('p','این فهرست بر اساس موضوع مراجعه، پاسخ‌های روزمره و حوزهٔ ثبت‌شدهٔ درمانگران مرتب شده است. این پاسخ‌ها علت تجربه‌ها یا وجود اختلال را مشخص نمی‌کنند؛ تناسب نهایی را با کلینیک و درمانگر بررسی کن.','mana-guide-small'));
    ['gender'].forEach(key=>{
      const q=questions.find(item=>item.key===key);
      const selected=q.options.find(([value])=>value===answers[key]);
      if(selected) root.append(el('p',selected[1],'mana-guide-small'));
    });
    if(answers.gender==='female'||answers.gender==='male') root.append(el('p','ترجیح خانم یا آقا بودن درمانگر در این فهرست اعمال نشده است؛ هنگام هماهنگی با کلینیک آن را مطرح کن.','mana-guide-small'));
    root.append(el('p','گروه سنی دقیق و شیوهٔ جلسه را پیش از رزرو هماهنگ کن.','mana-guide-small'));
    // A directory sorting heuristic, not a clinical scale or diagnostic cutoff.
    // Both questions in an area must be answered; skipped answers are not zeroes.
    const weights={};
    ['anxiety','depression','ocd'].forEach(area=>{
      const items=questions.filter(q=>q.area===area);
      if(items.every(q=>['0','1','2','3'].includes(answers[q.key]))) {
        weights[area]=Math.min(...items.map(q=>Number(answers[q.key])));
      }
    });
    const highest=Math.max(0,...Object.values(weights));
    const areas=highest>0?Object.keys(weights).filter(area=>weights[area]===highest):[];
    const descriptions={anxiety:'آرام‌تر شدن ذهن در موقعیت‌های نامطمئن',depression:'پیدا کردن ریتم و دل‌چسبی بیشتر در روزمره',ocd:'راحت‌تر عبور کردن از بررسی و تکرار کارها'};
    if(areas.length) root.append(el('p','برای شروع گفت‌وگو می‌توانی این موضوع‌ها را مطرح کنی: '+areas.map(area=>descriptions[area]).join('؛ ')));
    const matches=data.doctors.filter(doc=>doc.domains.includes(domain))
      .map(doc=>({doc,relevance:areas.filter(area=>doc.focus.includes(area)).length}))
      .sort((a,b)=>b.relevance-a.relevance).map(item=>item.doc);
    if(matches.length) {
      root.append(el('p','درمانگران دارای حوزهٔ ثبت‌شدهٔ مرتبط:'));
      matches.slice(0,3).forEach(doc=>{const a=link(doc.name,doc.href); a.append(el('span','مشاهدهٔ پروفایل · '+data.domains[domain]));root.append(a);});
    } else root.append(el('p','در فهرست فعلی، درمانگری با این حوزهٔ ثبت‌شده پیدا نشد. برای راهنمایی بیشتر با کلینیک هماهنگ کن یا همهٔ درمانگران را ببین.'));
    if(answers.goal!=='therapy') {
      const domainWords={child:['کودک','نوجوان','فرزند'],couples:['زوج','رابطه','روابط'],premarital:['ازدواج'],family:['خانواده'],individual:['خودشناسی','رشد فردی','مهارت']};
      const terms=domainWords[domain]||[];
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
