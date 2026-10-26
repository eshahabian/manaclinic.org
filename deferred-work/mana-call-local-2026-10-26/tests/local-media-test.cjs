// Real Chromium + local TURN. Signaling fixture is local; production PHP is
// covered separately. No production credentials or endpoints are used here.
const fs=require('fs'),http=require('http'),crypto=require('crypto'),assert=require('assert/strict');
const {chromium}=require('playwright-core'),Turn=require('node-turn');
const root=require('path').resolve(__dirname,'../..');
const username=Math.floor(Date.now()/1000)+7200+':test',password=crypto.createHmac('sha1','local-test-secret').update(username).digest('base64');
const turnPort=14378;
const turn=new Turn({listeningPort:turnPort,listeningIps:['127.0.0.1'],relayIps:['127.0.0.1'],
  minPort:51000,maxPort:51100,authMech:'long-term',credentials:{[username]:password},realm:'local-test',debugLevel:'OFF'});
const messages=[];let seq=0,browser,server,turnMessages=0;
turn.on('message',()=>turnMessages++);
const blocked=[];
function html(me,media){return `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="csrf-token" content="test-token"><link rel="stylesheet" href="/assets/css/video-call-local.css"></head><body>
<main data-video-call data-signal-url="/video-signal-local?me=${me}" data-room="test-room" data-me="${me}" data-peer-name="تست محلی" data-media="${media}" data-can-start="${me==='a'?'1':'0'}" data-group="0" data-can-record="0" data-ring-url="/assets/audio/incoming-call.ogg" data-hang-url="/assets/audio/hang-up.ogg">
<header><h1>تست تماس داخلی</h1></header><p data-video-status></p><section data-video-permit hidden><p>اجازه دسترسی</p><button data-video-permit-btn>ورود</button></section>
<section data-video-stage class="stage"><div data-video-remotes class="remotes"></div><video data-video-local class="self" autoplay playsinline muted></video><p data-video-incoming hidden></p></section>
<nav><button data-video-start>شروع</button><button data-video-hangup hidden>قطع</button><button data-video-fs>تمام صفحه</button></nav></main>
<script>window.__testPeers=[];const NativePC=window.RTCPeerConnection;window.RTCPeerConnection=class extends NativePC{constructor(c){super(c);this.testErrors=[];this.addEventListener('icecandidateerror',e=>this.testErrors.push({code:e.errorCode,text:e.errorText}));window.__testPeers.push(this)}};
window.__MANA_LOCAL_AUTO_ANSWER__=${me==='b'};window.__VIDEO_ICE__=[{urls:'turn:127.0.0.1:${turnPort}?transport=udp',username:${JSON.stringify(username)},credential:${JSON.stringify(password)}}];</script>
<script src="/assets/js/video-call-local.js"></script></body></html>`;}
server=http.createServer(async(req,res)=>{
  const u=new URL(req.url,'http://127.0.0.1');
  if(u.pathname==='/relay-check'){
    res.setHeader('Content-Type','text/html; charset=utf-8');
    return res.end(`<p data-local-check-status></p><button data-enable-local disabled>Enable</button><script type="application/json" data-local-check-config>${JSON.stringify([{urls:`turn:127.0.0.1:${turnPort}?transport=udp`,username,credential:password}])}</script><script src="/assets/js/mana-call-local-check.js"></script>`);
  }
  if(u.pathname==='/test'){res.setHeader('Content-Type','text/html; charset=utf-8');return res.end(html(u.searchParams.get('me'),u.searchParams.get('media')||'video'));}
  if(u.pathname==='/video-call'){res.setHeader('Content-Type','text/html');return res.end('<p>Call ended</p>');}
  if(u.pathname==='/video-signal-local'){
    let raw='';for await(const c of req)raw+=c;
    const b=JSON.parse(raw),me=u.searchParams.get('me');let data={ok:true};
    assert.equal(req.headers['x-csrf-token'],'test-token');
    if(b.action==='send')messages.push({seq:++seq,id:String(seq),from:me,to:b.target_id||'',kind:b.kind,payload:b.payload});
    if(b.action==='poll'){
      const found=messages.filter(s=>s.seq>(Number(b.after)||0)&&s.from!==me&&(!s.to||s.to===me));
      data={ok:true,members:[{id:'a',name:'caller',online:true},{id:'b',name:'answerer',online:true}],signals:found,cursor:found.at(-1)?.seq||b.after||0};
    }
    res.setHeader('Content-Type','application/json');return res.end(JSON.stringify(data));
  }
  const assets={
    '/assets/js/video-call-local.js':['cpanel/assets/js/video-call-local.js','application/javascript'],
    '/assets/js/mana-call-local-check.js':['cpanel/assets/js/mana-call-local-check.js','application/javascript'],
    '/assets/css/video-call-local.css':['cpanel/assets/css/video-call-local.css','text/css'],
    '/assets/fonts/Vazirmatn-Medium.woff2':['cpanel/assets/fonts/Vazirmatn-Medium.woff2','font/woff2'],
    '/assets/audio/incoming-call.ogg':['cpanel/assets/audio/incoming-call.ogg','audio/ogg'],
    '/assets/audio/hang-up.ogg':['cpanel/assets/audio/hang-up.ogg','audio/ogg']};
  if(assets[u.pathname]){const [p,m]=assets[u.pathname];res.setHeader('Content-Type',m);return res.end(fs.readFileSync(root+'/'+p));}
  res.statusCode=404;res.end();
});
async function stats(page){return page.evaluate(async()=>{
  const report=[];
  for(const pc of window.__testPeers){
    const s=await pc.getStats();let audio=0,video=0,relay=false;
    s.forEach(r=>{if(r.type==='inbound-rtp'){if(r.kind==='audio')audio+=r.packetsReceived||0;if(r.kind==='video')video+=r.framesDecoded||0;}
      if(r.type==='candidate-pair'&&r.state==='succeeded'&&r.nominated){const c=s.get(r.localCandidateId);if(c?.candidateType==='relay')relay=true;}});
    report.push({state:pc.connectionState,ice:pc.iceGatheringState,signaling:pc.signalingState,audio,video,relay,errors:pc.testErrors,policy:pc.getConfiguration().iceTransportPolicy});
  }
  return report;
});}
(async()=>{
  turn.start();await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const base=`http://127.0.0.1:${server.address().port}`;
  browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true,
    args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--autoplay-policy=no-user-gesture-required','--disable-background-networking',
      '--allow-loopback-in-peer-connection','--force-webrtc-ip-handling-policy=default','--disable-features=WebRtcHideLocalIpsWithMdns']});
  const results=[];
  const diagnostic=await browser.newContext();
  await diagnostic.route('**/*',route=>new URL(route.request().url()).origin===base?route.continue():route.abort());
  const checkPage=await diagnostic.newPage();await checkPage.goto(base+'/relay-check');
  await checkPage.waitForFunction(()=>!document.querySelector('[data-enable-local]').disabled,{},{timeout:15000});
  await diagnostic.close();
  for(const media of ['video','audio']){
    messages.length=0;seq=0;
    const contexts=await Promise.all([browser.newContext({permissions:['camera','microphone']}),browser.newContext({permissions:['camera','microphone']})]);
    for(const context of contexts)await context.route('**/*',route=>{
      if(new URL(route.request().url()).origin!==base){blocked.push(route.request().url());return route.abort();}return route.continue();});
    const [a,b]=await Promise.all(contexts.map(c=>c.newPage()));
    await b.goto(base+`/test?me=b&media=${media}`);
    await a.goto(base+`/test?me=a&media=${media}`);
    let last;
    const deadline=Date.now()+35000;
    while(Date.now()<deadline){
      last=await Promise.all([stats(a),stats(b)]);
      if(last.every(client=>client.some(p=>p.state==='connected'&&p.relay&&p.audio>0&&(media==='audio'||p.video>0))))break;
      await new Promise(resolve=>setTimeout(resolve,500));
    }
    assert.ok(last.every(client=>client.some(p=>p.state==='connected'&&p.relay&&p.audio>0&&(media==='audio'||p.video>0))),JSON.stringify({media,turnMessages,stats:last,kinds:messages.map(m=>m.kind)}));
    assert.ok(last.flat().every(p=>p.policy==='relay'),'local relay is mandatory');
    if(media==='audio')assert.ok(last.flat().every(p=>p.video===0),'audio does not send video');
    if(media==='video')await a.screenshot({path:__dirname+'/local-call-test.png'});
    await a.locator('[data-video-hangup]').click();
    await Promise.all([a.waitForURL(base+'/video-call',{timeout:10000}),b.waitForURL(base+'/video-call',{timeout:10000})]);
    results.push({media,clients:last,hangup:'both clients exited'});
    await Promise.all(contexts.map(c=>c.close()));
  }
  assert.equal(blocked.length,0,'no external website requested by the call');
  fs.writeFileSync(__dirname+'/local-media-results.json',JSON.stringify({fixture:true,productionServerTested:false,relayReadinessCheck:true,externalRequests:blocked.length,results},null,2));
  console.log('PASS real two-browser video/audio media through local TURN; external websites blocked; both-sided hangup');
})().catch(e=>{console.error(e);process.exitCode=1;}).finally(async()=>{
  if(browser)await browser.close();server.close();turn.stop();
  setTimeout(()=>process.exit(process.exitCode||0),500).unref();
});
