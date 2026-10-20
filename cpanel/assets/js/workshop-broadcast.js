(function(){
 function init(){document.querySelectorAll('[data-workshop-broadcast]').forEach(function(form){
  var text=form.elements.body,status=form.querySelector('[data-broadcast-status]'),send=form.querySelector('[data-broadcast-send]'),preview=form.querySelector('[type="submit"]'),token='',busy=false;
  preview.disabled=false;
  var search=form.querySelector('[data-recipient-search]'),rows=Array.from(form.querySelectorAll('[data-recipient-row]')),counter=form.querySelector('[data-recipient-count]');
  function normalize(v){return v.replace(/[۰-۹]/g,function(d){return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d);}).replace(/[٠-٩]/g,function(d){return '٠١٢٣٤٥٦٧٨٩'.indexOf(d);}).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s\u200c+(),-]/g,'').toLowerCase();}
  function filterRecipients(){var term=normalize(search.value),shown=0,matches=0,selected=rows.filter(function(r){return r.querySelector('input').checked;}).length;
    rows.forEach(function(row){var match=term!==''&&normalize(row.textContent).includes(term);if(match)matches++;row.hidden=!match||shown>=6;if(!row.hidden)shown++;});
    counter.textContent='انتخاب‌شده: '+selected+' نفر'+(term?' | '+matches+' نتیجه؛ نمایش حداکثر ۶ نفر':' | برای نمایش افراد جستجو کنید. همه اعضا در ابتدا انتخاب شده‌اند.');
  }
  if(search){search.addEventListener('input',filterRecipients);form.addEventListener('change',function(e){if(e.target.name==='selected[]')filterRecipients();});filterRecipients();}

  function count(result){var c=result.counts;status.textContent='پذیرفته‌شده توسط درگاه: '+c.SENT+' | در صف: '+c.PENDING+' | در حال ارسال: '+c.PROCESSING+' | ناموفق: '+c.FAILED+' | نامشخص: '+c.UNKNOWN+' | کنارگذاشته‌شده: '+c.SKIPPED;return c.PENDING;}
  async function request(operation){var data=new FormData(form);data.set('operation',operation);data.set('token',token);var response=await fetch(form.getAttribute('action'),{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json','X-Requested-With':'XMLHttpRequest'}});if(!response.headers.get('content-type')?.includes('application/json'))throw new Error('نشست یا ارتباط معتبر نیست؛ صفحه را تازه کنید.');var result=await response.json();if(!response.ok||!result.ok)throw new Error(result.error||'ارسال انجام نشد.');return result;}
  form.addEventListener('change',function(event){if(event.target.name==='selected[]'){token='';send.hidden=true;}});
  text.addEventListener('input',function(){if(!busy){token='';send.hidden=true;}var n=text.value.length;form.querySelector('[data-broadcast-length]').textContent=n+' کاراکتر';});
  form.addEventListener('submit',async function(event){event.preventDefault();if(busy)return;busy=true;preview.disabled=true;send.hidden=true;try{var previewBody=text.value,previewSelection=JSON.stringify(new FormData(form).getAll('selected[]'));var result=await request('preview');if(text.value!==previewBody||JSON.stringify(new FormData(form).getAll('selected[]'))!==previewSelection)throw new Error('متن یا گیرندگان تغییر کرده‌اند؛ دوباره پیش‌نمایش بگیرید.');token=result.token;status.textContent='گیرندگان: '+result.count+' شماره یکتا؛ شماره نامعتبر: '+result.invalid+'. متن را بررسی و سپس ارسال را تأیید کنید.';send.hidden=false;}catch(e){status.textContent=e.message;}finally{busy=false;preview.disabled=false;}});
  send.addEventListener('click',async function(){if(busy||!token)return;busy=true;send.disabled=true;preview.disabled=true;text.readOnly=true;form.querySelectorAll('input[type="checkbox"]').forEach(function(c){c.disabled=true;});try{var result=await request('send');var remaining=count(result);while(remaining>0){await new Promise(function(resolve){setTimeout(resolve,500);});result=await request('process');remaining=count(result);}send.hidden=true;token='';}catch(e){status.textContent=e.message+' پیام‌های ثبت‌شده در صف باقی می‌مانند؛ قبل از ارسال دوباره گزارش را بررسی کنید.';}finally{busy=false;send.disabled=false;preview.disabled=false;text.readOnly=false;form.querySelectorAll('input[type="checkbox"]').forEach(function(c){c.disabled=false;});}});
 });}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();
