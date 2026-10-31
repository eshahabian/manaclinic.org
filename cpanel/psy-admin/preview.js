(() => {
  const origin=location.origin;
  // Sandbox omits allow-forms. Also block programmatic clinic actions from preview scripts.
  window.fetch=()=>Promise.reject(new Error('Preview is read only'));
  XMLHttpRequest.prototype.open=function(){throw new Error('Preview is read only')};
  document.addEventListener('submit',event=>event.preventDefault(),true);
  document.addEventListener('click',event=>{
    const field=event.target.closest('[data-mana-field]');
    const link=event.target.closest('a');
    if(field||link||event.target.closest('button,input,select,textarea')){event.preventDefault();event.stopImmediatePropagation();}
    if(field) parent.postMessage({type:'mana-select',id:field.dataset.manaField},origin);
    else if(link) { const url=new URL(link.href,location.href); if(url.origin===origin) parent.postMessage({type:'mana-navigate',path:url.pathname},origin); }
  },true);
  document.addEventListener('DOMContentLoaded',()=>parent.postMessage({type:'mana-ready',path:location.pathname},origin),{once:true});
})();
