package com.campushunt.app

import android.content.Context
import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject

/** Standalone, explicitly simulated QR expedition. No network calls or official evidence. */
object LocalPreview {
    fun install(context: Context,store: HuntStore,name: String) {
        check(BuildConfig.UI_PREVIEW)
        val content=JSONObject(context.assets.open("ui_preview.json").bufferedReader().use { it.readText() })
        val routes=JSONObject().put("1",JSONArray(listOf("riddle-1","preview-riddle-1","preview-riddle-2")))
            .put("2",JSONArray(listOf("preview-riddle-0","preview-riddle-3","preview-riddle-4","preview-riddle-5","preview-riddle-6","riddle-2","riddle-3")))
        val pkg=JSONObject().put("protocol",2).put("design_version",5).put("workflow","QR_ONLY")
            .put("event_id","offline-atlas-expedition-v5").put("revision",5).put("name","Anazori Expedition")
            .put("team_id","local-preview-team").put("team_name",name.trim().ifBlank { "Explorer" }.take(40))
            .put("device_id",store.state().getString("device_id")).put("riddles",content.getJSONArray("riddles"))
            .put("checkpoints",content.getJSONArray("checkpoints")).put("edges",content.getJSONArray("edges")).put("routes",routes)
            .put("map_format","CAMPUS_MAP_V1")
            .put("settings",JSONObject().put("require_kiosk",false).put("ui_preview",true).put("workflow","QR_ONLY").put("max_lives",3))
        store.writableDatabase.execSQL("DELETE FROM outbox")
        store.update { s ->
            listOf("organizer_key","host","commands","applied_commands","sync_error","last_rejection","package_hash","elimination_reason").forEach { s.remove(it) }
            s.put("package",pkg).put("token","local-ui-preview").put("seq",0).put("hash","")
                .put("lives_remaining",3).put("eliminated",false).put("admin_unlocked",true).put("needs_review",false)
                .put("local_progress",JSONObject()).put("official_progress",JSONObject())
                .put("rounds",JSONObject().put("1",round("RUNNING")).put("2",round("DRAFT")))
                .put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_server",System.currentTimeMillis()).put("clock_boot",store.boot())
                .put("location",fix(27.4497,94.8945)).put("location_elapsed",SystemClock.elapsedRealtime()).put("location_boot",store.boot())
            s.put("leaderboard",board(s,store))
        }
    }
    private fun round(status: String)=JSONObject().put("status",status).put("remaining_ms",3600000L).put("control_version",1)
    private fun fix(lat: Double,lon: Double)=JSONObject().put("lat",lat).put("lon",lon).put("accuracy",6).put("mock",true)
    fun location(state: JSONObject)=state.optJSONObject("location")
    fun arrive(store: HuntStore,checkpoint: JSONObject) {
        check(BuildConfig.UI_PREVIEW)
        if(store.state().optInt("lives_remaining",3)<=0) return
        store.update { it.put("location",fix(checkpoint.getDouble("lat"),checkpoint.getDouble("lon"))) }
        store.record("SCAN",JSONObject().put("riddle_id",store.activeRiddle()?.getString("id")).put("preview",true),true)
    }
    fun wrongScan(store: HuntStore) {
        check(BuildConfig.UI_PREVIEW)
        store.record("SCAN",JSONObject().put("local_penalty",true).put("preview",true),false)
    }
    fun roundTwo(store: HuntStore) {
        check(BuildConfig.UI_PREVIEW)
        store.update { it.getJSONObject("rounds").put("2",round("RUNNING"));it.put("clock_elapsed",SystemClock.elapsedRealtime()).put("clock_boot",store.boot());it.put("leaderboard",board(it,store)) }
    }
    fun record(store: HuntStore,kind: String,fields: JSONObject,accepted: Boolean): JSONObject {
        check(BuildConfig.UI_PREVIEW)
        store.update { state ->
            if(state.optInt("lives_remaining",3)<=0) return@update
            state.put("seq",state.optInt("seq")+1)
            if(fields.optBoolean("local_penalty")) {
                val lives=(state.optInt("lives_remaining",3)-1).coerceAtLeast(0)
                state.put("lives_remaining",lives).put("eliminated",lives==0)
                if(lives==0) state.put("elimination_reason","THREE_WRONG_SEQUENCE_SCANS")
            } else if(accepted && kind=="SCAN") {
                val n=store.currentRound(state);val all=state.optJSONObject("local_progress") ?: JSONObject();val p=store.localProgress(state,n)
                p.put("completed",p.optInt("completed")+1).put("score",p.optInt("score")+100)
                p.put("status",if(p.optInt("completed")>=if(n==1)3 else 7) if(n==1)"QUALIFIED" else "FINISHED" else "PLAYING")
                all.put(n.toString(),p);state.put("local_progress",all)
            }
            state.put("leaderboard",board(state,store))
        }
        return JSONObject(fields.toString()).put("preview",true)
    }
    fun board(state: JSONObject,store: HuntStore): JSONObject {
        val p=store.localProgress(state,store.currentRound(state));val total=if(store.currentRound(state)==1)3 else 7
        val lost=state.optBoolean("eliminated");val done=p.optInt("completed")>=total
        val rows=mutableListOf(
            JSONObject().put("team","The Pathfinders").put("completed",2).put("total",total).put("status","PLAYING").put("score",200).put("lives_remaining",2),
            JSONObject().put("team",state.optJSONObject("package")?.optString("team_name") ?: "Explorer").put("completed",p.optInt("completed"))
                .put("total",total).put("status",if(lost)"ELIMINATED" else if(done) if(total==7)"FINISHED" else "QUALIFIED" else "PLAYING")
                .put("score",p.optInt("score")).put("lives_remaining",state.optInt("lives_remaining",3)).put("you",true),
            JSONObject().put("team","Campus Nomads").put("completed",1).put("total",total).put("status","PLAYING").put("score",100).put("lives_remaining",3))
        val sorted=rows.sortedWith(compareBy<JSONObject> { if(it.optString("status")=="ELIMINATED")2 else if(it.optString("status") in listOf("FINISHED","QUALIFIED"))0 else 1 }.thenByDescending { it.getInt("completed") })
        sorted.forEachIndexed { i,r -> r.put("rank",i+1).put("outcome",if(r.optString("status")=="FINISHED"&&i==0)"WINNER" else r.optString("status")) }
        return JSONObject().put("revision",state.optInt("seq")).put("rows",JSONArray(sorted)).put("preview",true)
    }
}
