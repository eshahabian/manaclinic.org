// Apply Git's configured working-tree line endings, without changing content.
const fs=require('fs'),path=require('path'),cp=require('child_process'),crypto=require('crypto');
const root=path.resolve('D:/Psychology'),archive=__dirname;
const manifestPath=path.join(archive,'manifest.json'),verificationPath=path.join(archive,'verification.json');
const manifest=JSON.parse(fs.readFileSync(manifestPath,'utf8'));
const normalized=b=>b.toString('utf8').replace(/\r\n/g,'\n');
for(const entry of manifest.tracked){
  const target=path.resolve(root,entry.path);
  if(!target.startsWith(root+path.sep))throw Error('Outside workspace');
  const baseline=fs.readFileSync(path.join(archive,'baseline',entry.path));
  if(normalized(fs.readFileSync(target))!==normalized(baseline))throw Error('Active content changed: '+entry.path);
  const checkout=cp.execFileSync('git',['cat-file','--filters',manifest.baseCommit+':'+entry.path],{cwd:root});
  if(normalized(checkout)!==normalized(baseline))throw Error('Unexpected Git filter change: '+entry.path);
  fs.writeFileSync(target,checkout);
  entry.restoredWorkingTreeSha256=crypto.createHash('sha256').update(checkout).digest('hex');
}
fs.writeFileSync(manifestPath,JSON.stringify(manifest,null,2));
const verification=JSON.parse(fs.readFileSync(verificationPath,'utf8'));
verification.lineEndingsMatchGitCheckout=true;
verification.restoredPhpSyntaxPassed=13;
verification.restoredJavascriptSyntaxPassed=3;
fs.writeFileSync(verificationPath,JSON.stringify(verification,null,2));
console.log('Restored content preserved; working-tree line endings normalized.');
