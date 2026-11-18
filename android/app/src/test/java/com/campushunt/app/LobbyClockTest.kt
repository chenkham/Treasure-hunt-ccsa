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
import org.robolectric.shadows.ShadowSystemClock
import java.time.Duration

@RunWith(RobolectricTestRunner::class)
@Config(sdk=[28])
class LobbyClockTest {
    private fun store(): HuntStore {
        val app=RuntimeEnvironment.getApplication();app.deleteDatabase("hunt.db")
        return HuntStore(app).also { store->store.update { state->
            state.put("clock_server",1000000L).put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_boot",store.boot())
            state.put("package",JSONObject().put("routes",JSONObject().put("1",JSONArray(listOf("a")))).put("riddles",JSONArray().put(JSONObject().put("id","a").put("question","Private clue"))))
            state.put("rounds",JSONObject().put("1",round("DRAFT")))
        } }
    }
    private fun round(status: String)=JSONObject().put("status",status).put("started_at",1030000L).put("duration_ms",3600000L).put("paused_ms",0).put("remaining_ms",3600000L)
    @Test fun waitingAndCountdownHidePrefetchedCluesThenStartOffline() {
        val store=store();try {
            assertEquals("WAITING",store.phase());assertNull(store.activeRiddle())
            store.update { it.getJSONObject("rounds").put("1",round("RUNNING")) }
            assertEquals("COUNTDOWN",store.phase());assertEquals(30000L,store.startsIn());assertNull(store.activeRiddle())
            assertEquals(3600000L,store.remaining())
            ShadowSystemClock.advanceBy(Duration.ofSeconds(30))
            assertEquals("PLAYING",store.phase());assertEquals("Private clue",store.activeRiddle()!!.getString("question"));assertEquals(3600000L,store.remaining())
            ShadowSystemClock.advanceBy(Duration.ofSeconds(5));assertEquals(3595000L,store.remaining())
            val boot=store.boot();Settings.Global.putInt(RuntimeEnvironment.getApplication().contentResolver,Settings.Global.BOOT_COUNT,boot+1)
            try { assertEquals("VERIFY_CLOCK",store.phase());assertNull(store.activeRiddle()) }
            finally { Settings.Global.putInt(RuntimeEnvironment.getApplication().contentResolver,Settings.Global.BOOT_COUNT,boot) }
        } finally { store.close() }
    }
    @Test fun delayedPhoneUsesTheSameDeadlineAndFasterClockSampleSurvivesSlowReply() {
        val store=store();try {
            fun reply(time: Long)=JSONObject().put("ack",JSONArray()).put("rounds",JSONObject().put("1",round("RUNNING"))).put("progress",JSONObject()).put("commands",JSONArray()).put("server_time",time)
            store.applySync(reply(1029900),200)
            assertEquals(1030000L,store.serverNow());assertEquals("PLAYING",store.phase());assertEquals(3600000L,store.remaining())
            ShadowSystemClock.advanceBy(Duration.ofSeconds(5))
            assertEquals(3595000L,store.remaining())
            store.applySync(reply(1032000),6000)
            assertEquals(1035000L,store.serverNow());assertEquals(3595000L,store.remaining())
            assertEquals(200L,store.state().getLong("clock_rtt_ms"))
        } finally { store.close() }
    }
    @Test fun unlimitedRoundCountsDownThenStaysPlayableOfflineUntilStopped() {
        val store=store();try {
            store.update { it.getJSONObject("rounds").put("1",round("ENDED")).put("2",round("RUNNING").put("duration_ms",0).put("remaining_ms",JSONObject.NULL))
                it.getJSONObject("package").getJSONObject("routes").put("2",JSONArray(listOf("a"))) }
            assertEquals("COUNTDOWN",store.phase());assertNull(store.activeRiddle())
            ShadowSystemClock.advanceBy(Duration.ofSeconds(30));assertEquals("PLAYING",store.phase());assertEquals(Long.MAX_VALUE,store.remaining())
            ShadowSystemClock.advanceBy(Duration.ofDays(30));assertEquals("PLAYING",store.phase());assertNotNull(store.activeRiddle())
            store.update { it.getJSONObject("rounds").getJSONObject("2").put("status","PAUSED") };assertEquals("PAUSED",store.phase());assertNull(store.activeRiddle())
            store.update { it.getJSONObject("rounds").getJSONObject("2").put("status","ENDED") };assertEquals("ENDED",store.phase());assertEquals(0L,store.remaining())
        } finally { store.close() }
    }
    @Test fun oneHourIsMeasuredFromTheSharedStartIncludingPausedTime() {
        assertEquals(3600000L,Rules.roundRemaining(3600000,1030000,0,1020000,"RUNNING"))
        assertEquals(3595000L,Rules.roundRemaining(3600000,1030000,0,1035000,"RUNNING"))
        assertEquals(3595000L,Rules.roundRemaining(3600000,1030000,10000,1045000,"RUNNING"))
        assertEquals(0L,Rules.roundRemaining(3600000,1030000,0,4630000,"RUNNING"))
    }
}
