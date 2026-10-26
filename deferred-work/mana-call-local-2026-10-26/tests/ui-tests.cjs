const fs=require('fs'),vm=require('vm'),assert=require('assert/strict');
const source=fs.readFileSync('cpanel/assets/js/video-call-livekit-ui.js','utf8');
let assertions=0;
function check(value,label){assert.ok(value,label);assertions++;}
function element(){return {disabled:false,hidden:false,textContent:'',innerHTML:'',style:{},dataset:{},
  listeners:{},classList:{toggle(){},add(){},remove(){}},addEventListener(n,fn){this.listeners[n]=fn;},
  querySelector(){return null},querySelectorAll(){return []},hasAttribute(){return false}};}
function setup(provider,{audio=false,denied=false,hang=false,failConnect=false}={}){
  const selectors=['connect','camera','mic','leave','record','fs','bg','remotes','self','status','stage'];
  const elements=Object.fromEntries(selectors.map(s=>[s,element()]));
  elements.bg.value='none';
  const root=element();root.dataset={room:'existing-room',media:audio?'audio':'video',auto:'0'};
  root.querySelector=s=>elements[s.replace('[data-lk-','').replace(']','')];
  const calls=[],timers=new Map();let timerId=0,lastRoom;
  class Room{
    constructor(options){this.options=options;lastRoom=this;this.remoteParticipants=new Map();this.handlers={};
      this.localParticipant={trackPublications:new Map(),setMicrophoneEnabled:async on=>calls.push(['mic',on]),
      setCameraEnabled:async on=>calls.push(['camera',on])};}
    on(event,handler){this.handlers[event]=handler;}
    async connect(url,token,options){calls.push(['connect',url,token,options]);if(failConnect)throw Error('Connection failed');}
    disconnect(){calls.push(['disconnect']);}
  }
  const win={LivekitClient:{Room,RoomEvent:{}},addEventListener(){}};
  const context={window:win,LivekitClient:win.LivekitClient,AbortController,
    document:{querySelector:s=>s==='[data-livekit-v2]'?root:s==='meta[name="csrf-token"]'?{content:'test-csrf'}:null,
      addEventListener(){},documentElement:element()},
    navigator:{userAgent:'Android test'},setTimeout(fn,ms){timers.set(++timerId,{fn,ms});return timerId;},
    clearTimeout(id){timers.delete(id);},location:{pathname:'/video-call'},
    fetch:async(url,options)=>{
      calls.push(['fetch',url,options]);
      if(hang)return new Promise((resolve,reject)=>options.signal.addEventListener('abort',()=>reject(Object.assign(Error('aborted'),{name:'AbortError'}))));
      return {ok:!denied,json:async()=>denied?{ok:false,error:'نشست منقضی شد.'}:{ok:true,serverUrl:`wss://${provider}.example`,participantToken:`${provider}-room-token`}};
    }};
  vm.runInNewContext(source,context);
  return {elements,root,calls,timers,win,get room(){return lastRoom}};
}
async function settle(){await new Promise(resolve=>setImmediate(resolve));}
(async()=>{
  for(const provider of ['cloud','self'])for(const audio of [false,true]){
    const test=setup(provider,{audio});
    await test.elements.connect.listeners.click();await settle();
    const request=test.calls.find(c=>c[0]==='fetch');
    check(request[2].headers['X-CSRF-Token']==='test-csrf','CSRF sent');
    check(request[2].credentials==='same-origin','session sent');
    check(JSON.parse(request[2].body).room==='existing-room','same room key sent');
    const connected=test.calls.find(c=>c[0]==='connect');
    check(connected[1]===`wss://${provider}.example`,'server from issued token');
    check(connected[2]===`${provider}-room-token`,'correct token');
    check(test.calls.some(c=>c[0]==='mic'&&c[1]),'microphone enabled');
    check(test.calls.some(c=>c[0]==='camera')===!audio,'camera only for video');
    check(!test.elements.mic.disabled && test.elements.connect.disabled,'connected controls');
    check(test.timers.size===0,'token timeout cleared');
    await test.elements.leave.listeners.click();await settle();
    check(test.calls.some(c=>c[0]==='disconnect'),'leave disconnects');
    check(!test.elements.connect.disabled && test.elements.mic.disabled,'leave resets controls');
  }
  const denied=setup('cloud',{denied:true});denied.elements.connect.listeners.click();await settle();
  check(!denied.calls.some(c=>c[0]==='connect'),'denied token never joins');
  check(!denied.elements.connect.disabled,'denied request allows retry');
  check(denied.elements.status.textContent==='نشست منقضی شد.','server error displayed');
  const hung=setup('cloud',{hang:true});hung.elements.connect.listeners.click();await settle();
  const timer=[...hung.timers.values()].find(t=>t.ms===15000);check(!!timer,'bounded token request');timer.fn();await settle();
  check(!hung.elements.connect.disabled,'timeout allows retry');
  check(hung.elements.status.textContent.includes('طول کشید'),'timeout described');
  const failed=setup('self',{failConnect:true});failed.elements.connect.listeners.click();await settle();
  check(failed.calls.some(c=>c[0]==='disconnect'),'failed join cleans up room');
  check(!failed.elements.connect.disabled,'failed join allows retry');
  for(const f of ['video_call.php','video_call_livekit.php','video_call_embed.php','video_call_v2.php']){
    const page=fs.readFileSync(`cpanel/pages/${f}`,'utf8');
    check(page.includes('/assets/vendor/livekit/livekit-client.umd.min.js'),'SDK hosted locally '+f);
    check(!page.includes('cdn.jsdelivr.net/npm/livekit-client'),'no external SDK '+f);
  }
  const crypto=require('crypto');
  check(fs.statSync('cpanel/assets/vendor/livekit/livekit-client.umd.min.js').size>500000,'SDK not an error document');
  console.log(`PASS ${assertions} UI assertions (stub DOM/SDK; no real media test)`);
  console.log('SDK SHA256 '+crypto.createHash('sha256').update(fs.readFileSync('cpanel/assets/vendor/livekit/livekit-client.umd.min.js')).digest('hex'));
})().catch(e=>{console.error(e);process.exitCode=1});
