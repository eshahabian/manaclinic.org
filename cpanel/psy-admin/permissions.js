'use strict';
// Loaded by the CMS only. This UI never manages clinic accounts or clinical roles.
window.ManaPermissions = {
 async open({user,api,onSaved,adminPassword}) {
  const data=await api('permission-catalog');
  const p={pages:[],fields:[],images:[],layouts:[],blocks:{},header:false,footer:false,menu:false,...user.permissions};
  const make=(tag,text)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;return e;};
  const dialog=make('dialog');dialog.className='permissions-dialog';dialog.setAttribute('aria-label','محدودهٔ ویرایش '+user.username);
  const form=make('form'),title=make('h2','محدودهٔ ویرایش '+user.username),intro=make('p','فقط موارد انتخاب‌شده قابل ویرایش‌اند. حساب جدید هیچ دسترسی پیش‌فرضی ندارد. این دسترسی‌ها فقط مربوط به طراحی و محتوای سایت هستند.');
  form.append(title,intro);
  const selections={pages:[],fields:[],images:[],layouts:[],blocks:{}},flags={};
  function choice(parent,label,checked){const wrapper=make('label'),input=make('input');input.type='checkbox';input.checked=checked;wrapper.append(input,document.createTextNode(label));parent.append(wrapper);return input;}
  const shared=make('fieldset');shared.append(make('legend','بخش‌های مشترک'));
  for(const [key,label] of [['header','همهٔ متن‌های سربرگ'],['footer','همهٔ متن‌های پابرگ'],['menu','منوی اصلی دسکتاپ و موبایل']])flags[key]=choice(shared,label,p[key]);
  shared.append(make('p','تغییر بخش مشترک در تمام صفحات اعمال می‌شود. می‌توانید به‌جای دسترسی کامل سربرگ یا پابرگ، فقط متن‌های مشخص زیر را انتخاب کنید.'));
  const sharedFields=new Map();for(const c of Object.values(data.catalog))for(const f of c.fields)if(f.scope!=='page')sharedFields.set(f.id,f);
  const specificShared=make('details');specificShared.append(make('summary','انتخاب متن مشخص از سربرگ یا پابرگ'));
  for(const f of sharedFields.values())selections.fields.push([f.id,choice(specificShared,(f.scope==='header'?'سربرگ: ':'پابرگ: ')+(f.value||'متن خالی'),p.fields.includes(f.id))]);
  shared.append(specificShared);form.append(shared);
  const designFlags=make('fieldset');designFlags.append(make('legend','اختیارهای طراحی و انتشار'));
  for(const [key,label]of [['createPages','ساخت و کپی صفحهٔ جدید'],['publishPages','انتشار صفحه‌ها و تغییرهای مجاز'],['mediaLibrary','بارگذاری و مدیریت کتابخانهٔ رسانه'],['footerMedia','افزودن تصویر به نستعلیق‌های فوتر'],['patterns','ذخیره و استفاده از بخش‌های آماده'],['theme','ویرایش ظاهر سراسری سایت'],['taxonomy','ویرایش حوزه، مسئله و رویکرد درمانگرها']])flags[key]=choice(designFlags,label,p[key]);
  form.append(designFlags);
  const pageGroup=make('fieldset');pageGroup.append(make('legend','صفحه‌ها و متن‌های مجاز'));
  for(const page of data.pages){
   const details=make('details');details.append(make('summary',page.title+' — '+page.id));
   const all=choice(details,'تمام متن‌های بدنهٔ '+page.id,p.pages.includes(page.id));selections.pages.push([page.id,all]);
   const layout=choice(details,'ساخت و ویرایش کل چیدمان '+page.id,p.layouts.includes(page.id));selections.layouts.push([page.id,layout]);
   const walkBlocks=(blocks,depth=0)=>{for(const b of blocks){const input=choice(details,'— '.repeat(depth)+'فقط بلوک '+b.type+': '+(b.text||b.id).slice(0,90),(p.blocks[page.id]||[]).includes(b.id));selections.blocks[page.id]??=[];selections.blocks[page.id].push([b.id,input]);walkBlocks(b.children||[],depth+1);}};
   walkBlocks(data.documents?.[page.id]?.draft?.blocks||[]);
   const content=make('div');const fields=(data.catalog[page.id]?.fields||[]).filter(f=>f.scope==='page');
   if(!fields.length)content.append(make('p','برای انتخاب متن‌های مشخص، ابتدا پیش‌نمایش این صفحه را باز یا همهٔ صفحه‌ها را فهرست‌سازی کنید.'));
   const choices=[];
   for(const f of fields){const input=choice(content,f.label+': '+(f.value||'متن خالی'),p.fields.includes(f.id));selections.fields.push([f.id,input]);choices.push(input);}
   const update=()=>{for(const input of choices)input.disabled=all.checked;};all.addEventListener('change',update);update();details.append(content);pageGroup.append(details);
  }
  form.append(pageGroup);
  const imageGroup=make('fieldset');imageGroup.append(make('legend','تصویرهای مجاز'));imageGroup.append(make('p','مجوز تصویر مستقل از صفحه است. جایگزینی تصویر مشترک در تمام محل‌های استفادهٔ آن اعمال می‌شود.'));
  const imgs=new Map();for(const c of Object.values(data.catalog))for(const i of c.images)imgs.set(i.id,i);
  for(const i of imgs.values())selections.images.push([i.id,choice(imageGroup,i.label+' — '+i.id,p.images.includes(i.id))]);
  if(!imgs.size)imageGroup.append(make('p','ابتدا صفحه‌های مربوط را پیش‌نمایش کنید تا تصویرهایشان شناسایی شوند.'));
  form.append(imageGroup);
  const passLabel=make('label','رمز فعلی مدیر طراحی برای ثبت دسترسی'),pass=make('input');pass.type='password';pass.autocomplete='current-password';pass.required=true;pass.value=adminPassword||'';passLabel.append(pass);form.append(passLabel);
  const message=make('p');message.setAttribute('role','status');const save=make('button','ثبت محدودهٔ ویرایش'),cancel=make('button','انصراف');cancel.type='button';cancel.className='secondary';cancel.onclick=()=>dialog.close();form.append(message,save,cancel);
  form.onsubmit=async event=>{event.preventDefault();save.disabled=true;try{
   const permissions={};for(const key of Object.keys(flags))permissions[key]=flags[key].checked;
   for(const key of ['pages','fields','images','layouts'])permissions[key]=[...new Set(selections[key].filter(([,input])=>input.checked&&!input.disabled).map(([id])=>id))];
   permissions.blocks={};for(const [path,items]of Object.entries(selections.blocks)){const ids=items.filter(([,input])=>input.checked).map(([id])=>id);if(ids.length)permissions.blocks[path]=ids;}
   await api('users',{action:'permissions',id:user.id,permissions,adminPassword:pass.value});dialog.close();await onSaved();
  }catch(error){message.textContent=error.message;}finally{save.disabled=false;}};
  dialog.append(form);document.body.append(dialog);dialog.addEventListener('close',()=>dialog.remove(),{once:true});dialog.showModal();
 }
};
