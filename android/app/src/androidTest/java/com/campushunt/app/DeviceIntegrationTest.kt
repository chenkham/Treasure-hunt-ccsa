package com.campushunt.app

import android.content.Context
import android.os.SystemClock
import android.view.MotionEvent
import android.view.View
import android.view.ViewGroup
import android.view.WindowManager
import android.widget.TextView
import androidx.test.core.app.ActivityScenario
import androidx.test.ext.junit.runners.AndroidJUnit4
import androidx.test.platform.app.InstrumentationRegistry
import org.json.JSONArray
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import java.io.File
import java.util.UUID

/** Run only on the disposable HuntQA emulator. All positions/content here are explicit test fixtures. */
@RunWith(AndroidJUnit4::class)
class DeviceIntegrationTest {
    private val context: Context get()=InstrumentationRegistry.getInstrumentation().targetContext
    private fun fixture(): HuntStore {
        val store=HuntStore(context)
        store.update { state ->
            val id=state.getString("device_id")
            val checkpoint=JSONObject().put("id","qa").put("name","QA fixture checkpoint").put("x",2496).put("y",2065)
                .put("lat",27.002065).put("lon",94.002496).put("radius_m",65)
            val riddle=JSONObject().put("id","qa-riddle").put("title","QA fixture clue").put("question","This is a disposable emulator fixture.")
                .put("hint","Test only").put("checkpoint_id","qa").put("difficulty","easy").put("points",100)
                
            val pkg=JSONObject().put("event_id","qa-event").put("revision",1).put("name","QA only").put("team_id","qa-team")
                .put("device_id",id).put("team_name","Emulator QA team").put("workflow","QR_ONLY").put("protocol",2)
                .put("riddles",JSONArray().put(riddle)).put("checkpoints",JSONArray().put(checkpoint)).put("edges",JSONArray())
                .put("routes",JSONObject().put("1",JSONArray().put("qa-riddle")).put("2",JSONArray()))
                .put("calibration",JSONObject().put("lat",JSONArray(listOf(0.0,0.000001,27.0))).put("lon",JSONArray(listOf(0.000001,0.0,94.0))))
                .put("settings",JSONObject().put("require_kiosk",false).put("organizer_phone","QA only"))
            state.put("package",pkg).put("token","qa-token").put("host","http://10.0.2.2:9").put("organizer_key",DeviceCrypto.publicKey())
                .put("rounds",JSONObject().put("1",JSONObject().put("status","RUNNING").put("remaining_ms",3600000).put("control_version",1)).put("2",JSONObject().put("status","DRAFT")))
                .put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_boot",store.boot())
                .put("location",JSONObject().put("lat",27.002065).put("lon",94.002496).put("accuracy",5).put("mock",false))
                .put("location_elapsed",SystemClock.elapsedRealtime()).put("location_boot",store.boot()).put("local_progress",JSONObject()).put("admin_unlocked",true)
        }
        return store
    }
    @Test fun keystoreSignaturesAndDurableOutbox() {
        val store=fixture()
        val payload=JSONObject().put("message","Unicode অসম").put("id",UUID.randomUUID().toString())
        val envelope=DeviceCrypto.sign(payload)
        assertEquals(payload.toString(),DeviceCrypto.verify(DeviceCrypto.publicKey(),envelope).toString())
        val before=store.pendingCount()
        store.record("SCAN",JSONObject().put("riddle_id","qa-riddle"),true)
        val device=store.state().getString("device_id"); store.close()
        val reopened=HuntStore(context)
        assertEquals(before+1,reopened.pendingCount()); assertEquals(device,reopened.state().getString("device_id"))
        assertEquals(1,reopened.localProgress(reopened.state(),1).getInt("completed"))
        File(context.getExternalFilesDir(null),"interop.json").writeText(JSONObject().put("public_key",DeviceCrypto.publicKey()).put("envelope",envelope).toString())
        reopened.close()
    }
    private fun views(v: View): List<View> = listOf(v)+if(v is ViewGroup)(0 until v.childCount).flatMap { views(v.getChildAt(it)) } else emptyList()
    @Test fun nativeMapGesturesAndOrganizerExit() {
        fixture().close()
        ActivityScenario.launch(MainActivity::class.java).use { scenario ->
            InstrumentationRegistry.getInstrumentation().waitForIdleSync()
            scenario.onActivity { activity ->
                val all=views(activity.window.decorView)
                assertTrue(all.filterIsInstance<TextView>().any { it.text.toString().contains("Emulator QA team") })
                val map=all.filterIsInstance<CampusMapView>().single()
                val time=SystemClock.uptimeMillis()
                map.dispatchTouchEvent(MotionEvent.obtain(time,time,MotionEvent.ACTION_DOWN,200f,200f,0))
                map.dispatchTouchEvent(MotionEvent.obtain(time,time+20,MotionEvent.ACTION_MOVE,350f,250f,0))
                map.dispatchTouchEvent(MotionEvent.obtain(time,time+40,MotionEvent.ACTION_UP,350f,250f,0))
                assertFalse(map.following); map.recenter(); assertTrue(map.following)
                val store=HuntStore(activity); val kiosk=Kiosk(activity,store)
                if(kiosk.owner()) {
                    store.update { it.put("admin_unlocked",false) }
                    assertTrue(kiosk.enter()); assertTrue(kiosk.locked())
                    kiosk.exit(); assertFalse(kiosk.locked())
                }
                activity.window.clearFlags(WindowManager.LayoutParams.FLAG_SECURE)
                store.close()
            }
            InstrumentationRegistry.getInstrumentation().waitForIdleSync()
            val screenshot=InstrumentationRegistry.getInstrumentation().uiAutomation.takeScreenshot()
            File(context.getExternalFilesDir(null),"native-map-qa.png").outputStream().use { screenshot.compress(android.graphics.Bitmap.CompressFormat.PNG,100,it) }
            scenario.onActivity { it.window.addFlags(WindowManager.LayoutParams.FLAG_SECURE) }
        }
    }
}
