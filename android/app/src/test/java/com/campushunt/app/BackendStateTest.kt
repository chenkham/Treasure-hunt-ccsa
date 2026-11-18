package com.campushunt.app
import android.os.SystemClock
import android.provider.Settings
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import org.robolectric.shadows.ShadowSystemClock
import java.time.Duration
import android.util.Base64

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
@GraphicsMode(GraphicsMode.Mode.LEGACY)
class BackendStateTest {
 private fun store(): HuntStore {
  val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
  return HuntStore(app).also { s->s.update { it.put("package",JSONObject().put("settings",JSONObject().put("life_cooldown_ms",900000))) } }
 }
 private fun reply()=JSONObject().put("ack",JSONArray()).put("rounds",JSONObject()).put("progress",JSONObject().put("1",JSONObject().put("completed",1)))
  .put("commands",JSONArray()).put("server_time",1000000L).put("cooldown_until",1900000L).put("cooldown_trigger_seq",3)
  .put("lives_remaining",0).put("eliminated",false)
 private fun withStore(block: (HuntStore)->Unit){val s=store();try{block(s)}finally{s.close()}}
 @Test fun zeroHeartsStartsRestWithoutEliminatingTeam(){withStore { s->s.applySync(reply());assertFalse(s.state().optBoolean("eliminated"));assertEquals(0,s.state().getInt("lives_remaining"));assertTrue(s.cooldownRemaining()!! in 899000..900000) }}
 @Test fun fifteenMinutesRestoresHeartsAndPreservesProgress(){withStore { s->s.applySync(reply());ShadowSystemClock.advanceBy(Duration.ofMinutes(15));s.refreshCooldown();assertEquals(3,s.state().getInt("lives_remaining"));assertEquals(1,s.localProgress(s.state(),1).getInt("completed")) }}
 @Test fun restartInvalidatesLocalCooldownClock(){withStore { s->s.applySync(reply());Settings.Global.putInt(RuntimeEnvironment.getApplication().contentResolver,Settings.Global.BOOT_COUNT,s.boot()+1);assertNull(s.cooldownRemaining());s.refreshCooldown();assertEquals(0,s.state().getInt("lives_remaining")) }}
 @Test fun opponentSnapshotIsRetainedForOfflineDisplay(){withStore { s->s.applySync(reply().put("opponents",JSONObject().put("mode","coarse").put("points",JSONArray())));assertEquals("coarse",s.state().getJSONObject("opponents").getString("mode")) }}
 @Test fun anOldSyncCannotEraseANewlyLoggedInSession(){withStore { s->
  s.update { it.put("token","old") };assertTrue(s.replaceSession("old","renewed"))
  assertFalse(s.replaceSession("old",null));assertEquals("renewed",s.state().getString("token"))
  assertTrue(s.replaceSession("renewed",null));assertFalse(s.state().has("token"));assertTrue(s.state().getBoolean("auth_expired"))
 }}
 @Test fun thirdRoundWaitsForStartAndUsesItsOwnTwoRiddles(){withStore { s->
  s.update { state->state.put("rounds",JSONObject().put("1",JSONObject().put("status","ENDED")).put("2",JSONObject().put("status","ENDED")).put("3",JSONObject().put("status","DRAFT")))
   state.getJSONObject("package").put("routes",JSONObject().put("3",JSONArray(listOf("final-a","final-b"))))
    .put("riddles",JSONArray().put(JSONObject().put("id","final-a").put("title","Final A")).put(JSONObject().put("id","final-b").put("title","Final B"))) }
  assertEquals(2,s.currentRound());s.update { it.getJSONObject("rounds").getJSONObject("3").put("status","RUNNING").put("remaining_ms",3600000);it.put("clock_server",1000000L).put("clock_elapsed",android.os.SystemClock.elapsedRealtime()).put("clock_boot",s.boot()) }
  assertEquals(3,s.currentRound());assertEquals(2,s.roundTotal(3));assertEquals("Final A",s.activeRiddle()!!.getString("title"))
  s.update { it.put("local_progress",JSONObject().put("3",JSONObject().put("completed",1))) }
  assertEquals("Final B",s.activeRiddle()!!.getString("title"))
 }}
 @Test fun qrValidationModeSurvivesClosingTheDatabase(){
  val s=store();s.update { it.getJSONObject("package").getJSONObject("settings").put("qr_validation","signed_code") };s.close()
  val reopened=HuntStore(RuntimeEnvironment.getApplication());try{assertTrue(reopened.qrOnly())}finally{reopened.close()}
 }
 private fun queued(s: HuntStore,id:String,seq:Int){
  val payload=Base64.encodeToString(JSONObject().put("local_penalty",false).toString().toByteArray(Charsets.UTF_8),Base64.NO_WRAP)
  val envelope=JSONObject().put("payload",payload).put("signature","test-only")
  s.writableDatabase.execSQL("INSERT INTO outbox(id,seq,envelope) VALUES(?,?,?)",arrayOf(id,seq,envelope.toString()))
 }
 private fun acknowledgement(id:String,completed:Int)=reply().put("cooldown_until",0).put("lives_remaining",3)
  .put("progress",JSONObject().put("1",JSONObject().put("completed",completed)))
  .put("ack",JSONArray().put(JSONObject().put("id",id).put("result","CHECKPOINT_ACCEPTED")))
 @Test fun offlineQueueSurvivesRestartAndPartialAcknowledgements(){
  val first=store();queued(first,"offline-a",1);queued(first,"offline-b",2)
  first.update{it.put("local_progress",JSONObject().put("1",JSONObject().put("completed",2)))}
  val original=first.pending().toString();first.close()
  val reopened=HuntStore(RuntimeEnvironment.getApplication())
  try{
   assertEquals(original,reopened.pending().toString());assertEquals(2,reopened.pendingCount())
   reopened.applySync(acknowledgement("offline-a",1));assertEquals(1,reopened.pendingCount())
   assertEquals(2,reopened.localProgress(reopened.state(),1).getInt("completed"))
   reopened.applySync(acknowledgement("offline-a",1));assertEquals(1,reopened.pendingCount())
   reopened.applySync(acknowledgement("offline-b",2));assertEquals(0,reopened.pendingCount())
   assertEquals(2,reopened.localProgress(reopened.state(),1).getInt("completed"))
  }finally{reopened.close()}
 }
 @Test fun anInvalidResponseRollsBackAcknowledgements(){withStore{s->
  queued(s,"retained-scan",1)
  val invalid=acknowledgement("retained-scan",1).put("package",JSONObject().put("payload","invalid").put("signature","invalid"))
  try{s.applySync(invalid);fail("Invalid package must not commit acknowledgements")}catch(_:Exception){}
  assertEquals(1,s.pendingCount())
 }}
}
