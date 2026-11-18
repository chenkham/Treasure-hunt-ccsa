"""Real PHP + disposable MySQL + local SMTP recovery tests. No production mail or DB."""
import contextlib,email,json,os,queue,re,socket,socketserver,subprocess,threading,time,uuid
from pathlib import Path
import httpx,pytest

ROOT=Path(__file__).resolve().parents[2]
PHP=ROOT/'runtime/tools/php-8.4.27/php.exe'
PASSWORD='Test-First-Login-Only-2026!'
NEW_PASSWORD='Test-New-Password-Only-2026!'

class SMTPHandler(socketserver.StreamRequestHandler):
    def handle(self):
        def reply(line):self.wfile.write((line+'\r\n').encode());self.wfile.flush()
        reply('220 local-test ESMTP')
        while True:
            line=self.rfile.readline()
            if not line:return
            command=line.decode(errors='replace').strip()
            if command.upper().startswith(('EHLO','HELO')):reply('250 local-test')
            elif command.upper().startswith(('MAIL FROM:','RCPT TO:','RSET','NOOP')):reply('250 OK')
            elif command.upper()=='DATA':
                reply('354 End with dot');parts=[]
                while True:
                    part=self.rfile.readline()
                    if part in (b'.\r\n',b'.\n',b''):break
                    parts.append(part)
                self.server.messages.put(email.message_from_bytes(b''.join(parts)));reply('250 Queued')
            elif command.upper()=='QUIT':reply('221 Bye');return
            else:reply('502 Unsupported')

@pytest.fixture(scope='module')
def access():
    private=ROOT/'runtime/access-tests'/uuid.uuid4().hex;private.mkdir(parents=True)
    smtp=socketserver.ThreadingTCPServer(('127.0.0.1',0),SMTPHandler);smtp.messages=queue.Queue()
    threading.Thread(target=smtp.serve_forever,daemon=True).start()
    schema='hunt_local_access_'+uuid.uuid4().hex[:10]
    config=private/'config.php'
    def php(code):
        env={**os.environ,'OPENSSL_CONF':str(ROOT/'runtime/tools/php-8.4.27/extras/ssl/openssl.cnf')}
        result=subprocess.run([str(PHP),'-r',code],cwd=ROOT,env=env,capture_output=True,text=True)
        assert result.returncode==0,result.stderr
        return json.loads(result.stdout) if result.stdout else None
    php(r'''$c=require 'runtime/php-mysql/private/config.php';if(($c['test_only']??false)!==true||!str_starts_with($c['dsn'],'mysql:host=127.0.0.1;port=3339;dbname=hunt_local'))exit(1);
    $server=preg_replace('/;dbname=[^;]+/','',$c['dsn']);$pdo=new PDO($server,'root','');$pdo->exec('CREATE DATABASE '''+schema+r''' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('GRANT ALL ON '''+schema+r'''.* TO '.$pdo->quote($c['username'])."@'127.0.0.1'");
    $c['dsn']=preg_replace('/dbname=[^;]+/','dbname='.'''+json.dumps(schema)+r''',$c['dsn']);$c['private_dir']='''+json.dumps(str(private).replace('\\','/'))+r''';$c['require_https']=false;
    $c['initial_admin']=['username'=>'Chenkham','password_hash'=>password_hash('''+json.dumps(PASSWORD)+r''',PASSWORD_DEFAULT)];
    $c['admin_recovery']=['Chenkham'=>'organizer@example.test'];$c['recovery_secret']=bin2hex(random_bytes(32));
    $c['mail']=['transport'=>'smtp','host'=>'127.0.0.1','port'=>'''+str(smtp.server_address[1])+r''','encryption'=>'','from_address'=>'hunt@example.test'];
    file_put_contents('''+json.dumps(str(config).replace('\\','/'))+r''',"<?php return ".var_export($c,true).";");''')
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    env={**os.environ,'HUNT_CONFIG':str(config),'HUNT_TEST_PREFIX':'/treasure-hunt','OPENSSL_CONF':str(ROOT/'runtime/tools/php-8.4.27/extras/ssl/openssl.cnf')}
    log=(private/'php.log').open('w')
    worker=subprocess.Popen([str(PHP),'-S',f'127.0.0.1:{port}','-t',str(ROOT/'php-server'),str(ROOT/'php-server/router.php')],env=env,cwd=ROOT,stdout=log,stderr=log,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
    client=httpx.Client(base_url=f'http://127.0.0.1:{port}/treasure-hunt',timeout=20)
    for _ in range(80):
        try:client.get('/preflight.php');break
        except httpx.ConnectError:time.sleep(.1)
    def query(statement):
        return php("require 'php-server/app/bootstrap.php';$c=require "+json.dumps(str(config).replace('\\','/'))+";$d=new \\Hunt\\Database($c,false);$q=$d->pdo->query("+json.dumps(statement)+");echo json_encode($q->columnCount()?$q->fetchAll(PDO::FETCH_ASSOC):[]);")
    try:yield dict(client=client,query=query,messages=smtp.messages,private=private,php=php,config=config)
    finally:
        client.close();worker.terminate();worker.wait(timeout=10);log.close();smtp.shutdown();smtp.server_close()
        php("$c=require "+json.dumps(str(config).replace('\\','/'))+";$p=new PDO(preg_replace('/;dbname=[^;]+/','',$c['dsn']),'root','');$p->exec('DROP DATABASE "+schema+"');$p->exec('REVOKE ALL ON "+schema+".* FROM '.$p->quote($c['username']).\"@'127.0.0.1'\");")

def login(a,password=PASSWORD):
    return a['client'].post('/api/admin/login',json=dict(username='Chenkham',password=password))

def clear(a):
    a['query']('DELETE FROM hunt_rate_limits');a['query']('DELETE FROM hunt_admin_resets')
    while not a['messages'].empty():a['messages'].get_nowait()

def request(a):
    result=a['client'].post('/api/admin/password/request',json=dict(username='Chenkham',email='attacker@example.test'))
    assert result.status_code==200,result.text
    message=a['messages'].get(timeout=5)
    assert message['To']=='organizer@example.test' and message['From']
    raw=message.get_payload(decode=True).decode();code=re.search(r'code is: (\d{6})',raw).group(1)
    assert code not in result.text
    return code

def reset(a,code,password=NEW_PASSWORD):
    return a['client'].post('/api/admin/password/reset',json=dict(username='Chenkham',code=code,password=password))

def test_first_login_initializes_only_with_private_credentials(access):
    a=access;assert a['client'].get('/preflight.php').json()['status']=='first_login_ready'
    assert a['client'].post('/api/admin/password/request',json=dict(username='Chenkham')).status_code==409
    assert login(a,'not-the-password').status_code==401
    assert a['query']("SHOW TABLES LIKE 'hunt_admins'")==[]
    result=login(a);assert result.status_code==200,result.text
    headers={'Authorization':'Bearer '+result.json()['token']}
    overview=a['client'].get('/api/admin/overview',headers=headers);assert overview.status_code==200
    assert overview.json()['teams']==[] and len(overview.json()['event']['riddles'])==40
    assert all(r['difficulty']=='neutral' and r['title'].startswith('Riddle ') for r in overview.json()['event']['riddles'])
    assert a['client'].get('/preflight.php').json()['status']=='ready'
    assert login(a).status_code==200
    assert len(a['query']('SELECT * FROM hunt_admins'))==1

def test_recovery_uses_phpmailer_hashes_codes_and_throttles_resend(access):
    a=access;clear(a);code=request(a)
    row=a['query']('SELECT * FROM hunt_admin_resets')[0]
    assert code not in row['code_hash'] and len(row['code_hash'])==64
    assert a['client'].post('/api/admin/password/request',json=dict(username='Chenkham')).status_code==429
    assert a['client'].post('/api/admin/password/request',json=dict(username='unknown')).status_code==200
    assert a['messages'].empty()

def test_expired_and_exhausted_codes_cannot_reset_password(access):
    a=access;clear(a);code=request(a)
    a['query']('UPDATE hunt_admin_resets SET expires=1');assert reset(a,code).status_code==400
    a['query']('UPDATE hunt_admin_resets SET expires=9999999999999,attempts=0')
    wrong='999999' if code!='999999' else '888888'
    for _ in range(5):assert reset(a,wrong).status_code==400
    assert reset(a,code).status_code==400
    assert login(a).status_code==200

def test_reset_revokes_sessions_is_single_use_and_never_restores_bootstrap_password(access):
    a=access;clear(a);session=login(a).json()['token'];code=request(a)
    assert reset(a,code,'short').status_code==400
    assert reset(a,code).status_code==200
    assert reset(a,code).status_code==400
    assert login(a).status_code==401
    assert login(a,NEW_PASSWORD).status_code==200
    assert a['client'].get('/api/admin/overview',headers={'Authorization':'Bearer '+session}).status_code==401

def test_mail_settings_require_current_password_and_never_return_smtp_password(access):
    a=access;headers={'Authorization':'Bearer '+login(a,NEW_PASSWORD).json()['token']}
    mail=dict(transport='smtp',host='smtp.example.test',port=587,encryption='tls',username='hunt@example.test',password='Mailbox-Test-Secret',from_address='hunt@example.test')
    assert a['client'].put('/api/admin/account/mail',headers=headers,json=dict(mail=mail,current_password='wrong')).status_code==403
    assert a['client'].put('/api/admin/account/mail',headers=headers,json=dict(mail=mail,current_password=NEW_PASSWORD)).status_code==200
    result=a['client'].get('/api/admin/account',headers=headers)
    assert result.json()['recovery_email']=='organizer@example.test' and 'Mailbox-Test-Secret' not in result.text
    mail['password']='';assert a['client'].put('/api/admin/account/mail',headers=headers,json=dict(mail=mail,current_password=NEW_PASSWORD)).status_code==200
    assert json.loads((a['private']/'admin-access.json').read_text())['mail']['password']=='Mailbox-Test-Secret'
    assert a['client'].get('/api/admin/account').status_code==401

def test_hostinger_build_check_requires_private_provider_and_new_build_date(access):
    results=access['php']("require 'php-server/app/bootstrap.php';echo json_encode([\\Hunt\\runtimeSecurity(['hosting_provider'=>'hostinger-managed'],'8.4.26','2026-10-21'),\\Hunt\\runtimeSecurity(['hosting_provider'=>'hostinger-managed'],'8.4.26','2026-10-20'),\\Hunt\\runtimeSecurity([],'8.4.26','2026-10-21')]);")
    assert [r['patched'] for r in results]==[True,False,False]

def test_delivery_failure_does_not_leave_an_usable_code_or_leak_mail_details(access):
    a=access;clear(a)
    (a['private']/'admin-access.json').write_text(json.dumps(dict(mail=dict(transport='invalid',password='PRIVATE-MAIL-SECRET'))))
    result=a['client'].post('/api/admin/password/request',json=dict(username='Chenkham'))
    assert result.status_code==503 and 'PRIVATE-MAIL-SECRET' not in result.text
    assert a['query']('SELECT * FROM hunt_admin_resets')==[] and a['messages'].empty()

def test_reset_request_limit_applies_across_usernames(access):
    a=access;clear(a)
    for i in range(8):assert a['client'].post('/api/admin/password/request',json=dict(username=f'unknown{i}')).status_code==200
    assert a['client'].post('/api/admin/password/request',json=dict(username='another-user')).status_code==429
