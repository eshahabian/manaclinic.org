const {spawnSync}=require('child_process'),assert=require('assert/strict');
const php=process.argv[2]||'output/mana-call-check/runtime/php/php.exe';
for(const scenario of ['missing-csrf','bad-csrf','forbidden-role','settings-denied','local-missing-csrf','local-bad-csrf','local-forbidden-role',
  'local-missing-room','local-outsider','local-schedule-denied','local-target-outsider','local-unassigned']){
  const result=spawnSync(php,['-n','output/mana-call-check/security-fixture.php',scenario],{encoding:'utf8'});
  assert.equal(result.status,0,result.stdout+result.stderr);
  assert.equal(result.stderr,'HTTP 403',scenario+' must be forbidden');
  if(scenario!=='settings-denied')assert.equal(JSON.parse(result.stdout).ok,false);
}
console.log('PASS 12 executable access/CSRF/membership/assignment/appointment denial cases against actual actions/pages (database doubles)');
