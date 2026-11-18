"""Run only against the disposable loopback MariaDB fixture documented in TESTING.md."""
import base64, gzip, hashlib, json, os, subprocess, time, uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
from types import SimpleNamespace
import httpx, pytest
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric import ec
from server.security import key_public, signed, verify

ROOT=Path(__file__).resolve().parents[2]
CONFIG=Path(os.environ.get('HUNT_TEST_CONFIG',str(ROOT/'runtime/php-mysql/private/config.php')))
PREFIX=os.environ.get('HUNT_TEST_PREFIX','/anazori/treasure-hunt')
URL=os.environ.get('HUNT_HTTP_BASE','http://127.0.0.1:8150')+PREFIX
URL2=os.environ.get('HUNT_HTTP_BASE2','http://127.0.0.1:8151')+PREFIX

def bridge(op,**kw):
    env={**os.environ,'HUNT_TEST_CONFIG':str(CONFIG)}
    r=subprocess.run(['php',str(Path(__file__).with_name('bridge.php'))],input=json.dumps(dict(op=op,**kw)),capture_output=True,text=True,env=env,check=True)
    return json.loads(r.stdout)

def fixture_content(revision):
    cp=[]; clues=[]
    for i in range(24):
        x,y=500+(i%6)*700,500+(i//6)*650
        cp.append(dict(id=f'c{i}',name=f'Outdoor fixture {i}',kind='hidden' if i==5 else 'department',description='Synthetic test coordinates only',
            x=x,y=y,lat=27+y*.000001,lon=94+x*.000001,radius_m=65,verified=True,accessible=True,finish_network_verified=i==23))
        clues.append(dict(id=f'r{i}',title=f'Fixture clue {i}',question=f'Find QR {i}',hint='Only test data',checkpoint_id=f'c{i}',
            difficulty=['easy','medium','hard'][i%3],points=100,reviewed=True,finish_only=i==23))
    # A small outdoor test graph; no real campus location is asserted by these fixtures.
    from server.rules import meters
    edges=[dict(a=a['id'],b=b['id'],meters=round(meters(a,b)*1.2+1,2),verified=True) for i,a in enumerate(cp) for b in cp[i+1:]]
    anchors=[dict(x=x,y=y,lat=27+y*.000001,lon=94+x*.000001) for x,y in [(0,0),(6000,0),(0,3375),(6000,3375)]]
    return dict(revision=revision,name='MySQL HTTP fixture',checkpoints=cp,riddles=clues,anchors=anchors,edges=edges,public_leaderboard=True,
        settings=dict(start_node='c0',route_tolerance=.2,require_kiosk=False,organizer_phone='',max_lives=3,workflow='QR_ONLY'))

@pytest.fixture(scope='module')
def baseline():
    client=httpx.Client(base_url=URL,timeout=30)
    login=client.post('/api/admin/login',json=dict(username='demo',password='Local-PHP-Demo-2026!'))
    assert login.status_code==200,login.text
    admin={'Authorization':'Bearer '+login.json()['token']}
    e=client.get('/api/admin/overview',headers=admin).json()['event']
    # Local fixture reset is explicitly gated by both test_only and the exact disposable DSN.
    e['published']=False
    for r in e['rounds'].values():
        r.update(status='DRAFT',started_at=None,paused_at=None,paused_ms=0,pause_intervals=[],control_version=0)
    bridge('reset',value=dict(event=e,teams=[],sessions=[]))
    content=fixture_content(e['revision'])
    r=client.put('/api/admin/content',headers=admin,json=content);assert r.status_code==200,r.text
    team=client.post('/api/admin/teams',headers=admin,json=dict(code='PHPT',name='PHP Expedition',pin='php-test-pin')).json()
    r=client.post('/api/admin/publish',headers=admin);assert r.status_code==200,r.text
    key=ec.generate_private_key(ec.SECP256R1());device=str(uuid.uuid4())
    r=client.post('/api/enroll',json=dict(code='PHPT',pin='php-test-pin',device_id=device,public_key=key_public(key)));assert r.status_code==200,r.text
    enrollment=r.json()
    h=SimpleNamespace(client=client,admin=admin,key=key,device=device,team_id=team['id'],auth={'Authorization':'Bearer '+enrollment['token']},
        organizer=serialization.load_pem_private_key((CONFIG.parent/'organizer-key.pem').read_bytes(),password=None))
    h.package_hash=''
    def sync(events=None,url=URL,**kw):
        body=dict(device_id=device,events=events or [],package_hash=h.package_hash,kiosk=True,ready=True,location=dict(lat=27.0005,lon=94.0005,accuracy=5,mock=False,age_ms=0),pending=0)
        body.update(kw)
        r=httpx.post(url+'/api/sync',headers=h.auth,json=body,timeout=30)
        if r.status_code==200 and 'package_hash' in r.json():h.package_hash=r.json()['package_hash']
        return r
    h.sync=sync
    assert sync().status_code==200;assert sync().status_code==200
    r=client.post('/api/admin/rounds/1/start',headers=admin);assert r.status_code==200,r.text
    h.initial=bridge('snapshot')
    yield h
    client.close()

@pytest.fixture
def h(baseline):
    h=baseline;bridge('reset',value=h.initial);h.seq=0;h.previous=''
    def action(kind='SCAN',n=1,wrong=False,**fields):
        v=bridge('state',id=h.team_id);e=v['event'];t=v['team'];p=t['data']['rounds'][str(n)]
        rid=p['route'][min(p['completed'],len(p['route'])-1)]
        clue=next(r for r in e['riddles'] if r['id']==rid)
        cp=next(c for c in e['checkpoints'] if c['id']==clue['checkpoint_id'])
        scan=next(c for c in e['checkpoints'] if c['id']!=cp['id']) if wrong else cp
        payload=dict(id=str(uuid.uuid4()),seq=h.seq+1,prev_hash=h.previous,kind=kind,round=n,event_id=e['id'],revision=e['revision'],
            team_id=h.team_id,device_id=h.device,control_version=e['rounds'][str(n)]['control_version'],riddle_id=rid,
            qr=signed(h.organizer,dict(event_id=e['id'],checkpoint_id=scan['id'],nonce=scan['nonce'])),
            gps=dict(lat=cp['lat'],lon=cp['lon'],accuracy=5,age_ms=0,mock=False))
        payload.update(fields);envelope=signed(h.key,payload);h.seq+=1;h.previous=hashlib.sha256(envelope['payload'].encode()).hexdigest();return envelope
    h.action=action
    return h

def ack(h,a):
    r=h.sync([a]);assert r.status_code==200,r.text
    return r.json()['ack'][0]['result']

def test_mysql_and_subfolder_serving(h):
    assert h.client.get('/health').json()['backend']=='php-mysql'
    for path in ('/dashboard/','/dashboard/app.js','/dashboard/leaderboard.html'):
        assert h.client.get(path).status_code==200,path
    for path in ('/app/Support.php','/vendor/autoload.php','/tests/bridge.php','/database/schema.mysql.sql','/composer.lock'):
        assert h.client.get(path).status_code in (403,404),path

def test_auth_and_one_phone(h):
    assert h.client.get('/api/admin/overview').status_code==401
    assert h.client.post('/api/sync',json={}).status_code==401
    key=ec.generate_private_key(ec.SECP256R1())
    r=h.client.post('/api/enroll',json=dict(code='PHPT',pin='php-test-pin',device_id=str(uuid.uuid4()),public_key=key_public(key)))
    assert r.status_code==409

def test_package_has_no_typed_answer_or_nonce(h):
    r=h.sync(package_hash='');assert r.status_code==200,r.text
    package=verify(key_public(h.organizer),r.json()['package'])
    assert package['workflow']=='QR_ONLY'
    assert all('answers' not in c and 'validators' not in c for c in package['riddles'])
    assert all('nonce' not in c and len(c['qr_nonce_hash'])==64 for c in package['checkpoints'])
    assert ack(h,h.action(kind='ANSWER',answer='guess'))=='QR_SCAN_REQUIRED'

def test_qr_svg(h):
    r=h.client.get('/api/admin/qr/c0',headers=h.admin)
    assert r.status_code==200 and '<svg' in r.text
    assert r.headers['content-type'].startswith('image/svg+xml')

def test_scan_retries_and_concurrent_duplicate_are_once(h):
    a=h.action(wrong=True)
    with ThreadPoolExecutor(2) as pool:
        results=list(pool.map(lambda url:h.sync([a],url=url),[URL,URL2]))
    assert [r.status_code for r in results]==[200,200]
    assert all(r.json()['ack'][0]['result']=='WRONG_SEQUENCE' for r in results)
    v=bridge('state',id=h.team_id)
    assert v['lives_events']==1 and v['team']['data']['lives_remaining']==2

def test_three_lives_elimination_and_restore(h):
    for result in ('WRONG_SEQUENCE','WRONG_SEQUENCE','ELIMINATED'):
        assert ack(h,h.action(wrong=True))==result
    assert ack(h,h.action())=='ELIMINATED'
    r=h.sync().json();assert r['eliminated'] and r['lives_remaining']==0
    board=h.client.get('/api/leaderboard').json()['rows'][0]
    assert board['status']=='ELIMINATED' and board['outcome']!='WINNER'
    r=h.client.post(f'/api/admin/teams/{h.team_id}/control',headers=h.admin,json=dict(operation='restore_lives',lives=1,reason='Marshal verified disputed QR placement'))
    assert r.status_code==200,r.text
    assert ack(h,h.action())=='CHECKPOINT_ACCEPTED'
    assert h.sync().json()['lives_remaining']==1

@pytest.mark.parametrize('gps,result',[(None,'GPS_REQUIRED'),({},'GPS_REQUIRED'),
    (dict(lat=0,lon=0,accuracy=5,age_ms=0,mock=False),'OUTSIDE_CHECKPOINT'),
    (dict(lat=27,lon=94,accuracy=80,age_ms=0,mock=False),'GPS_UNRELIABLE'),
    (dict(lat=27,lon=94,accuracy=5,age_ms=20000,mock=False),'GPS_UNRELIABLE'),
    (dict(lat=27,lon=94,accuracy=5,age_ms=0,mock=True),'MOCK_LOCATION')])
def test_gps_failure_never_costs_life(h,gps,result):
    assert ack(h,h.action(gps=gps))==result
    assert h.sync().json()['lives_remaining']==3

def test_unrelated_invalid_and_retired_qr_do_not_cost_lives(h):
    assert ack(h,h.action(qr={'payload':'AAAA','signature':'AAAA'}))=='INVALID_QR'
    v=bridge('state',id=h.team_id);e=v['event'];cp=e['checkpoints'][0]
    for event,nonce,result in [('another',cp['nonce'],'WRONG_EVENT_QR'),(e['id'],'old','RETIRED_QR')]:
        assert ack(h,h.action(qr=signed(h.organizer,dict(event_id=event,checkpoint_id=cp['id'],nonce=nonce))))==result
    assert h.sync().json()['lives_remaining']==3

def test_pause_late_review_and_no_backdated_finish(h):
    a=h.action()
    assert h.client.post('/api/admin/rounds/1/pause',headers=h.admin).status_code==200
    assert ack(h,a)=='REVIEW_REQUIRED'
    assert h.sync().json()['lives_remaining']==3
    r=h.client.post(f'/api/admin/teams/{h.team_id}/review',headers=h.admin,json={'reason':'Marshal inspected retained physical QR evidence'})
    assert r.status_code==200 and r.json()['reviewed'][0]['result']=='CHECKPOINT_ACCEPTED'

def test_two_round_winner_and_total_lives_persist(h):
    assert ack(h,h.action(wrong=True))=='WRONG_SEQUENCE'
    for _ in range(3):assert ack(h,h.action())=='CHECKPOINT_ACCEPTED'
    before=bridge('state',id=h.team_id)['team']['data']['rounds']['1']
    assert before['status']=='QUALIFIED'
    assert h.client.post('/api/admin/rounds/1/end',headers=h.admin).status_code==200
    r=h.client.post('/api/admin/rounds/2/prepare',headers=h.admin);assert r.status_code==200,r.text
    assert h.client.post('/api/admin/rounds/2/start',headers=h.admin).status_code==409
    h.sync();h.sync()
    r=h.client.post('/api/admin/rounds/2/start',headers=h.admin);assert r.status_code==200,r.text
    p=bridge('state',id=h.team_id)['team']['data']['rounds']['2']
    assert not set(before['route'])&set(p['route'])
    assert p['route'][-1]=='r23'
    for _ in range(7):assert ack(h,h.action(n=2))=='CHECKPOINT_ACCEPTED'
    row=h.client.get('/api/leaderboard').json()['rows'][0]
    assert row['outcome']=='WINNER' and row['lives_remaining']==2 and row['completed']==7
    assert row['finished_at'] is not None

def test_small_gzip_sync_and_etag(h):
    initial=h.sync().json();board=initial.get('leaderboard',h.client.get('/api/leaderboard').json())
    raw=json.dumps(dict(device_id=h.device,events=[],package_hash=h.package_hash,leaderboard_revision=board['revision'])).encode()
    r=h.client.post('/api/sync',headers={**h.auth,'Content-Type':'application/json','Content-Encoding':'gzip'},content=gzip.compress(raw))
    assert r.status_code==200,r.text
    assert len(gzip.compress(r.content))<1500
    assert h.client.post('/api/sync',headers={**h.auth,'Content-Encoding':'gzip'},content=gzip.compress(b'x'*1100000)).status_code==413
    r=h.client.get('/api/leaderboard')
    assert h.client.get('/api/leaderboard',headers={'If-None-Match':r.headers['etag']}).status_code==304
    assert 'location' not in r.text and 'public_key' not in r.text

def test_command_and_signed_offline_import(h):
    r=h.client.post(f'/api/admin/teams/{h.team_id}/control',headers=h.admin,json=dict(operation='exit',reason='Supervised organizer device maintenance'))
    assert r.status_code==200
    cmd=verify(key_public(h.organizer),h.sync().json()['commands'][0]);assert cmd['kind']=='EXIT'
    assert h.sync(applied_commands=[cmd['id']]).json()['commands']==[]
    a=h.action()
    r=h.client.post('/api/admin/import-evidence',headers=h.admin,json=dict(team_id=h.team_id,device_id=h.device,events=[a]))
    assert r.status_code==200 and r.json()['ack'][0]['result']=='CHECKPOINT_ACCEPTED'

def test_round_one_qualification_survives_round_two_life_restoration(h):
    for _ in range(3):assert ack(h,h.action())=='CHECKPOINT_ACCEPTED'
    assert h.client.post('/api/admin/rounds/1/end',headers=h.admin).status_code==200
    assert h.client.post('/api/admin/rounds/2/prepare',headers=h.admin).status_code==200
    h.sync();h.sync()
    assert h.client.post('/api/admin/rounds/2/start',headers=h.admin).status_code==200
    v=bridge('state',id=h.team_id);e=v['event'];p=v['team']['data']['rounds']['2']
    target=next(r['checkpoint_id'] for r in e['riddles'] if r['id']==p['route'][0])
    c=next(c for c in e['checkpoints'] if c['id']!=target)
    qr=signed(h.organizer,dict(event_id=e['id'],checkpoint_id=c['id'],nonce=c['nonce']))
    for expected in ('WRONG_SEQUENCE','WRONG_SEQUENCE','ELIMINATED'):
        assert ack(h,h.action(n=2,qr=qr))==expected
    r=h.client.post(f'/api/admin/teams/{h.team_id}/control',headers=h.admin,json=dict(operation='restore_lives',lives=1,reason='Marshal verified second round disputed seal'))
    assert r.status_code==200,r.text
    v=bridge('state',id=h.team_id)
    assert v['team']['data']['rounds']['1']['status']=='QUALIFIED'
    assert ack(h,h.action(n=2))=='CHECKPOINT_ACCEPTED'


def test_admin_elimination_blocks_winner_and_reinstatement_is_audited(h):
    url=f'/api/admin/teams/{h.team_id}/control'
    for operation in ('eliminate','disqualify'):
        r=h.client.post(url,headers=h.admin,json=dict(operation=operation,round=1,reason='Marshal verified an event rule dispute'))
        assert r.status_code==200,r.text
        assert ack(h,h.action())=='ELIMINATED'
        r=h.client.post(url,headers=h.admin,json=dict(operation='manual_finish',round=2,reason='Attempt to finish an eliminated participant'))
        assert r.status_code==409
        r=h.client.post(url,headers=h.admin,json=dict(operation='reinstate',reason='Head marshal overturned the previous decision'))
        assert r.status_code==200,r.text
    assert ack(h,h.action())=='CHECKPOINT_ACCEPTED'

