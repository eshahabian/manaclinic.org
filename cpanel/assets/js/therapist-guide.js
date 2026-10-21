(() => {
  'use strict';
  const dialog = document.getElementById('mana-guide-dialog');
  const root = document.getElementById('mana-guide-content');
  const trigger = document.querySelector('[data-guide-start]');
  if (!dialog || !root || !trigger || !dialog.showModal) return;
  const data = JSON.parse(document.getElementById('mana-guide-data').textContent);
  const domainQuestion = [
    {key:'domain', title:'دوست داری این گفت‌وگو برای کدام بخش از زندگی‌ات باشد؟', options:[['individual','برای خودم و تجربه‌های شخصی‌ام'],['child','برای همراهی بهتر با فرزندم'],['couples','برای رابطه عاطفی یا زندگی مشترکم'],['premarital','برای شناخت بیشتر پیش از ازدواج'],['family','برای ارتباط بین اعضای خانواده']]},
  ][0];
  const closingQuestions = [
    {key:'gender', title:'برای گفت‌وگو با درمانگر، با کدام گزینه راحت‌تری؟', options:[['female','ترجیح می‌دهم درمانگرم خانم باشد'],['male','ترجیح می‌دهم درمانگرم آقا باشد'],['any','خانم یا آقا بودن برایم تفاوتی ندارد'],['unsure','هنوز ترجیح مشخصی ندارم']]},
    {key:'goal', title:'برای قدم بعدی، کدام مسیر به خواسته‌ات نزدیک‌تر است؟', options:[['therapy','جلسه مشاوره متناسب با موضوعی که انتخاب کردم'],['learn','آشنایی با دوره‌ها و کارگاه‌ها'],['unsure','ابتدا راهنمایی بگیرم و بعد تصمیم بگیرم']]}
  ];
  const individualQuestions = [
    ...[
      ['unwind','وقتی روزت تمام می‌شود و وقت استراحت می‌رسد، چقدر پیش می‌آید ذهنت هنوز مشغول کارهای فردا و اتفاق‌های احتمالی باشد؟','anxiety'],
      ['start','برای شروع کارهای معمول روز، چقدر پیش می‌آید بیشتر از قبل به خودت فرصت یا فشار بدهی؟','depression'],
      ['check','وقتی از خانه بیرون می‌روی یا کاری را تحویل می‌دهی، چقدر پیش می‌آید با وجود بررسی قبلی، دوباره برگردی تا مطمئن شوی؟','ocd'],
      ['plans','وقتی برنامه‌ای هنوز قطعی نیست، چقدر پیش می‌آید مرور حالت‌های مختلف، تمرکزت را از کارهای دیگر بگیرد؟','anxiety'],
      ['enjoy','وقتی فرصت کاری را داری که معمولاً دوستش داری، چقدر پیش می‌آید مثل گذشته برایت کشش نداشته باشد؟','depression'],
      ['repeat','وقتی می‌خواهی سراغ کار بعدی بروی، چقدر پیش می‌آید احساس کنی باید کار قبلی را دوباره انجام بدهی تا بتوانی رهایش کنی؟','ocd']
    ].map(([key,title,area])=>({key,title,area,hint:'با توجه به تجربهٔ چند هفتهٔ اخیرِ کسی که قرار است در جلسات شرکت کند پاسخ بده؛ پاسخ درست یا غلطی وجود ندارد.',options:[['0','هیچ‌وقت یا به‌ندرت'],['1','گاهی'],['2','بیشتر وقت‌ها'],['3','تقریباً همیشه'],['skip','مطمئن نیستم / ترجیح می‌دهم پاسخ ندهم']]})),
  ];
  const branchPrompts = {
  "child": [
    [
      "وقتی فرزندت از چیزی ناراحت می‌شود، معمولاً چه اتفاقی می‌افتد؟",
      [
        "کم‌کم می‌تواند بگوید چه شده",
        "زود بحث یا گریه بالا می‌گیرد و آرام شدن سخت می‌شود",
        "بیشتر در خودش می‌رود و فهمیدن خواسته‌اش سخت است",
        "بسته به موقعیت خیلی فرق می‌کند"
      ]
    ],
    [
      "وقتی زمان جدا شدن از تو یا رفتن به مهد و مدرسه می‌رسد، کدام تصویر آشناتر است؟",
      [
        "معمولاً بعد از کمی همراهی وارد جمع می‌شود",
        "جدا شدن طول می‌کشد و دنبال اطمینان گرفتن است",
        "می‌رود، اما بعد از آن خستگی یا دلخوری‌اش را در خانه نشان می‌دهد",
        "هنوز در چنین موقعیتی نبوده‌ایم"
      ]
    ],
    [
      "وقتی قرار است بازی تمام شود و کار دیگری شروع شود، بیشتر چه می‌بینی؟",
      [
        "با یادآوری و کمی زمان همراه می‌شود",
        "تغییر برنامه معمولاً به کشمکش می‌رسد",
        "شروع کار بعدی را فراموش می‌کند یا نیمه‌کاره رها می‌کند",
        "در بعضی کارها انعطاف دارد و در بعضی کارها نه"
      ]
    ],
    [
      "در ارتباط با هم‌سن‌وسال‌ها، بیشتر کدام موضوع توجهت را جلب کرده؟",
      [
        "ارتباط برقرار می‌کند؛ می‌خواهم بهتر حمایتش کنم",
        "برای وارد شدن به جمع به همراهی زیادی نیاز دارد",
        "دوستی‌ها زود به دلخوری یا درگیری می‌رسند",
        "فرصت کافی برای دیدن ارتباطش با هم‌سال‌ها نداشته‌ام"
      ]
    ],
    [
      "وقتی درباره یک قانون خانه با هم اختلاف دارید، معمولاً چه می‌شود؟",
      [
        "می‌توانیم قانون را کوتاه و روشن توضیح بدهیم",
        "آن‌قدر گفت‌وگو ادامه پیدا می‌کند که هر دو خسته می‌شویم",
        "بزرگ‌ترها پاسخ‌های متفاوتی می‌دهند و قانون عوض می‌شود",
        "مطمئن نیستم انتظارمان با سن او متناسب باشد"
      ]
    ],
    [
      "اگر همراهی با فرزندت کمی آسان‌تر شود، دوست داری اول کجا تفاوتش را ببینی؟",
      [
        "حرف‌ها و احساساتش را بهتر بفهمم",
        "برای خواب، درس یا کارهای روزانه روال بهتری داشته باشیم",
        "در جمع و محیط آموزشی راحت‌تر باشد",
        "من و دیگر مراقبان، هماهنگ‌تر با او برخورد کنیم"
      ]
    ]
  ],
  "couples": [
    [
      "وقتی درباره موضوعی اختلاف دارید، گفت‌وگو معمولاً به کجا می‌رسد؟",
      [
        "هر دو فرصت حرف زدن پیدا می‌کنیم",
        "یک موضوع قدیمی دوباره وسط می‌آید",
        "یکی دنبال ادامه گفت‌وگوست و دیگری فاصله می‌گیرد",
        "برای جلوگیری از تنش، اصل موضوع را مطرح نمی‌کنیم"
      ]
    ],
    [
      "وقتی از طرف مقابل چیزی می‌خواهی، کدام حالت آشناتر است؟",
      [
        "می‌توانم روشن بگویم چه نیازی دارم",
        "انتظار دارم خودش متوجه شود و بعد دلخور می‌شوم",
        "می‌گویم، اما احساس می‌کنم حرفم جور دیگری شنیده می‌شود",
        "هنوز برای خودم هم روشن نیست دقیقاً چه می‌خواهم"
      ]
    ],
    [
      "بعد از یک دلخوری، معمولاً چطور دوباره به هم نزدیک می‌شوید؟",
      [
        "درباره اتفاق حرف می‌زنیم و راهی پیدا می‌کنیم",
        "زمان می‌گذرد، اما موضوع حل‌نشده می‌ماند",
        "معمولاً فقط یکی از ما برای آشتی قدم برمی‌دارد",
        "مدتی است نزدیک شدن دوباره سخت‌تر شده"
      ]
    ],
    [
      "در تصمیم‌های مشترک مثل وقت، هزینه‌ها یا مسئولیت‌ها، چه تجربه‌ای داری؟",
      [
        "تقسیم مسئولیت‌ها برای هر دو روشن است",
        "احساس می‌کنم سهم کارها یا تصمیم‌ها متوازن نیست",
        "درباره اولویت‌ها توافق می‌کنیم، اما اجرا سخت می‌شود",
        "بیشتر فشار از شرایط بیرون رابطه می‌آید"
      ]
    ],
    [
      "درباره وقت دونفره و صمیمیت، کدام به تجربه‌ات نزدیک‌تر است؟",
      [
        "می‌خواهم رابطه خوبمان را عمیق‌تر کنیم",
        "کارهای روزمره جای وقت دونفره را گرفته‌اند",
        "نیاز یا انتظارمان از نزدیکی با هم فرق دارد",
        "گفتن خواسته‌ها و مرزها برایمان راحت نیست"
      ]
    ],
    [
      "دوست داری گفت‌وگوهای مشترک اول به کدام تغییر کمک کند؟",
      [
        "اختلاف را بدون دور شدن از هم مطرح کنیم",
        "اعتماد و اطمینان بینمان را دوباره بسازیم",
        "درباره ادامه مسیر رابطه تصمیم روشن‌تری بگیریم",
        "در برابر فشارهای زندگی، بیشتر همراه هم باشیم"
      ]
    ]
  ],
  "premarital": [
    [
      "وقتی درباره آینده حرف می‌زنید، کدام تصویر به شما نزدیک‌تر است؟",
      [
        "خواسته‌هایمان را با مثال و جزئیات مطرح کرده‌ایم",
        "کلیات شبیه است، اما جزئیات هنوز روشن نیست",
        "در چند موضوع مهم تصویرهای متفاوتی داریم",
        "بیشتر حرف‌ها به زمان دیگری موکول می‌شود"
      ]
    ],
    [
      "وقتی یکی از شما با خواسته دیگری موافق نیست، معمولاً چه می‌شود؟",
      [
        "می‌توانیم تفاوت را بشنویم و درباره‌اش حرف بزنیم",
        "برای حفظ فضای خوب، زود موافقت می‌کنیم",
        "یکی از ما تلاش می‌کند دیگری را قانع یا تغییر دهد",
        "هنوز اختلاف جدی‌ای پیش نیامده است"
      ]
    ],
    [
      "درباره کار، پول و مسئولیت‌های زندگی مشترک چقدر گفت‌وگو کرده‌اید؟",
      [
        "با مثال‌های واقعی و انتظارهای مشخص صحبت کرده‌ایم",
        "تا حدی، اما چند موضوع هنوز مبهم است",
        "نگاهمان متفاوت است و به توافق نرسیده‌ایم",
        "هنوز فرصت یا راحتی مطرح کردنش را نداشته‌ایم"
      ]
    ],
    [
      "وقتی نظر خانواده‌ها با خواسته شما فرق دارد، معمولاً چه می‌کنید؟",
      [
        "ابتدا بین خودمان گفت‌وگو می‌کنیم و بعد پاسخ می‌دهیم",
        "مرز نظر دادن و تصمیم گرفتن هنوز روشن نیست",
        "یکی از ما بین خواسته خانواده و رابطه گیر می‌کند",
        "هنوز چنین موقعیتی را تجربه نکرده‌ایم"
      ]
    ],
    [
      "درباره موضوع‌های شخصی مثل حریم خصوصی، صمیمیت و فرزند داشتن، کدام درست‌تر است؟",
      [
        "می‌توانیم خواسته‌ها و مرزهایمان را روشن بگوییم",
        "درباره بعضی موضوع‌ها حرف زده‌ایم و بعضی نه",
        "انتظارهای متفاوتی داریم که هنوز بررسی نکرده‌ایم",
        "شروع این گفت‌وگوها برایمان سخت است"
      ]
    ],
    [
      "از جلسات پیش از ازدواج بیشتر چه چیزی می‌خواهی؟",
      [
        "موضوع‌هایی را ببینیم که هنوز به آن‌ها توجه نکرده‌ایم",
        "تفاوت‌هایمان را واقع‌بینانه‌تر بررسی کنیم",
        "برای تصمیمی که هنوز درباره‌اش تردید دارم، روشن‌تر فکر کنم",
        "برای شروع زندگی مشترک مهارت و توافق بیشتری بسازیم"
      ]
    ]
  ],
  "family": [
    [
      "وقتی یک نفر در خانواده خواسته‌ای متفاوت دارد، معمولاً چه اتفاقی می‌افتد؟",
      [
        "می‌توانیم نظرهای مختلف را بشنویم",
        "یک یا دو نفر بیشتر تصمیم می‌گیرند",
        "بحث به دلخوری‌های قبلی کشیده می‌شود",
        "برای آرام ماندن فضا، خواسته مطرح نمی‌شود"
      ]
    ],
    [
      "اگر دو نفر از اعضای خانواده دلخور شوند، دیگران معمولاً چه نقشی پیدا می‌کنند؟",
      [
        "اجازه می‌دهند خودشان گفت‌وگو کنند",
        "از دیگران انتظار می‌رود طرف یکی را بگیرند",
        "یک نفر همیشه واسطه یا آرام‌کننده می‌شود",
        "دلخوری کم‌کم به بقیه رابطه‌ها هم می‌رسد"
      ]
    ],
    [
      "در مورد حریم شخصی و باخبر بودن از کارهای هم، کدام آشناتر است؟",
      [
        "حدود مورد قبولمان نسبتاً روشن است",
        "درباره اینکه چه چیزی خصوصی است اختلاف داریم",
        "نه گفتن یا مستقل تصمیم گرفتن باعث دلخوری می‌شود",
        "آن‌قدر از هم فاصله داریم که از نیازهای هم خبر نداریم"
      ]
    ],
    [
      "تقسیم کارها و مراقبت از اعضای خانواده معمولاً چه شکلی دارد؟",
      [
        "مسئولیت‌ها نسبتاً روشن و قابل گفت‌وگو هستند",
        "بار بیشتری روی دوش یک نفر مانده است",
        "انتظارها گفته نمی‌شوند، اما باعث دلخوری می‌شوند",
        "شرایط تازه‌ای پیش آمده و هنوز روال جدیدی نداریم"
      ]
    ],
    [
      "در این مدت، کدام موقعیت بیشتر تعادل خانواده را تغییر داده است؟",
      [
        "تغییر مهمی نبوده؛ می‌خواهیم ارتباطمان بهتر شود",
        "تغییر سن فرزندان، استقلال یا جدا شدن از خانه",
        "جابه‌جایی، جدایی یا تغییر در ترکیب خانواده",
        "از دست دادن یک عزیز یا مسئولیت مراقبتی تازه"
      ]
    ],
    [
      "دوست داری اولین تغییر در خانواده چه باشد؟",
      [
        "راحت‌تر و بدون قطع کردن حرف هم گفت‌وگو کنیم",
        "مرزها و مسئولیت‌های روشن‌تری داشته باشیم",
        "با یک تغییر یا اتفاق تازه همراه‌تر روبه‌رو شویم",
        "اعضای خانواده دوباره احساس نزدیکی و همراهی کنند"
      ]
    ]
  ]
};
  function branchContext(domain) {
    const definitions={
      individual:{key:'age',title:'خودت در کدام گروه سنی هستی؟',options:[['child','زیر ۱۲ سال'],['teen','۱۲ تا ۱۷ سال'],['adult','۱۸ سال یا بیشتر']]},
      child:{key:'age',title:'فرزندت در کدام گروه سنی است؟',options:[['preschool','پیش از دبستان'],['child','دبستان تا پیش از ۱۲ سالگی'],['teen','۱۲ تا ۱۷ سال'],['adult','۱۸ سال یا بیشتر']]},
      couples:{key:'stage',title:'در حال حاضر رابطه‌تان در چه موقعیتی است؟',options:[['dating','رابطه عاطفی بدون زندگی مشترک'],['together','زندگی مشترک داریم'],['apart','مدتی است از هم فاصله گرفته‌ایم'],['unsure','ترجیح می‌دهم در جلسه توضیح بدهم']]},
      premarital:{key:'stage',title:'در مسیر آشنایی و تصمیم برای ازدواج، کجا هستید؟',options:[['early','در مرحله آشنایی و شناخت اولیه‌ایم'],['deciding','در حال بررسی تصمیم ازدواجیم'],['engaged','تصمیم گرفته‌ایم و برای شروع آماده می‌شویم'],['unsure','هنوز موقعیتمان روشن نیست']]},
      family:{key:'household',title:'بیشتر دوست داری ارتباط بین چه کسانی بررسی شود؟',options:[['parent-child','والدین و فرزندان'],['siblings','خواهرها و برادرها'],['extended','اعضای خانواده و خانواده گسترده‌تر'],['several','چند نفر از اعضای خانواده با هم']]}
    };
    return definitions[domain];
  }
  function questionsFor(domain) {
    const branch=domain==='individual'?individualQuestions:(branchPrompts[domain]||[]).map(([title,labels],i)=>({
      key:domain+'_'+i,title,
      hint:domain==='child'?'بر اساس چیزهایی که خودت دیده‌ای پاسخ بده؛ لازم نیست احساس درونی فرزندت را حدس بزنی.':'نزدیک‌ترین گزینه به تجربه خودت را انتخاب کن؛ لازم نیست از طرف دیگران پاسخ بدهی.',
      options:[...labels.map((label,index)=>[String(index),label]),['skip','مطمئن نیستم / ترجیح می‌دهم در جلسه توضیح بدهم']]
    }));
    const closing=closingQuestions.map(q=>q.key==='gender' && domain==='child'
      ? {...q,title:'برای همراهی فرزندت، درباره خانم یا آقا بودن درمانگر ترجیحی داری؟',options:[['female','ترجیح می‌دهم درمانگر خانم باشد'],['male','ترجیح می‌دهم درمانگر آقا باشد'],['any','تفاوتی ندارد'],['unsure','می‌خواهم ترجیح فرزندم را هم در نظر بگیرم']]}
      : q);
    return [domainQuestion,branchContext(domain),...branch,...closing];
  }
  let step = 0, answers = {};
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
    nav.append(button(step===questions.length-1?'دیدن مسیر پیشنهادی':'ادامه',() => {
      const selected=Array.from(fields.querySelectorAll('input:checked')).map(i=>i.value);
      if(!selected.length) { error.textContent='برای ادامه یک گزینه انتخاب کن.'; return; }
      if(q.key==='domain' && answers.domain!==selected[0]) answers={};
      answers[q.key]=q.multi?selected:selected[0];
      if(step===questions.length-1) result(); else { step++; render(); }
    },'btn btn-primary'));
    if(step>0) nav.append(button('قبلی',()=>{step--;render();}));
    root.append(nav,el('p','پاسخ‌ها برای پیشنهاد حوزهٔ کاری درمانگر استفاده می‌شوند. این راهنما تست تشخیصی معتبر نیست؛ پاسخ‌ها ذخیره یا ارسال نمی‌شوند.','mana-guide-small'));
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
    if(answers.domain!=='individual') {
      const lastQuestion=questions[7];
      const priority=lastQuestion.options.find(([value])=>value===answers[lastQuestion.key]);
      if(priority && priority[0]!=='skip') root.append(el('p','اولویت تو برای شروع: '+priority[1]));
    }
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
