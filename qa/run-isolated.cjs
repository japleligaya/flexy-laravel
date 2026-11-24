const {spawn,spawnSync}=require('node:child_process');
const fs=require('node:fs');
const path=require('node:path');
const crypto=require('node:crypto');
const net=require('node:net');
const root=path.resolve(__dirname,'..');
async function main(){
  const listener=net.createServer();await new Promise(r=>listener.listen(0,'127.0.0.1',r));const port=listener.address().port;await new Promise(r=>listener.close(r));
  const id=Date.now()+'-'+crypto.randomBytes(4).toString('hex');
  const db=path.join(root,'storage','app','private','payroll-qa-'+id+'.sqlite');fs.writeFileSync(db,'');
  const env={...process.env,APP_ENV:'testing',APP_DEBUG:'false',DB_CONNECTION:'sqlite',DB_DATABASE:db,DB_URL:'',CACHE_STORE:'array',SESSION_DRIVER:'file',QUEUE_CONNECTION:'sync',MAIL_MAILER:'array',APP_CONFIG_CACHE:path.join(root,'storage','app','private','qa-config-'+id+'.php'),APP_URL:`http://127.0.0.1:${port}`,PAYROLL_QA_URL:`http://127.0.0.1:${port}`,PAYROLL_QA_EMAIL:'qa@example.test',PAYROLL_QA_PASSWORD:crypto.randomBytes(24).toString('hex'),PAYROLL_QA_ISOLATED:'1'};
  for(const args of [['artisan','migrate','--force','--no-interaction'],['artisan','payroll:qa-fixtures']]){
    const r=spawnSync('php',args,{cwd:root,env,stdio:'inherit',windowsHide:true});if(r.status!==0)throw new Error('SQLite QA setup failed');
  }
  const server=spawn('php',['-S',`127.0.0.1:${port}`,'-t','public','qa/router.php'],{cwd:root,env,stdio:['ignore','ignore','pipe'],windowsHide:true});
  let logs='';server.stderr.on('data',x=>{logs=(logs+x.toString()).slice(-5000);});
  try{
    let ready=false;for(let i=0;i<100;i++){try{if((await fetch(env.APP_URL+'/login')).ok){ready=true;break;}}catch{}await new Promise(r=>setTimeout(r,100));}
    if(!ready)throw new Error('QA server failed: '+logs);
    const result=await new Promise((resolve,reject)=>{const test=spawn(process.execPath,[path.join(__dirname,'node_modules','@playwright','test','cli.js'),'test',...process.argv.slice(2)],{cwd:__dirname,env,stdio:'inherit',windowsHide:true});test.on('error',reject);test.on('exit',resolve);});
    process.exitCode=result??1;
    console.log('QA used dedicated SQLite:',path.basename(db));
  } finally {server.kill();}
}
main().catch(e=>{console.error(e.message);process.exitCode=1;});
