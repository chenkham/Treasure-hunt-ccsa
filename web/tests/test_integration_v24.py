"""New competition and deployment checks; reuse the disposable HTTP fixture."""
import json,os,subprocess,time
from pathlib import Path
import pytest
from test_http import h,baseline,ack,bridge,ROOT,URL,key_public

def test_cooldown_retains_route_and_progress(h):
    event=bridge('state',id=h.team_id)['event'];event['settings']['life_cooldown_ms']=900000
    bridge('event',value=event)
    before=bridge('state',id=h.team_id)['team']['data']['rounds']['1']['route']
    for elapsed,result in [(1000,'WRONG_SEQUENCE'),(2000,'WRONG_SEQUENCE'),(3000,'COOLDOWN_STARTED')]:
        assert ack(h,h.action(wrong=True,elapsed_ms=elapsed,boot_id=1))==result
    state=h.sync().json();assert not state['eliminated'] and state['lives_remaining']==0
    assert state['cooldown_until']>state['server_time']
    assert ack(h,h.action(elapsed_ms=4000,boot_id=1))=='COOLDOWN'
    assert ack(h,h.action(elapsed_ms=903001,boot_id=1))=='CHECKPOINT_ACCEPTED'
    state=bridge('state',id=h.team_id)['team']['data']
    assert state['lives_remaining']==3 and state['rounds']['1']['route']==before
    assert state['rounds']['1']['completed']==1

def test_cooldown_reboot_needs_review(h):
    e=bridge('state',id=h.team_id)['event'];e['settings']['life_cooldown_ms']=900000;bridge('event',value=e)
    for elapsed in [1000,2000,3000]:ack(h,h.action(wrong=True,elapsed_ms=elapsed,boot_id=1))
    assert ack(h,h.action(elapsed_ms=904000,boot_id=2))=='COOLDOWN'

def test_participant_zone_has_no_exact_gps(h):
    # The source history is authority-only. Test the pure projection of a second team.
    code=r'''require 'php-server/app/bootstrap.php';$config=require getenv('HUNT_TEST_CONFIG');$db=new \Hunt\Database($config);$db->begin();
    try{$e=$db->event();$e['settings']['opponent_map']='coarse';$t=$db->teams()[0];$d=$t['data'];$d['device_id']='fixture';$d['location_history']=[['lat'=>27.448067,'lon'=>94.8953513,'accuracy'=>5,'received_at'=>\Hunt\stamp()-31000]];$t['data']=$d;$db->saveTeam($t);echo \Hunt\json(\Hunt\opponentSnapshot($db,$e,'another-team'));}finally{$db->rollback();}'''
    r=subprocess.run(['php','-r',code],cwd=ROOT,env=os.environ,capture_output=True,text=True,check=True)
    point=json.loads(r.stdout)['points'][0]
    assert abs(point['lat']-27.448067)>1e-8 or abs(point['lon']-94.8953513)>1e-8
    assert point['radius_m']==100 and 'accuracy' not in point and 'device_id' not in point

@pytest.mark.parametrize('version,patched',[('8.4.26',False),('8.4.27',True),('8.3.36',True),('8.2.34',False),('8.5.12',True),('8.1.34',False)])
def test_php_security_gate(version,patched):
    code="require 'php-server/app/bootstrap.php';echo \\Hunt\\json(\\Hunt\\runtimeSecurity([],"+json.dumps(version)+"));"
    result=subprocess.run(['php','-r',code],cwd=ROOT,capture_output=True,text=True,check=True)
    assert json.loads(result.stdout)['patched'] is patched

def test_hostinger_confirmation_must_match_runtime():
    code=r'''require 'php-server/app/bootstrap.php';$c=['verified_php_backport'=>['provider'=>'hostinger','php_version'=>'8.4.26','build_date'=>'2026-10-21','verified_on'=>'2026-10-23']];echo \Hunt\json([\Hunt\runtimeSecurity($c,'8.4.26'),\Hunt\runtimeSecurity($c,'8.3.35')]);'''
    r=subprocess.run(['php','-r',code],cwd=ROOT,capture_output=True,text=True,check=True)
    a,b=json.loads(r.stdout);assert a['patched'] and not b['patched']

def test_preflight_and_atlas_assets(h):
    result=h.client.get('/preflight.php');assert result.status_code==200
    assert result.json()['php_patched'] and result.json()['database']=='connected'
    atlas=h.client.get('/dashboard/atlas/map-data.json').json()
    assert len(atlas['places'])==91
    overview=h.client.get('/api/admin/overview',headers=h.admin).json()
    assert overview['runtime_security']['patched'] and overview['atlas']['places']==91

def test_new_event_rejects_old_android_build(h):
    e=bridge('state',id=h.team_id)['event'];e['settings']['min_android_version']=7;bridge('event',value=e)
    data=dict(code='PHPT',pin='php-test-pin',device_id=h.device,public_key=key_public(h.key),client_version=6)
    assert h.client.post('/api/enroll',json=data).status_code==426
    assert h.sync().status_code==426
