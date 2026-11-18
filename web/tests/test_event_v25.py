"""Three-round production workflow exercised through PHP/MySQL HTTP endpoints."""
import base64,hashlib,json,time,uuid
from concurrent.futures import ThreadPoolExecutor
from types import SimpleNamespace
import httpx,pytest
from cryptography.hazmat.primitives.asymmetric import ec
from test_http import h,baseline,bridge,URL,URL2,key_public,signed,verify,ROOT

def rounds():
    return {str(n):dict(status='DRAFT',duration_ms=60000,started_at=None,paused_at=None,paused_ms=0,
        pause_intervals=[],control_version=0,qualify_mode='all',qualify_limit=20) for n in (1,2,3)}

@pytest.fixture
def event(h):
    e=bridge('state',id=h.team_id)['event'];e.update(published=False,rounds=rounds(),finish_counter=0)
    e['settings']=dict(event_format='three-round-v3',qr_validation='signed_code',start_node='',route_tolerance=.2,
        require_kiosk=False,organizer_phone='',life_cooldown_ms=900000,opponent_map='off',min_android_version=8,assignment_seed=str(uuid.uuid4()))
    e['riddles']=[];e['checkpoints']=[];e['anchors']=[];e['edges']=[]
    bridge('reset',value=dict(event=e,teams=[],sessions=[]))
    clues=[]
    for n,levels in [(1,['easy','medium','hard']*2),(2,['neutral']*10),(3,['hard']*4)]:
        for i,level in enumerate(levels):clues.append(dict(id=f'round{n}-{i}',round=n,difficulty=level,title=f'Round {n} clue {i}',
            question=f'QUESTION-{n}-{i} <script>test</script>',placement=f'PRIVATE-PLACEMENT-{n}-{i}',reviewed=True,points=100,hint='MUST NOT SHIP'))
    result=h.client.put('/api/admin/content',headers=h.admin,json=dict(revision=e['revision'],riddles=clues,round_settings=rounds()))
    assert result.status_code==200,result.text
    v=SimpleNamespace(client=h.client,admin=h.admin,organizer=h.organizer,teams=[])
    for i in range(3):
        result=h.client.post('/api/admin/teams',headers=h.admin,json=dict(code=f'TEAM{i}',name=f'Team {i}',pin='private-password'))
        assert result.status_code==200,result.text
        v.teams.append(SimpleNamespace(id=result.json()['id'],code=f'TEAM{i}',device=str(uuid.uuid4()),key=ec.generate_private_key(ec.SECP256R1()),seq=0,previous='',package_hash=''))
    def control(n,op):
        result=v.client.post(f'/api/admin/rounds/{n}/{op}',headers=v.admin)
        assert result.status_code==200,result.text
        return result.json()
    v.control=control
    def sync(t,events=None,url=URL):
        result=httpx.post(url+'/api/sync',headers=t.auth,json=dict(client_version=8,device_id=t.device,events=events or [],
            package_hash=t.package_hash,kiosk=True,ready=True,pending=0),timeout=30)
        assert result.status_code==200,result.text
        data=result.json()
        if 'package_hash' in data:t.package_hash=data['package_hash']
        return data
    v.sync=sync
    def start(config=None):
        if config:
            state=bridge('state',id=v.teams[0].id)['event'];rs=state['rounds']
            for n,patch in config.items():rs[str(n)].update(patch)
            result=v.client.put('/api/admin/content',headers=v.admin,json=dict(revision=state['revision'],round_settings=rs))
            assert result.status_code==200,result.text
        result=v.client.post('/api/admin/publish',headers=v.admin);assert result.status_code==200,result.text
        for t in v.teams:
            result=v.client.post('/api/enroll',json=dict(code=t.code,pin='private-password',client_version=8,device_id=t.device,public_key=key_public(t.key)))
            assert result.status_code==200,result.text
            t.auth={'Authorization':'Bearer '+result.json()['token']};sync(t);sync(t)
        control(1,'start')
    v.start=start
    def action(t,n=1,wrong=False):
        state=bridge('state',id=t.id);e=state['event'];p=state['team']['data']['rounds'][str(n)]
        rid=p['route'][p['completed']];clue=next(r for r in e['riddles'] if r['id']==rid)
        c=next(c for c in e['checkpoints'] if (c['id']!=clue['checkpoint_id'] if wrong else c['id']==clue['checkpoint_id']))
        payload=dict(id=str(uuid.uuid4()),seq=t.seq+1,prev_hash=t.previous,event_id=e['id'],revision=e['revision'],team_id=t.id,
            device_id=t.device,round=n,kind='SCAN',control_version=e['rounds'][str(n)]['control_version'],
            elapsed_ms=t.seq*1000,boot_id=1,qr=signed(v.organizer,dict(event_id=e['id'],checkpoint_id=c['id'],nonce=c['nonce'])))
        envelope=signed(t.key,payload);t.seq+=1;t.previous=hashlib.sha256(envelope['payload'].encode()).hexdigest();return envelope
    v.action=action
    def scan(t,n=1,wrong=False):return sync(t,[action(t,n,wrong)])['ack'][0]['result']
    v.scan=scan
    def finish(t,n):
        for _ in range({1:3,2:7,3:2}[n]):assert scan(t,n)=='CHECKPOINT_ACCEPTED'
    v.finish=finish
    def next_round(n):
        control(n,'prepare')
        for t in v.teams:
            if not bridge('state',id=t.id)['team']['data'].get('eliminated_at'):sync(t);sync(t)
        control(n,'start')
    v.next_round=next_round
    return v

def test_three_rounds_no_gps_and_winner_only_in_final(event):
    v=event;v.start()
    for t in v.teams:v.finish(t,1)
    assert all(r['outcome']=='QUALIFIED' for r in v.client.get('/api/leaderboard').json()['rows'])
    v.control(1,'end');v.next_round(2)
    for t in v.teams:v.finish(t,2)
    assert all(r['outcome']=='QUALIFIED' for r in v.client.get('/api/leaderboard').json()['rows'])
    v.control(2,'end');v.next_round(3)
    for t in v.teams:v.finish(t,3)
    rows=v.client.get('/api/leaderboard').json()['rows']
    assert [r['outcome'] for r in rows].count('WINNER')==1
    assert all(r['completed']==r['total']==2 and len(r['solved'])==2 for r in rows)
    assert rows[0]['code']==v.teams[0].code

def test_first_n_cutoff_is_atomic_across_workers(event):
    v=event;v.start({1:dict(qualify_mode='first_n',qualify_limit=1)})
    for t in v.teams[:2]:assert v.scan(t)=='CHECKPOINT_ACCEPTED';assert v.scan(t)=='CHECKPOINT_ACCEPTED'
    envelopes=[v.action(t) for t in v.teams[:2]]
    with ThreadPoolExecutor(2) as pool:
        replies=list(pool.map(lambda item:v.sync(item[0],[item[1]],item[2]),zip(v.teams[:2],envelopes,[URL,URL2])))
    states=[bridge('state',id=t.id)['team']['data'] for t in v.teams]
    assert sum(d['rounds']['1']['status']=='QUALIFIED' for d in states)==1
    assert sum(bool(d.get('eliminated_at')) for d in states)==2
    assert bridge('state',id=v.teams[0].id)['event']['rounds']['1']['status']=='ENDED'
    assert sum(r['ack'][0]['result']=='CHECKPOINT_ACCEPTED' for r in replies)==1

def test_deadline_eliminates_only_unfinished_teams(event):
    v=event;v.start();v.finish(v.teams[0],1)
    e=bridge('state',id=v.teams[0].id)['event'];e['rounds']['1']['started_at']=int(time.time()*1000)-61000;bridge('event',value=e)
    data=v.client.get('/api/leaderboard').json()
    assert data['round_state']['status']=='ENDED'
    assert sorted(r['status'] for r in data['rows'])==['ELIMINATED','ELIMINATED','QUALIFIED']

def test_round_two_first_n_and_neutral_assignment(event):
    v=event;v.start({2:dict(qualify_mode='first_n',qualify_limit=1)})
    for t in v.teams:v.finish(t,1)
    v.control(1,'end');v.next_round(2)
    v.finish(v.teams[0],2)
    assert bridge('state',id=v.teams[1].id)['team']['data']['eliminated_at']
    v.next_round(3);v.finish(v.teams[0],3)
    assert v.client.get('/api/leaderboard').json()['rows'][0]['outcome']=='WINNER'

def test_packages_hide_private_notes_hints_seed_and_qr_coordinates(event):
    v=event;v.start();t=v.teams[0];t.package_hash='';data=v.sync(t)
    package=verify(key_public(v.organizer),data['package'])
    assert package['protocol']==3 and 'assignment_seed' not in package['settings']
    assert all('hint' not in r and 'placement' not in r for r in package['riddles'])
    assert all('lat' not in c and 'lon' not in c for c in package['checkpoints'])
    state=bridge('state',id=t.id)['event']
    assert all(not r.get('hint_enabled',False) for r in state['riddles'])
    firsts=[bridge('state',id=t.id)['team']['data']['rounds']['1']['route'][0] for t in v.teams]
    assert len(set(firsts))==3

def test_print_layouts_are_authenticated_and_no_station_spoilers(event):
    v=event
    assert v.client.get('/api/admin/print/organizer').status_code==401
    organizer=v.client.get('/api/admin/print/organizer',headers=v.admin)
    stations=v.client.get('/api/admin/print/stations',headers=v.admin)
    assert organizer.status_code==stations.status_code==200
    assert organizer.text.count('<article>')==20 and organizer.text.count('data:image/svg+xml;base64,')==20
    assert 'PRIVATE-PLACEMENT' in organizer.text and '&lt;script&gt;test&lt;/script&gt;' in organizer.text
    assert 'PRIVATE-PLACEMENT' not in stations.text and 'QUESTION-' not in stations.text

def test_account_can_be_edited_without_source_changes(event):
    v=event;v.start();t=v.teams[0]
    result=v.client.put('/api/admin/teams/'+t.id,headers=v.admin,json=dict(name='Renamed team',password='new-private-pass'))
    assert result.status_code==200
    row=next(r for r in v.client.get('/api/admin/overview',headers=v.admin).json()['teams'] if r['id']==t.id)
    assert row['name']=='Renamed team'
    body=dict(code=t.code,client_version=8,device_id=t.device,public_key=key_public(t.key),pin='private-password')
    assert v.client.post('/api/enroll',json=body).status_code==401
    body['pin']='new-private-pass'
    assert v.client.post('/api/enroll',json=body).status_code==200
    assert v.client.put('/api/admin/teams/'+t.id,headers=v.admin,json=dict(password='bad')).status_code==400

def test_published_rules_are_frozen_and_invalid_round_categories_rejected(event):
    v=event;e=v.client.get('/api/admin/overview',headers=v.admin).json()['event'];bad=[dict(r) for r in e['riddles']];bad[-1]['difficulty']='neutral'
    assert v.client.put('/api/admin/content',headers=v.admin,json=dict(revision=e['revision'],riddles=bad)).status_code==400
    v.start()
    assert v.client.put('/api/admin/content',headers=v.admin,json=dict(revision=e['revision'],round_settings=rounds())).status_code==409

def test_production_seed_is_empty():
    seed=json.loads((ROOT/'php-server/database/seed.json').read_text('utf-8'))
    assert seed['riddles']==seed['checkpoints']==seed['edges']==[]
    assert seed['settings']['event_format']=='three-round-v3'
