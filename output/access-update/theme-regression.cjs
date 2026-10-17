const fs = require('fs');
const {PhpNode} = require('./tools/node_modules/php-wasm/PhpNode');
(async () => {
  const php = new PhpNode({version:'8.3'});
  let output = '';
  php.addEventListener('output', event => { output += event.detail.join(''); });
  php.addEventListener('error', event => { console.error(event.detail); });
  await php.mkdir('/app');
  await php.mkdir('/app/includes');
  for (const file of ['helpers.php','staff_app.php','web_push.php','video_call.php','doctor_profile_fields.php','petty_cash.php','user_avatar.php']) {
    await php.writeFile('/app/includes/'+file,fs.readFileSync('cpanel/includes/'+file));
  }
  const source = fs.readFileSync('cpanel/pages/staff_app.php','utf8');
  let home = source.slice(source.indexOf("if ($section === 'home') {"),source.indexOf("if ($section === 'therapists') {"));
  home = home.replace("staff_app_render('home', 'Mana Staff', $descriptions['home'], ob_get_clean());", '$renderedHome = ob_get_clean();').replace('    exit;', '');
  await php.writeFile('/app/home-fragment.php',home);
  const result = await php.run(fs.readFileSync('output/access-update/theme-regression.php','utf8'));
  console.log(output);
  if (result !== 0 || !output.startsWith('PASS ')) throw new Error('PHP regression checks failed');
})().catch(error => { console.error(error); process.exitCode=1; });
