package com.campushunt.app
import android.graphics.*
import android.os.Looper
import android.view.*
import android.widget.*
import androidx.appcompat.app.AlertDialog
import com.google.android.material.button.MaterialButton
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.*
import org.robolectric.Shadows.shadowOf
import org.robolectric.shadows.ShadowDialog
import org.robolectric.annotation.*
import java.io.File
import java.time.Duration

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[35],qualifiers="w411dp-h891dp-420dpi")
@GraphicsMode(GraphicsMode.Mode.NATIVE)
class PreviewFlowTest {
 private fun views(v: View): List<View> = listOf(v)+if(v is ViewGroup) (0 until v.childCount).flatMap { views(v.getChildAt(it)) } else emptyList()
 private fun button(a: MainActivity,label: String)=views(a.window.decorView).filterIsInstance<MaterialButton>().first { it.text.toString()==label }
 private fun settle(ms: Long=300)=shadowOf(Looper.getMainLooper()).idleFor(Duration.ofMillis(ms))
 private fun tab(a: MainActivity,name: String) { views(a.window.decorView).first { it.contentDescription?.toString()==name }.performClick();settle() }
 private fun screenshot(a: MainActivity,name: String) {
  val decor=a.window.decorView;val d=a.resources.displayMetrics.density;val w=(411*d).toInt();val h=(891*d).toInt()
  decor.measure(View.MeasureSpec.makeMeasureSpec(w,View.MeasureSpec.EXACTLY),View.MeasureSpec.makeMeasureSpec(h,View.MeasureSpec.EXACTLY));decor.layout(0,0,w,h)
  val bitmap=Bitmap.createBitmap(w,h,Bitmap.Config.ARGB_8888);decor.draw(Canvas(bitmap))
  val dir=File(System.getProperty("hunt.screenshots"));dir.mkdirs()
  File(dir,"$name.png").outputStream().use { bitmap.compress(Bitmap.CompressFormat.PNG,100,it) };bitmap.recycle()
 }
 private fun scan(a: MainActivity,correct: Boolean) {
  button(a,"Scan a QR seal").performClick();settle()
  (ShadowDialog.getLatestDialog() as AlertDialog).getButton(if(correct) AlertDialog.BUTTON_POSITIVE else AlertDialog.BUTTON_NEUTRAL).performClick();settle()
  if(!correct) { (ShadowDialog.getLatestDialog() as AlertDialog).getButton(AlertDialog.BUTTON_POSITIVE).performClick();settle() }
 }
 @Test fun anyLoginQrOnlyLivesAndTwoRoundFlow() {
  assertTrue(BuildConfig.UI_PREVIEW);RuntimeEnvironment.getApplication().deleteDatabase("hunt.db")
  val controller=Robolectric.buildActivity(MainActivity::class.java).setup();val a=controller.get()
  settle(800);screenshot(a,"01-welcome")
  val edits=views(a.window.decorView).filterIsInstance<EditText>();assertEquals(2,edits.size)
  edits[0].setText("Pixel Explorers");edits[1].setText("any-password-works")
  button(a,"Start hunt").performClick();settle(4000)
  val store=HuntStore(a)
  assertEquals("Pixel Explorers",store.packageData()!!.getString("team_name"))
  assertFalse(store.state().toString().contains("any-password-works"))
  assertEquals(123,store.packageData()!!.getJSONArray("checkpoints").length())
  assertEquals("QR_ONLY",store.packageData()!!.getString("workflow"))
  assertTrue(views(a.window.decorView).filterIsInstance<EditText>().isEmpty());screenshot(a,"00-home")
  tab(a,"QUEST");screenshot(a,"02-quest")
  scan(a,true);assertEquals(1,store.localProgress(store.state(),1).getInt("completed"));screenshot(a,"08-seal-notice")
  assertEquals(3,store.state().getInt("lives_remaining"))
  tab(a,"QUEST");settle(500);screenshot(a,"03-world")
  views(a.window.decorView).filterIsInstance<CampusMapView>().single().recenter();settle(600);screenshot(a,"04-character")
  tab(a,"RACE");screenshot(a,"05-race")
  tab(a,"RACE")
  val atlasMap=views(a.window.decorView).filterIsInstance<CampusMapView>().single()
  atlasMap.showDestination(atlasMap.places().single { it.getString("name")=="Department of Chemistry" });settle(600);screenshot(a,"09-chemistry")
  atlasMap.showDestination(atlasMap.places().single { it.getString("name").contains("(DUIET)") });settle(600);screenshot(a,"10-duiet")
  tab(a,"QUEST");scan(a,false);scan(a,false);scan(a,false)
  assertEquals(0,store.state().getInt("lives_remaining"));assertTrue(store.state().getBoolean("eliminated"))
  assertTrue(views(a.window.decorView).filterIsInstance<TextView>().any { it.text.toString()=="ELIMINATED" });screenshot(a,"06-eliminated")
  LocalPreview.install(a,store,"Pixel Explorers");tab(a,"QUEST");tab(a,"QUEST")
  repeat(3) { scan(a,true) };button(a,"Enter round two").performClick();settle()
  repeat(7) { scan(a,true) };assertEquals(7,store.localProgress(store.state(),2).getInt("completed"))
  tab(a,"RACE");screenshot(a,"07-winner")
  assertTrue(views(a.window.decorView).filterIsInstance<TextView>().any { it.text.contains("WINNER") })
  assertEquals(0,store.pendingCount());store.close();controller.pause().stop().destroy()
 }
 @Test fun atlasAndGpsStayGeographicAndTheOldMapIsAbsent() {
  val context=RuntimeEnvironment.getApplication();assertFalse(context.assets.list("")!!.contains("campus_map.webp"))
  assertTrue(context.assets.list("")!!.contains("title_cover.webp"));assertFalse(context.assets.list("")!!.contains("welcome_expedition.webp"));assertFalse(context.assets.list("")!!.contains("blitz_logo.webp"))
  val map=CampusMapView(context);assertEquals(91,map.places().size)
  assertTrue(map.contains(27.44925,94.89659));assertFalse(map.contains(27.0,94.0));assertFalse(map.contains(Double.NaN,94.9))
  val point=map.places().first();map.showDestination(point);map.fitCampus();map.recenter();map.zoomBy(1.4f)
 }
 @Test fun reviewedPinsReplaceTheHostelCoordinateAndStaleLabelsCannotReturn() {
  val map=CampusMapView(RuntimeEnvironment.getApplication());val places=map.places()
  val chemistry=places.single { it.getString("name")=="Department of Chemistry" }
  assertEquals(27.448067,chemistry.getDouble("lat"),.0000001);assertEquals(94.8953513,chemistry.getDouble("lon"),.0000001)
  val aideu=places.single { it.getString("name")=="Aideu Handique Girls Hostel" }
  assertTrue(Rules.meters(chemistry.getDouble("lat"),chemistry.getDouble("lon"),aideu.getDouble("lat"),aideu.getDouble("lon"))>400)
  assertTrue(places.any { it.getString("name").contains("Computer Science and Engineering")&&it.getDouble("lon")<94.893 })
  assertTrue(places.any { it.getString("name")=="Centre for Computer Science and Applications" })
  assertFalse(places.any { it.getString("name").contains("Physical Education")||it.getString("name").contains("Studies in Languages") })
  assertTrue(places.all { it.has("source_url")&&it.has("reviewed_on") })
  val stale=org.json.JSONObject().put("id","stale-chemistry").put("name","OLD WRONG CHEMISTRY").put("kind","landmark").put("verified",false).put("lat",27.447119).put("lon",94.899645)
  map.setData(org.json.JSONObject().put("event_id","old-package").put("revision",1).put("checkpoints",org.json.JSONArray().put(stale)),null,null)
  assertFalse(map.places().any { it.optString("id")=="stale-chemistry" });assertEquals(91,map.places().size)
 }
}
