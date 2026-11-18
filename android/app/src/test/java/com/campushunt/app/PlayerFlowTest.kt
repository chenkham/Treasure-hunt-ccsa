package com.campushunt.app

import android.os.Looper
import android.os.SystemClock
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.TextView
import androidx.appcompat.app.AlertDialog
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.Robolectric
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import org.robolectric.annotation.GraphicsMode
import java.time.Duration

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
@GraphicsMode(GraphicsMode.Mode.LEGACY)
class PlayerFlowTest {
 private fun views(v: View): List<View> = listOf(v)+if(v is ViewGroup)(0 until v.childCount).flatMap { views(v.getChildAt(it)) }else emptyList()
 private fun texts(v: View)=views(v).filterIsInstance<TextView>()
 private fun field(activity: MainActivity,name: String)=MainActivity::class.java.getDeclaredField(name).apply { isAccessible=true }.get(activity)
 private fun refresh(activity: MainActivity)=MainActivity::class.java.getDeclaredMethod("refresh").apply { isAccessible=true }.invoke(activity)
 private fun player(block: (MainActivity,HuntStore)->Unit){
  val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
  try{androidx.work.WorkManager.getInstance(app)}catch(_:IllegalStateException){androidx.work.WorkManager.initialize(app,androidx.work.Configuration.Builder().build())}
  val store=HuntStore(app)
  store.update { s->
   s.put("token","test-only").put("clock_server",1000000L).put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_boot",store.boot())
   val riddles=JSONArray();for(id in listOf("a","b","c"))riddles.put(JSONObject().put("id",id).put("title","Quest").put("question","Question $id").put("checkpoint_id","station-$id"))
   s.put("package",JSONObject().put("event_id","test").put("run_id","run").put("team_id","team").put("team_name","Moon runners").put("device_id",s.getString("device_id")).put("revision",1)
    .put("riddles",riddles).put("routes",JSONObject().put("1",JSONArray(listOf("a","b","c")))).put("checkpoints",JSONArray()).put("edges",JSONArray()).put("settings",JSONObject().put("require_kiosk",false)))
   s.put("rounds",JSONObject().put("1",JSONObject().put("status","RUNNING").put("remaining_ms",3600000))).put("local_progress",JSONObject().put("1",JSONObject().put("completed",1)))
   s.put("lives_remaining",3)
  }
  val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val activity=controller.get()
  try{block(activity,store)}finally{controller.pause().stop().destroy();store.close()}
 }
 @Test fun correctScanWaitsForUnlockAndDoesNotLoseNoticeOnRestart(){player { activity,store->
  store.update { it.put("scan_notice",JSONObject().put("title","CORRECT!").put("detail","Unlock your next riddle.").put("button","Unlock next riddle").put("correct",true)) }
  val reopened=HuntStore(activity);try{assertTrue(reopened.state().has("scan_notice"))}finally{reopened.close()}
  refresh(activity);val dialog=field(activity,"gameDialog") as AlertDialog
  assertTrue(dialog.isShowing);assertTrue(dialog.window!!.attributes.flags and WindowManager.LayoutParams.FLAG_SECURE!=0)
  assertFalse(texts(activity.window.decorView).any { it.text.toString()=="Question b" })
  texts(dialog.window!!.decorView).single { it.text.toString()=="Unlock next riddle" }.performClick()
  assertFalse(store.state().has("scan_notice"));assertTrue(texts(activity.window.decorView).any { it.text.toString()=="Question b" })
  assertFalse(texts(activity.window.decorView).any { it.text.toString().contains("hint",true)||it.text.toString().startsWith("Round ") })
  assertFalse(texts(activity.window.decorView).any { Regex("(?i)verified|pending|syncing|connected|GPS active").containsMatchIn(it.text) })
  assertFalse(texts(activity.window.decorView).any { it.text.toString() in listOf("Zoom +","Zoom -","Orient north","Organizer controls") })
 }}
 @Test fun wrongScanShowsAFullPopupAndKeepsTheCurrentRiddle(){player { activity,store->
  store.update { it.put("lives_remaining",2).put("scan_notice",JSONObject().put("title","WRONG CHECKPOINT").put("detail","One heart lost. 2 hearts remain.").put("button","Continue").put("correct",false)) }
  refresh(activity);val dialog=field(activity,"gameDialog") as AlertDialog
  assertTrue(texts(dialog.window!!.decorView).any { it.text.toString()=="WRONG CHECKPOINT" })
  texts(dialog.window!!.decorView).single { it.text.toString()=="Continue" }.performClick()
  assertEquals(2,store.state().getInt("lives_remaining"));assertTrue(texts(activity.window.decorView).any { it.text.toString()=="Question b" })
 }}
 @Test fun recoveryKeepsTheRiddleVisibleAndUnlocksScanningAfterReset(){player { activity,store->
  store.update { it.put("lives_remaining",0).put("local_cooldown_end",SystemClock.elapsedRealtime()+900000).put("local_cooldown_boot",store.boot()) }
  MainActivity::class.java.getDeclaredField("screen").apply { isAccessible=true }.set(activity,"CLUE")
  refresh(activity)
  var labels=texts(activity.window.decorView).map { it.text.toString() }
  assertTrue("Question b" in labels);assertTrue("HEARTS RECOVERING" in labels)
  assertTrue(labels.any { it.contains("Think about this riddle") });assertFalse("Scan location QR" in labels)
  store.update { it.put("local_cooldown_end",SystemClock.elapsedRealtime()-1) };refresh(activity)
  labels=texts(activity.window.decorView).map { it.text.toString() }
  assertEquals(3,store.state().getInt("lives_remaining"));assertTrue("Question b" in labels)
  assertTrue("Scan location QR" in labels);assertFalse("HEARTS RECOVERING" in labels)
 }}
 @Test fun draggingHidesMapPanelsThenRestoresThem(){player { activity,_->
  val map=field(activity,"map") as CampusMapView;map.layout(0,0,1080,1600)
  val now=SystemClock.uptimeMillis()
  fun touch(action: Int,after: Long,x: Float){MotionEvent.obtain(now,now+after,action,x,600f,0).also { map.onTouchEvent(it);it.recycle() }}
  touch(MotionEvent.ACTION_DOWN,0,700f);touch(MotionEvent.ACTION_MOVE,100,300f)
  assertTrue(map.controlsHidden)
  touch(MotionEvent.ACTION_UP,200,300f);shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(500))
  assertFalse(map.controlsHidden)
  val labels=texts(activity.window.decorView).map { it.text.toString() }
  assertTrue("Continue question" in labels);assertFalse("Zoom +" in labels)
 }}
 @Test fun mapControlsRecoverIfAFingerUpEventIsLost(){player { activity,_->
  MainActivity::class.java.getDeclaredMethod("mapInteraction",Boolean::class.javaPrimitiveType).apply { isAccessible=true }.invoke(activity,true)
  assertTrue((field(activity,"map") as CampusMapView).controlsHidden)
  shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(900))
  assertFalse((field(activity,"map") as CampusMapView).controlsHidden)
 }}
 @Test fun helplineReadsDashboardNumberAndHasNoDefault(){player { activity,store->
  store.update { it.getJSONObject("package").getJSONObject("settings").put("organizer_phone","+91 12345 67890") }
  val method=MainActivity::class.java.getDeclaredMethod("emergencyMenu").apply { isAccessible=true };method.invoke(activity)
  var dialog=org.robolectric.shadows.ShadowDialog.getLatestDialog()
  assertTrue(texts(dialog.window!!.decorView).any { it.text.toString()=="+91 12345 67890" });dialog.dismiss()
  store.update { it.getJSONObject("package").getJSONObject("settings").put("organizer_phone","") };method.invoke(activity)
  dialog=org.robolectric.shadows.ShadowDialog.getLatestDialog();assertTrue(texts(dialog.window!!.decorView).any { it.text.toString().contains("No helpline") });dialog.dismiss()
 }}
 @Test fun compactPhoneWithLargeTextHasNoOverlappingTabsOrClippedCredits(){player { activity,_->
  val density=activity.resources.displayMetrics.density
  activity.resources.displayMetrics.scaledDensity=density*1.5f
  MainActivity::class.java.getDeclaredMethod("render").apply { isAccessible=true }.invoke(activity)
  fun layout(){val decor=activity.window.decorView;val w=(320*density).toInt();val h=(640*density).toInt();decor.measure(View.MeasureSpec.makeMeasureSpec(w,View.MeasureSpec.EXACTLY),View.MeasureSpec.makeMeasureSpec(h,View.MeasureSpec.EXACTLY));decor.layout(0,0,w,h)}
  layout();layout()
  val all=views(activity.window.decorView)
  val hud=all.single { it.tag=="hunt-hud" };val tabs=all.single { it.tag=="hunt-tabs" } as ViewGroup
  assertTrue(tabs.top>=hud.bottom);assertTrue(tabs.getChildAt(0).right<=tabs.getChildAt(1).left)
  for(tag in listOf("hunt-credit","hunt-attribution")){
   val label=all.single { it.tag==tag } as TextView
   assertNotNull(label.layout);assertTrue(label.height>=label.layout.height+label.compoundPaddingTop+label.compoundPaddingBottom)
   assertTrue(label.bottom<=(label.parent as View).height)
  }
  assertTrue(texts(activity.window.decorView).any { it.text.toString()=="Developed by Blitz Club" })
  activity.resources.displayMetrics.scaledDensity=density
 }}
}
