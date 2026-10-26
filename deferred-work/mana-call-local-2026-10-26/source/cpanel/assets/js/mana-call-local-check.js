(function () {
  var source=document.querySelector('[data-local-check-config]');
  var status=document.querySelector('[data-local-check-status]');
  var button=document.querySelector('[data-enable-local]');
  if(!source||!status||!button)return;
  var pc,timer,finished=false;
  function finish(ok){
    if(finished)return;finished=true;clearTimeout(timer);if(pc)pc.close();
    button.disabled=!ok;
    status.textContent=ok?'واسط داخلی از این مرورگر در دسترس است. تست دوطرفهٔ صدا و تصویر نیز لازم است.':'ارتباط با واسط داخلی برقرار نشد؛ روش فعلی را حفظ کنید و نصب یا پورت‌های سرور را بررسی کنید.';
  }
  try{
    pc=new RTCPeerConnection({iceServers:JSON.parse(source.textContent),iceTransportPolicy:'relay'});
    pc.onicecandidate=function(e){if(e.candidate&&(/ typ relay(?: |$)/.test(e.candidate.candidate)||e.candidate.type==='relay'))finish(true);};
    timer=setTimeout(function(){finish(false);},12000);
    pc.createDataChannel('relay-check');
    pc.createOffer().then(function(offer){return pc.setLocalDescription(offer);}).catch(function(){finish(false);});
  }catch(e){finish(false);}
})();
