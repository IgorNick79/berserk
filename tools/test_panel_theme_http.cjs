const fs=require('fs'),path=require('path'),assert=require('assert'),{spawn,execFileSync}=require('child_process');
const repo=path.resolve(__dirname,'..'),php=process.env.PHP_BINARY||'C:/OSPanel/modules/php/PHP_8.1/php.exe';
if(!process.env.THEME_TEST_MYSQL_PORT){console.log('SKIP: set THEME_TEST_MYSQL_PORT to an isolated test MySQL');process.exit(0);}
const port=Number(process.env.THEME_TEST_MYSQL_PORT),httpPort=Number(process.env.THEME_TEST_HTTP_PORT||33390);
assert(port>=1024&&port<=65535&&httpPort>=1024&&httpPort<=65535);
const database='berserk_ui_theme_http_'+require('crypto').randomBytes(5).toString('hex');assert(/^berserk_ui_theme_http_[a-f0-9]{10}$/.test(database));
const temp=process.env.THEME_HTTP_FIXTURE_DIR||path.join(repo,'tmp','theme-http-'+database),www=path.join(temp,'www');
for(const sub of ['www','config','sessions'])fs.mkdirSync(path.join(temp,sub),{recursive:true});
for(const file of ['www/panel-theme.php','src/Core/Autoloader.php','src/Auth/AuthContext.php','src/Auth/AuthSession.php','src/Auth/Account.php','src/Auth/AuthStore.php','src/Auth/DebugAccess.php','src/Auth/AccountUiPreferences.php','src/View/Ui/PanelTheme.php']){const target=path.join(temp,file);fs.mkdirSync(path.dirname(target),{recursive:true});fs.copyFileSync(path.join(repo,file),target);}
const config={allow_loopback_http:true,db:{host:'127.0.0.1',port,user:'root',pass:'',name:database}};
fs.writeFileSync(path.join(temp,'config/auth.local.php'),"<?php return json_decode('"+JSON.stringify(config)+"',true);");
fs.writeFileSync(path.join(www,'fixture-login.php'),String.raw`<?php require '../src/Core/Autoloader.php'; Berserk\Core\Autoloader::register(); Berserk\Core\Autoloader::addNamespace('Berserk\\',__DIR__.'/../src/'); Berserk\Auth\AuthSession::start(true); Berserk\Auth\AuthSession::login(new Berserk\Auth\Account((int)($_GET['id']??1),'Test')); header('Content-Type: application/json'); echo json_encode(['csrf'=>Berserk\Auth\AuthSession::csrf()]);`);
const sql=code=>execFileSync(php,['-r',`mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);$db=new mysqli('127.0.0.1','', '', null, ${port});`.replace("'127.0.0.1','',", "'127.0.0.1','root',")+code],{encoding:'utf8'});
let server;
(async()=>{try{
sql(`$db->query('CREATE DATABASE ${database}');$db->select_db('${database}');$db->multi_query(file_get_contents(${JSON.stringify(path.join(repo,'tools/migrations/20261021_auth.sql'))}));do{if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());$db->query(file_get_contents(${JSON.stringify(path.join(repo,'tools/migrations/20261024_account_ui_preferences.sql'))}));$db->query("INSERT INTO accounts(id,login,login_key,password_hash,created_at) VALUES(1,'TestOne','testone','not-a-login-password',UTC_TIMESTAMP(6)),(2,'TestTwo','testtwo','not-a-login-password',UTC_TIMESTAMP(6))");`);
server=spawn(php,['-d','session.save_path='+path.join(temp,'sessions'),'-S','127.0.0.1:'+httpPort,'-t',www],{cwd:temp,windowsHide:true,stdio:'ignore'});
const origin='http://127.0.0.1:'+httpPort;
for(let i=0;i<40;i++){try{await fetch(origin+'/panel-theme.php');break;}catch(e){await new Promise(r=>setTimeout(r,100));}}
assert.strictEqual((await fetch(origin+'/panel-theme.php')).status,401);
async function login(id){const r=await fetch(origin+'/fixture-login.php?id='+id);assert.strictEqual(r.status,200);const cookies=r.headers.getSetCookie?r.headers.getSetCookie():[r.headers.get('set-cookie')];return {cookie:cookies.filter(Boolean).map(s=>s.split(';')[0]).filter(s=>s.startsWith('BERSERKAUTH5A=')).at(-1),csrf:(await r.json()).csrf};}
const one=await login(1),two=await login(2);
const get=(session,suffix='')=>fetch(origin+'/panel-theme.php'+suffix,{headers:{Cookie:session.cookie}});
const post=(session,body)=>fetch(origin+'/panel-theme.php',{method:'POST',headers:{Cookie:session.cookie,'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(body)});
assert.strictEqual((await (await get(one)).json()).theme,'grimoire');
assert.strictEqual((await post(one,{theme:'stone'})).status,403);
assert.strictEqual((await post(one,{theme:'leather',csrf:one.csrf,account_id:'2'})).status,400);
assert.strictEqual((await post(one,{theme:'../stone',csrf:one.csrf})).status,400);
assert.strictEqual((await get(one,'?account_id=2')).status,400);
assert.strictEqual((await fetch(origin+'/panel-theme.php',{method:'PUT',headers:{Cookie:one.cookie}})).status,405);
assert.strictEqual((await post(one,{theme:'leather',csrf:one.csrf})).status,200);
assert.strictEqual((await (await get(one)).json()).theme,'leather');assert.strictEqual((await (await get(two)).json()).theme,'grimoire');
assert.strictEqual((await post(two,{theme:'stone',csrf:two.csrf})).status,200);
assert.strictEqual((await (await get(one)).json()).theme,'leather');assert.strictEqual((await (await get(two)).json()).theme,'stone');
sql(`$db->select_db('${database}');$db->query('RENAME TABLE account_ui_preferences TO missing_preferences');`);
const unavailable=await get(one);assert.strictEqual(unavailable.status,503);assert.strictEqual((await unavailable.json()).error,'theme_storage_unavailable');
console.log('PASS HTTP + MySQL: real AuthSession/AuthStore, anonymous 401, CSRF 403, whitelist/identity injection 400, method 405, account-isolated persistence and missing-schema 503. No game bootstrap.');
}finally{if(server)server.kill();sql(`$db->query('DROP DATABASE ${database}');`);}})().catch(e=>{console.error(e);process.exit(1)});
