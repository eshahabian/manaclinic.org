(() => {
  'use strict';
  const dialog = document.getElementById('mana-guide-dialog');
  const root = document.getElementById('mana-guide-content');
  const trigger = document.querySelector('[data-guide-start]');
  if (!dialog || !root || !trigger || !dialog.showModal) return;
  const data = JSON.parse(document.getElementById('mana-guide-data').textContent);
  function questionsFor(domain) { return data.questions[domain] || data.questions.individual; }
  let step = 0, answers = {}, submission = null, runId = 0;
  const el = (tag, text, cls) => { const n = document.createElement(tag); if (text) n.textContent = text; if (cls) n.className = cls; return n; };
  const fa = n => String(n).replace(/\d/g, d => '۰۱۲۳۴۵۶۷۸۹'[d]);
  function button(text, action, cls='btn btn-outline') { const b=el('button',text,cls); b.type='button'; b.addEventListener('click',action); return b; }
  function link(text, href, cls='mana-guide-result') { const a=el('a',text,cls); a.href=href; return a; }
  function focusTitle() { root.querySelector('h2').focus(); }
  function render() {
    root.replaceChildren();
    const questions=questionsFor(answers.domain||'individual');
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
    nav.append(button(step===questions.length-1?'ثبت پاسخ‌ها و دیدن نتیجه':'ادامه',async () => {
      const selected=Array.from(fields.querySelectorAll('input:checked')).map(i=>i.value);
      if(!selected.length) { error.textContent='برای ادامه یک گزینه انتخاب کن.'; return; }
      if(q.key==='domain' && answers.domain!==selected[0]) { answers={}; }
      if(answers[q.key]!==selected[0]) submission=null;
      answers[q.key]=q.multi?selected:selected[0];
      if(step===questions.length-1) {
        const currentRun=runId;
        if(!submission) submission=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');
        nav.querySelectorAll('button').forEach(b=>b.disabled=true);
        error.textContent='در حال ثبت پاسخ‌ها…';
        try {
          const response=await fetch(data.submit,{method:'POST',credentials:'same-origin',headers:{'Accept':'application/json','X-CSRF-Token':data.csrf},body:new URLSearchParams({answers:JSON.stringify(answers),submission,action:'show_result'})});
          const saved=await response.json();
          if(!response.ok || !saved.ok) throw new Error(saved.error||'ثبت پاسخ‌ها انجام نشد.');
          if(currentRun===runId && dialog.open) result();
        } catch(e) {
          if(currentRun===runId && dialog.open) {
            error.textContent=e.message||'ارتباط برقرار نشد؛ دوباره تلاش کن.';
            nav.querySelectorAll('button').forEach(b=>b.disabled=false);
          }
        }
      } else { step++; render(); }
    },'btn btn-primary'));
    if(step>0) nav.append(button('قبلی',()=>{step--;render();}));
    root.append(nav,el('p','با زدن «ثبت پاسخ‌ها و دیدن نتیجه»، پاسخ‌ها و نتیجه برای بررسی توسط تیم مجاز کلینیک ذخیره می‌شوند. این راهنما تست تشخیصی معتبر نیست.','mana-guide-small'));
    focusTitle();
  }
  function result() {
    const questions=questionsFor(answers.domain);
    root.replaceChildren();
    const title=el('h2','یک نقطهٔ شروع برای تو'); title.id='mana-guide-dialog-title'; title.tabIndex=-1; root.append(title);
    const domain = answers.domain==='child' && answers.age==='adult' ? 'family'
      : ['child','teen'].includes(answers.age) && answers.domain==='individual' ? 'child' : answers.domain;
    root.append(el('p','مسیر قابل بررسی: '+data.domains[domain]));
    root.append(el('p',answers.domain==='individual'
      ? 'این فهرست بر اساس موضوع مراجعه، پاسخ‌های روزمره و حوزهٔ ثبت‌شدهٔ درمانگران مرتب شده است. پاسخ‌ها وجود اختلال را مشخص نمی‌کنند؛ تناسب نهایی را با کلینیک و درمانگر بررسی کن.'
      : 'درمانگران زیر در حوزهٔ انتخاب‌شده فعالیت دارند. پاسخ‌های اختصاصی به روشن شدن موضوع جلسه کمک می‌کنند؛ اطلاعات ثبت‌شده برای رتبه‌بندی دقیق‌تر درمانگران در این حوزه کافی نیست. تناسب نهایی را با کلینیک بررسی کن.','mana-guide-small'));
    if(answers.domain==='child' && answers.age==='adult') root.append(el('p','چون فرزندت بزرگسال است، برای بررسی رابطه والد و فرزند از مسیر خانواده شروع می‌کنیم؛ نیاز به جلسه فردی یا مشترک را با کلینیک هماهنگ کن.','mana-guide-small'));
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
      if(items.length && items.every(q=>['0','1','2','3'].includes(answers[q.key]))) {
        weights[area]=Math.min(...items.map(q=>Number(answers[q.key])));
      }
    });
    const highest=Math.max(0,...Object.values(weights));
    const areas=highest>0?Object.keys(weights).filter(area=>weights[area]===highest):[];
    const descriptions={anxiety:'آرام‌تر شدن ذهن در موقعیت‌های نامطمئن',depression:'پیدا کردن ریتم و دل‌چسبی بیشتر در روزمره',ocd:'راحت‌تر عبور کردن از بررسی و تکرار کارها'};
    const summary=el('div',null,'mana-guide-summary');
    summary.append(el('h3','برداشت کوتاه از پاسخ‌هایت'));
    if(answers.domain==='individual') {
      summary.append(el('p',areas.length
        ? 'در پاسخ‌هایت، موضوع «'+areas.map(area=>descriptions[area]).join(' و ')+'» بیشتر به چشم می‌آید.'
        : 'پاسخ‌های فعلی برای برجسته کردن یک موضوع مشخص کافی نیستند.'));
      summary.append(el('p','می‌توانی جلسه اول را با یک نمونه از روزمره‌ات شروع کنی تا نیازت روشن‌تر شود.'));
    } else {
      const lastQuestion=questions[7];
      const priority=lastQuestion.options.find(([value])=>value===answers[lastQuestion.key]);
      const nextSteps={
        child:'یک موقعیت واقعی از تعامل با فرزندت را به جلسه بیاور تا نیاز او و شیوه همراهی تو با هم بررسی شوند.',
        couples:'برای شروع، یک اختلاف تکرارشونده و انتظار خودت از طرف مقابل را با درمانگر بررسی کن.',
        premarital:'برای شروع، یک تصمیم واقعی پیش رو را بررسی کنید تا انتظارها و تفاوت‌ها روشن‌تر شوند.',
        family:'برای شروع، یک موقعیت خانوادگی مشخص و نقش هر نفر را با درمانگر مرور کن.'
      };
      summary.append(el('p',priority && priority[0]!=='skip'
        ? 'از انتخابت پیداست که این خواسته برای شروع مهم‌تر است: «'+priority[1]+'».'
        : 'هنوز اولویت مشخصی برای شروع انتخاب نکرده‌ای؛ می‌توان آن را در جلسه اول روشن کرد.'));
      summary.append(el('p',nextSteps[answers.domain]));
    }
    root.append(summary);
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
    const nav=el('div',null,'mana-guide-actions'); nav.append(button('ویرایش پاسخ‌ها',()=>{step=questions.length-1;render();}),button('شروع دوباره',()=>{step=0;answers={};submission=null;runId++;render();})); root.append(nav);focusTitle();
  }
  trigger.hidden=false;
  trigger.addEventListener('click',()=>{step=0;answers={};submission=null;runId++;dialog.showModal();render();});
  document.querySelector('[data-guide-close]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('close',()=>{answers={};submission=null;runId++;root.replaceChildren();trigger.focus();});
})();
