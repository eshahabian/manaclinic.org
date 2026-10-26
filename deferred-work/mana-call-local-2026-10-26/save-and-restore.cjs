// One-time preservation/rollback for this chat. Refuses to overwrite its archive.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve('D:/Psychology'),archive=path.join(root,'deferred-work/mana-call-local-2026-10-26');
function checked(p){const a=path.resolve(p);if(!a.startsWith(root+path.sep))throw Error('Outside workspace: '+a);return a;}
const sha=b=>crypto.createHash('sha256').update(b).digest('hex');
const git=args=>cp.execFileSync('git',args,{cwd:root});
const tracked=['.gitignore','cpanel/.htaccess','cpanel/actions/livekit_token.php','cpanel/actions/video_signal.php',
 'cpanel/assets/js/video-call-livekit-bridge-v3.js','cpanel/assets/js/video-call-livekit-ui.js','cpanel/assets/js/video-call-watch.js',
 'cpanel/includes/auth.php','cpanel/includes/layout.php','cpanel/includes/livekit.php','cpanel/includes/staff_app.php',
 'cpanel/includes/video_call.php','cpanel/index.php','cpanel/pages/staff_app.php','cpanel/pages/video_call.php',
 'cpanel/pages/video_call_embed.php','cpanel/pages/video_call_livekit.php','cpanel/pages/video_call_v2.php'];
const added=['cpanel/actions/video_signal_local.php','cpanel/assets/css/video-call-local.css','cpanel/assets/js/mana-call-local-check.js',
 'cpanel/assets/js/video-call-local.js','cpanel/assets/vendor/livekit/LICENSE','cpanel/assets/vendor/livekit/README.md',
 'cpanel/assets/vendor/livekit/livekit-client.umd.min.js','cpanel/config.call-local.sample.php','cpanel/includes/mana_local_call.php',
 'cpanel/pages/staff_mana_call_settings.php','cpanel/pages/staff_mana_local_settings.php','cpanel/pages/video_call_connect.php',
 'cpanel/pages/video_call_local.php','docs/mana-call-domestic.md','docs/mana-call-server-switch.md','ops/mana-call/turnserver.conf.sample'];
const manifestFile=checked(path.join(archive,'manifest.json'));
if(fs.existsSync(manifestFile))throw Error('Archive already exists; do not rerun.');
if(git(['diff','--cached','--name-only','--',...tracked]).toString().trim())throw Error('Unexpected staged edits; stop.');
const changed=git(['diff','--name-only']).toString().trim().split(/\r?\n/).filter(Boolean);
if(changed.some(f=>!tracked.includes(f)))throw Error('Unrelated tracked changes detected; stop.');
const manifest={baseCommit:git(['rev-parse','HEAD']).toString().trim(),reason:'User requested postponement and complete local rollback',
 originalTestsPath:'output/mana-call-check',tracked:[],added:[],restored:false};
function save(kind,rel,bytes){const dst=checked(path.join(archive,kind,rel));fs.mkdirSync(path.dirname(dst),{recursive:true});fs.writeFileSync(dst,bytes);
 if(sha(fs.readFileSync(dst))!==sha(bytes))throw Error('Archive verification failed: '+rel);return sha(bytes);}
// Finish and verify every source/baseline copy before changing active files.
for(const rel of tracked){
 const current=fs.readFileSync(checked(path.join(root,rel))),baseline=git(['show','HEAD:'+rel]);
 manifest.tracked.push({path:rel,savedSha256:save('source',rel,current),baselineSha256:save('baseline',rel,baseline)});
}
for(const rel of added){const bytes=fs.readFileSync(checked(path.join(root,rel)));manifest.added.push({path:rel,savedSha256:save('source',rel,bytes)});}
fs.writeFileSync(checked(path.join(archive,'changes.patch')),git(['diff','--binary','--',...tracked]));
fs.writeFileSync(manifestFile,JSON.stringify(manifest,null,2));
const testSource=checked(path.join(root,manifest.originalTestsPath)),testTarget=checked(path.join(archive,'tests'));
if(fs.existsSync(testTarget))throw Error('Test archive already exists; stop.');
fs.renameSync(testSource,testTarget);
for(const entry of manifest.tracked){
 const active=checked(path.join(root,entry.path));
 if(sha(fs.readFileSync(active))!==entry.savedSha256)throw Error('Active file changed during backup: '+entry.path);
 fs.writeFileSync(active,fs.readFileSync(checked(path.join(archive,'baseline',entry.path))));
 if(sha(fs.readFileSync(active))!==entry.baselineSha256)throw Error('Restore verification failed: '+entry.path);
}
for(const entry of manifest.added){
 const active=checked(path.join(root,entry.path)),saved=checked(path.join(archive,'source',entry.path));
 if(sha(fs.readFileSync(active))!==entry.savedSha256||sha(fs.readFileSync(saved))!==entry.savedSha256)throw Error('Saved file changed: '+entry.path);
 // Remove only verified duplicate files; the complete copy is in source/.
 fs.unlinkSync(active);
}
for(const rel of ['cpanel/assets/vendor/livekit','cpanel/assets/vendor','ops/mana-call','ops']){
 const dir=checked(path.join(root,rel));if(fs.existsSync(dir)&&fs.readdirSync(dir).length===0)fs.rmdirSync(dir);
}
manifest.restored=true;fs.writeFileSync(manifestFile,JSON.stringify(manifest,null,2));
const diff=git(['diff','--name-only','--',...tracked]).toString().trim();
if(diff)throw Error('Remaining differences after rollback: '+diff);
const verification={trackedFilesRestored:manifest.tracked.length,newFilesArchived:manifest.added.length,
 activeFilesMatchBaseline:true,newCallFilesAbsent:added.every(rel=>!fs.existsSync(checked(path.join(root,rel)))),
 testsPreserved:fs.existsSync(path.join(testTarget,'local-media-results.json')),deploymentScopeUnchanged:true,
 liveHostModified:false,privateConfigurationCopied:false};
fs.writeFileSync(checked(path.join(archive,'verification.json')),JSON.stringify(verification,null,2));
console.log(JSON.stringify(verification));
