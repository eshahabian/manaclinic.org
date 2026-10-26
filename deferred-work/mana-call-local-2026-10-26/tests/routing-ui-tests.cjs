const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
let checks=0;const check=(v,label)=>{assert.ok(v,label);checks++;};
const settle=()=>new Promise(resolve=>setImmediate(resolve));
for(const engine of ['local','livekit']){
  let opened=null;const location={href:'',search:'',pathname:'/video-call',replace(url){this.href=url;}};
  const home={};const window={ManaLiveKitUi:{open(info){opened=info;}}};
  const document={querySelector(s){return s==='[data-livekit-home]'?home:null;},createElement(){return {};},head:{appendChild(){}}};
  vm.runInNewContext(fs.readFileSync('cpanel/assets/js/video-call-livekit-bridge-v3.js','utf8'),{window,document,location,URLSearchParams,setTimeout(){},fetch(){throw Error('unexpected network');}});
  window.ManaVideoCall.start({room:'room-key',engine,media:'audio',answer:true});
  if(engine==='local'){
    check(location.href==='/video-call-local?room=room-key&media=audio&answer=1','local engine has separate page');
    check(opened===null,'local engine never opens LiveKit UI');
  }else{
    check(opened?.room==='room-key','original LiveKit UI retained');
    check(location.href==='','original inline call retained');
  }
}
async function watcher(engine){
  const calls=[],listeners={},storage=new Map();let opened=null;
  const location={href:'',pathname:'/app'};
  const nodes={};const node=id=>nodes[id]??={hidden:false,addEventListener(event,fn){listeners[id+'-'+event]=fn;},querySelector(s){return node(s);}};
  const window={__VIDEO_CALL_WATCH__:{signalUrl:'/video-signal',localSignalUrl:'/video-signal-local'},location,
    ManaLiveKitUi:{open(info){opened=info;}},focus(){}};
  const document={title:'Staff',hidden:false,body:{appendChild(){}},addEventListener(){},
    createElement(){return node('banner');},querySelector(s){if(s==='meta[name="csrf-token"]')return {content:'csrf'};if(s==='[data-livekit-home]')return {};return null;}};
  const context={window,document,location,navigator:{},sessionStorage:{getItem:k=>storage.get(k),setItem:(k,v)=>storage.set(k,v)},
    setTimeout(){},setInterval(){return 1;},clearInterval(){},
    fetch:async(url,options)=>{const body=JSON.parse(options.body);calls.push({url,body});return {json:async()=>body.action==='inbox'?{ok:true,items:[{id:'ring1',room:'same-room',sender_name:'Doctor',media:'video',engine}]}:{ok:true}};}};
  vm.runInNewContext(fs.readFileSync('cpanel/assets/js/video-call-watch.js','utf8'),context);
  await settle();
  check(calls.length===1&&calls[0].url==='/video-signal-local','one combined inbox request');
  listeners['[data-vc-alert-open]-click']();await settle();
  const ack=calls.find(c=>c.body.action==='ack_ring');
  check(ack.url===(engine==='local'?'/video-signal-local':'/video-signal'),'ack stays in its own engine');
  if(engine==='local'){
    check(location.href.includes('/video-call-local?room=same-room&answer=1'),'local answer routes locally');
    check(opened===null,'local answer does not open cloud media');
  }else{
    check(opened?.room==='same-room'&&opened.answer,'cloud answer retains inline LiveKit');
    check(location.href==='','cloud answer not redirected into local call');
  }
}
(async()=>{
  await watcher('local');await watcher('livekit');
  const cp=require('child_process');
  const original=cp.execFileSync('git',['show','HEAD:cpanel/includes/livekit.php'],{encoding:'utf8'});
  check(fs.readFileSync('cpanel/includes/livekit.php','utf8').replace(/\r\n/g,'\n')===original.toString().replace(/\r\n/g,'\n'),'original LiveKit implementation unchanged');
  console.log(`PASS ${checks} routing/answer/isolation assertions`);
})().catch(e=>{console.error(e);process.exitCode=1});
