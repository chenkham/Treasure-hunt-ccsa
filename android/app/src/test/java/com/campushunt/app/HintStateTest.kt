package com.campushunt.app

import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.annotation.Config
import java.security.KeyPairGenerator
import java.security.Signature
import java.security.spec.ECGenParameterSpec

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class HintStateTest {
 private fun store(): HuntStore {
  val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
  return HuntStore(app).also { s->s.update { state->state.put("clock_server",1000000L).put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_boot",s.boot())
   state.put("package",JSONObject().put("device_id",state.getString("device_id")).put("run_id","before").put("routes",JSONObject().put("1",JSONArray(listOf("d")))).put("riddles",JSONArray().put(JSONObject().put("id","d").put("hint","New hint"))).put("settings",JSONObject()))
   state.put("rounds",JSONObject().put("1",JSONObject().put("status","RUNNING").put("remaining_ms",3600000)))
  } }
 }
 private fun reply()=JSONObject().put("ack",JSONArray()).put("rounds",JSONObject().put("1",JSONObject().put("status","RUNNING").put("remaining_ms",3600000))).put("progress",JSONObject()).put("commands",JSONArray()).put("server_time",1000000L).put("lives_remaining",3)
 @Test fun threeHintsSurviveRestartRoundChangesAndOlderServerReplies(){
  var s=store();s.update { it.put("hints_used",JSONArray(listOf("a","b","c"))) };s.close();s=HuntStore(RuntimeEnvironment.getApplication())
  try{
   assertEquals(0,s.hintsRemaining());assertThrows(IllegalStateException::class.java){s.revealHint("d")};assertEquals(0,s.pendingCount())
   s.applySync(reply().put("hints_used",JSONArray(listOf("a"))));assertEquals(0,s.hintsRemaining())
   s.update{it.getJSONObject("rounds").getJSONObject("1").put("status","ENDED");it.getJSONObject("rounds").put("2",JSONObject().put("status","RUNNING").put("remaining_ms",3600000));it.getJSONObject("package").getJSONObject("routes").put("2",JSONArray(listOf("d")))}
   assertThrows(IllegalStateException::class.java){s.revealHint("d")};assertEquals(0,s.hintsRemaining())
  }finally{s.close()}
 }
 @Test fun reopeningAnExistingHintIsFree(){val s=store();try{
  s.update{it.put("hints_used",JSONArray(listOf("a","b","d")));it.put("hint_reveals",JSONObject().put("d","Old hint"))}
  assertEquals("New hint",s.revealHint("d"));assertEquals("New hint",s.revealHint("d"));assertEquals(0,s.hintsRemaining());assertEquals(0,s.pendingCount())
 }finally{s.close()}}
 @Test fun signedResetArchivesOldEvidenceAndRestoresOnlyTheNewRunBudget(){val s=store();try{
  val pair=KeyPairGenerator.getInstance("EC").apply{initialize(ECGenParameterSpec("secp256r1"))}.generateKeyPair()
  val old=JSONObject().put("payload",DeviceCrypto.encode(JSONObject().put("run_id","before").put("local_penalty",true).toString().toByteArray())).put("signature","local-old-proof")
  s.writableDatabase.execSQL("INSERT INTO outbox(id,seq,envelope) VALUES(?,?,?)",arrayOf("old",3,old.toString()))
  s.update{it.put("organizer_key",DeviceCrypto.encode(pair.public.encoded)).put("hints_used",JSONArray(listOf("a","b","c"))).put("local_progress",JSONObject().put("1",JSONObject().put("completed",2))).put("seq",3).put("hash","old-hash")}
  val pkg=JSONObject(s.packageData().toString()).put("run_id","after")
  val bytes=pkg.toString().toByteArray();val signature=Signature.getInstance("SHA256withECDSA").apply{initSign(pair.private);update(bytes)}.sign()
  val envelope=JSONObject().put("payload",DeviceCrypto.encode(bytes)).put("signature",DeviceCrypto.encode(signature))
  val response=reply().put("package",envelope).put("package_hash","after-hash").put("last_seq",2).put("last_hash","confirmed-hash").put("hints_used",JSONArray())
  s.applySync(response)
  assertEquals(3,s.hintsRemaining());assertEquals(0,s.pendingCount());assertEquals(3,s.state().getInt("lives_remaining"));assertEquals(2,s.state().getInt("seq"));assertEquals("confirmed-hash",s.state().getString("hash"));assertEquals(1,s.export().getJSONArray("events").length())
  val next=JSONObject().put("payload",DeviceCrypto.encode(JSONObject().put("run_id","after").toString().toByteArray())).put("signature","new-local-proof")
  s.writableDatabase.execSQL("INSERT INTO outbox(id,seq,envelope) VALUES(?,?,?)",arrayOf("new",3,next.toString()))
  s.applySync(response);assertEquals(1,s.pendingCount())
 }finally{s.close()}}
}
