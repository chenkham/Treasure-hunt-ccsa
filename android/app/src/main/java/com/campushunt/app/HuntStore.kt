package com.campushunt.app

import android.content.Context
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper
import android.os.SystemClock
import android.provider.Settings
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

class HuntStore(private val context: Context): SQLiteOpenHelper(context,"hunt.db",null,1) {
    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL("CREATE TABLE state(id INTEGER PRIMARY KEY CHECK(id=1), data TEXT NOT NULL)")
        db.execSQL("CREATE TABLE outbox(id TEXT PRIMARY KEY, seq INTEGER UNIQUE NOT NULL, envelope TEXT NOT NULL, result TEXT)")
        db.execSQL("INSERT INTO state VALUES(1,?)",arrayOf(JSONObject().put("device_id",UUID.randomUUID().toString()).put("seq",0).put("hash","").toString()))
    }
    override fun onUpgrade(db: SQLiteDatabase, old: Int, new: Int) { error("Unsupported schema migration; export evidence before updating") }
    @Synchronized fun state(): JSONObject=readableDatabase.rawQuery("SELECT data FROM state WHERE id=1",null).use { it.moveToFirst(); JSONObject(it.getString(0)) }
    private fun save(db: SQLiteDatabase, state: JSONObject) { db.execSQL("UPDATE state SET data=? WHERE id=1",arrayOf(state.toString())) }
    @Synchronized fun update(change: (JSONObject)->Unit) {
        val db=writableDatabase; db.beginTransaction()
        try { val state=state(); change(state); save(db,state); db.setTransactionSuccessful() } finally { db.endTransaction() }
    }
    fun replaceSession(expected: String,replacement: String?): Boolean {
        var changed=false
        update { if(it.optString("token")==expected) {
            if(replacement==null){it.remove("token");it.put("auth_expired",true)}
            else {it.put("token",replacement);it.remove("auth_expired")}
            changed=true
        } }
        return changed
    }
    fun boot(): Int=Settings.Global.getInt(context.contentResolver,Settings.Global.BOOT_COUNT,-1)
    fun packageData(): JSONObject?=state().optJSONObject("package")
    fun pendingCount(): Int=readableDatabase.rawQuery("SELECT COUNT(*) FROM outbox WHERE result IS NULL",null).use { it.moveToFirst(); it.getInt(0) }
    fun pending(): JSONArray=JSONArray().apply {
        readableDatabase.rawQuery("SELECT envelope FROM outbox WHERE result IS NULL ORDER BY seq LIMIT 32",null).use {
            while (it.moveToNext()) put(JSONObject(it.getString(0)))
        }
    }
    fun currentRound(state: JSONObject=state()): Int {
        val rounds=state.optJSONObject("rounds") ?: return 1
        val count=state.optJSONObject("package")?.optJSONObject("settings")?.optInt("round_count",3)?.coerceIn(1,3)?:3
        return (count downTo 1).firstOrNull { rounds.has(it.toString()) && rounds.getJSONObject(it.toString()).optString("status","DRAFT")!="DRAFT" } ?: 1
    }
    fun roundTotal(n: Int,state: JSONObject=state()): Int = state.optJSONObject("package")?.optJSONObject("routes")?.optJSONArray(n.toString())?.length()?.takeIf { it>0 }
        ?: state.optJSONObject("package")?.optJSONObject("settings")?.optJSONObject("round_sizes")?.optInt(n.toString())?.takeIf { it>0 } ?: when(n){1->3;3->2;else->7}
    fun qrOnly(state: JSONObject=state()): Boolean=state.optJSONObject("package")?.optJSONObject("settings")?.optString("qr_validation")=="signed_code"
    fun localProgress(state: JSONObject, n: Int): JSONObject = state.optJSONObject("local_progress")?.optJSONObject(n.toString()) ?: JSONObject().put("completed",0).put("answered",false)
    fun remaining(state: JSONObject=state()): Long? {
        val r=state.optJSONObject("rounds")?.optJSONObject(currentRound(state).toString()) ?: return null
        if(r.optString("status")=="DRAFT")return null
        if(r.optLong("started_at")>0){
            val now=serverNow(state) ?: return null
            return Rules.roundRemaining(r.optLong("duration_ms"),r.getLong("started_at"),r.optLong("paused_ms"),if(r.optString("status")=="PAUSED")r.optLong("paused_at",now)else now,r.optString("status"))
        }
        return Rules.clockRemaining(r.optLong("remaining_ms"),r.optString("status"),state.optLong("clock_elapsed"),
            SystemClock.elapsedRealtime(),state.optInt("clock_boot",-2)==boot())
    }
    fun serverNow(state: JSONObject=state()): Long? {
        val elapsed=SystemClock.elapsedRealtime()-state.optLong("clock_elapsed")
        if(!state.has("clock_server")||state.optInt("clock_boot",-2)!=boot()||elapsed<0)return null
        return state.getLong("clock_server")+elapsed
    }
    fun startsIn(state: JSONObject=state()): Long? {
        val r=state.optJSONObject("rounds")?.optJSONObject(currentRound(state).toString()) ?: return null
        val now=serverNow(state) ?: return null
        return if(r.optString("status")=="RUNNING")(r.optLong("started_at")+r.optLong("paused_ms")-now).coerceAtLeast(0)else 0
    }
    fun phase(state: JSONObject=state()): String {
        val r=state.optJSONObject("rounds")?.optJSONObject(currentRound(state).toString()) ?: return "WAITING"
        val status=r.optString("status","DRAFT")
        if(status=="DRAFT")return "WAITING"
        if(status!="RUNNING")return status
        if(serverNow(state)==null)return "VERIFY_CLOCK"
        if((startsIn(state)?:0)>0)return "COUNTDOWN"
        return if((remaining(state)?:0)>0)"PLAYING" else "ENDED"
    }
    fun activeRiddle(state: JSONObject=state()): JSONObject? {
        if(!BuildConfig.UI_PREVIEW&&phase(state)!="PLAYING")return null
        val pkg=state.optJSONObject("package") ?: return null
        val n=currentRound(state); val route=pkg.optJSONObject("routes")?.optJSONArray(n.toString()) ?: return null
        val index=localProgress(state,n).optInt("completed")
        if(index>=route.length()) return null
        val id=route.getString(index); val riddles=pkg.getJSONArray("riddles")
        return (0 until riddles.length()).map { riddles.getJSONObject(it) }.firstOrNull { it.getString("id")==id }
    }
    fun hintIds(state: JSONObject=state()): Set<String> {
        val used=state.optJSONArray("hints_used")?:JSONArray();val revealed=state.optJSONObject("hint_reveals")?:JSONObject()
        return (0 until used.length()).map { used.getString(it) }.toSet()+revealed.keys().asSequence().toSet()
    }
    fun hintsRemaining(state: JSONObject=state()): Int=(3-hintIds(state).size).coerceAtLeast(0)
    @Synchronized fun revealHint(id: String): String {
        val state=state();val active=activeRiddle(state)
        check(active?.optString("id")==id) { "Open your current quest during play to use a hint." }
        val revealed=state.optJSONObject("hint_reveals")?:JSONObject()
        if(revealed.has(id)){
            val updated=active!!.optString("hint").trim().ifEmpty { revealed.getString(id) }
            update { it.put("hint_reveals",revealed.put(id,updated)) };return updated
        }
        val text=active!!.optString("hint").trim();check(text.isNotEmpty()) { "No hint is available for this quest." }
        if(id in hintIds(state)) { update { it.put("hint_reveals",revealed.put(id,text)) };return text }
        check(hintsRemaining(state)>0) { "Your team has used all 3 hints." }
        record("HINT",JSONObject().put("riddle_id",id),false)
        return text
    }
    fun cooldownRemaining(state: JSONObject=state()): Long? {
        if(!state.has("local_cooldown_end"))return 0
        if(state.optInt("local_cooldown_boot",-2)!=boot())return null
        return (state.optLong("local_cooldown_end")-SystemClock.elapsedRealtime()).coerceAtLeast(0)
    }
    fun refreshCooldown() {
        val s=state();if(s.optBoolean("eliminated")||!s.has("local_cooldown_end"))return
        if(cooldownRemaining(s)==0L&&s.optInt("lives_remaining",3)==0)update { it.put("lives_remaining",3) }
    }
    @Synchronized fun record(kind: String, fields: JSONObject, localAccepted: Boolean): JSONObject {
        if(BuildConfig.UI_PREVIEW) return LocalPreview.record(this,kind,fields,localAccepted)
        val db=writableDatabase; db.beginTransaction()
        try {
            val state=state(); val pkg=state.getJSONObject("package"); val n=currentRound(state)
            if(kind in listOf("SCAN","HINT"))check(phase(state)=="PLAYING") { "The round has not started, is paused, or has ended." }
            if(kind=="HINT"){
                val active=activeRiddle(state);val id=fields.getString("riddle_id")
                check(active!=null&&active.optString("id")==id&&active.optString("hint").isNotBlank()) { "No hint is available for this quest." }
                check(id !in hintIds(state)&&hintsRemaining(state)>0) { "Your team has used all 3 hints." }
                val revealed=state.optJSONObject("hint_reveals")?:JSONObject();revealed.put(id,active!!.getString("hint"))
                state.put("hint_reveals",revealed).put("hints_used",JSONArray(hintIds(state)+id))
            }
            check(pendingCount()<5000) { "Your progress is saved. Connect to the internet or ask an organizer before scanning again." }
            val action=JSONObject(fields.toString()).put("id",UUID.randomUUID().toString()).put("seq",state.optInt("seq")+1)
                .put("prev_hash",state.optString("hash")).put("event_id",pkg.getString("event_id")).put("revision",pkg.getInt("revision"))
                .put("run_id",pkg.optString("run_id"))
                .put("team_id",pkg.getString("team_id")).put("device_id",state.getString("device_id")).put("round",n)
                .put("kind",kind).put("elapsed_ms",SystemClock.elapsedRealtime()).put("boot_id",boot())
                .put("control_version",state.optJSONObject("rounds")?.optJSONObject(n.toString())?.optInt("control_version",0) ?: 0)
            if(kind in listOf("SCAN","HINT"))action.put("server_at",serverNow(state))
            val envelope=DeviceCrypto.sign(action)
            db.execSQL("INSERT INTO outbox(id,seq,envelope) VALUES(?,?,?)",arrayOf(action.getString("id"),action.getInt("seq"),envelope.toString()))
            state.put("seq",action.getInt("seq")).put("hash",Rules.sha256(envelope.getString("payload")))
            if(localAccepted) {
                val all=state.optJSONObject("local_progress") ?: JSONObject(); val p=localProgress(state,n)
                if(kind=="SCAN") p.put("completed",p.optInt("completed")+1).put("answered",false)
                all.put(n.toString(),p); state.put("local_progress",all)
            }
            if(fields.optBoolean("local_penalty")) {
                val lives=(state.optInt("lives_remaining",3)-1).coerceAtLeast(0)
                val cooldown=pkg.optJSONObject("settings")?.optLong("life_cooldown_ms",0) ?: 0
                state.put("lives_remaining",lives).put("eliminated",lives==0&&cooldown<=0)
                if(lives==0&&cooldown>0)state.put("local_cooldown_end",SystemClock.elapsedRealtime()+cooldown).put("local_cooldown_boot",boot()).put("local_cooldown_seq",action.getInt("seq"))
                else if(lives==0) state.put("elimination_reason","THREE_WRONG_SEQUENCE_SCANS")
            }
            save(db,state); db.setTransactionSuccessful(); return action
        } finally { db.endTransaction() }
    }
    @Synchronized fun applySync(response: JSONObject,roundTripMs: Long=0,previousRun: String?=null) {
        val db=writableDatabase; db.beginTransaction()
        try {
            val state=state(); val ack=response.getJSONArray("ack")
            var rejection=""
            for(i in 0 until ack.length()) {
                val item=ack.getJSONObject(i); val result=item.getString("result")
                db.execSQL("UPDATE outbox SET result=? WHERE id=?",arrayOf(result,item.getString("id")))
                if(result !in setOf("CHECKPOINT_ACCEPTED","RECORDED","ALREADY_COMPLETED","HINT_GRANTED","HINT_ALREADY_REVEALED","WRONG_RUN")) rejection=result
            }
            var changedRun=false
            if(response.has("package")) {
                val pkg=DeviceCrypto.verify(state.getString("organizer_key"),response.getJSONObject("package"))
                check(pkg.getString("device_id")==state.getString("device_id")) { "Package belongs to another phone" }
                changedRun=(previousRun?:state.optJSONObject("package")?.optString("run_id")?:"")!=pkg.optString("run_id")
                if(changedRun){
                    db.execSQL("CREATE TABLE IF NOT EXISTS retired_outbox(id TEXT PRIMARY KEY,seq INTEGER NOT NULL,envelope TEXT NOT NULL,result TEXT)")
                    db.execSQL("INSERT OR IGNORE INTO retired_outbox SELECT id,seq,envelope,result FROM outbox")
                    db.execSQL("DELETE FROM outbox")
                    state.put("seq",response.getInt("last_seq")).put("hash",response.getString("last_hash"))
                    for(key in listOf("hint_reveals","hints_used","local_progress","local_cooldown_end","local_cooldown_seq","local_cooldown_boot","last_rejection","applied_commands","scan_notice"))state.remove(key)
                }
                state.put("package",pkg).put("package_hash",response.getString("package_hash"))
            }
            val elapsed=SystemClock.elapsedRealtime();val roundTrip=roundTripMs.coerceIn(0,60000)
            // Keep a recent faster clock sample when one slow reply would add jitter.
            val keepClock=serverNow(state)!=null&&elapsed-state.optLong("clock_elapsed")<60000&&state.optLong("clock_rtt_ms",Long.MAX_VALUE)<roundTrip
            if(!keepClock)state.put("clock_server",response.getLong("server_time")+roundTrip/2).put("clock_elapsed",elapsed).put("clock_boot",boot()).put("clock_rtt_ms",roundTrip)
            state.put("rounds",response.getJSONObject("rounds")).put("official_progress",response.getJSONObject("progress"))
                .put("last_sync_elapsed",SystemClock.elapsedRealtime()).put("last_sync_server",response.getLong("server_time"))
                .put("sync_error","").put("needs_review",response.optBoolean("needs_review"))
            if(response.has("leaderboard")) state.put("leaderboard",response.getJSONObject("leaderboard"))
            if(response.has("opponents"))state.put("opponents",response.getJSONObject("opponents"))
            val confirmedHints=response.optJSONArray("hints_used")?:JSONArray()
            state.put("hints_used",JSONArray(hintIds(state)+(0 until confirmedHints.length()).map { confirmedHints.getString(it) }))
            if(changedRun||(pendingCount()==0 && !response.optBoolean("needs_review"))) state.put("local_progress",JSONObject(response.getJSONObject("progress").toString()))
            var projectedLives=response.optInt("lives_remaining",3)
            val outstanding=JSONArray()
            db.rawQuery("SELECT envelope FROM outbox WHERE result IS NULL ORDER BY seq",null).use { while(it.moveToNext()) outstanding.put(JSONObject(it.getString(0))) }
            for(i in 0 until outstanding.length()) {
                val saved=JSONObject(String(DeviceCrypto.decode(outstanding.getJSONObject(i).getString("payload")),Charsets.UTF_8))
                if(saved.optString("run_id")!=(state.optJSONObject("package")?.optString("run_id")?:""))continue
                if(saved.optBoolean("local_penalty")) projectedLives--
            }
            val cooldown=state.optJSONObject("package")?.optJSONObject("settings")?.optLong("life_cooldown_ms",0) ?: 0
            if(response.optLong("cooldown_until",0)>response.getLong("server_time")) {
                val end=SystemClock.elapsedRealtime()+response.getLong("cooldown_until")-(serverNow(state)?:response.getLong("server_time"))
                val known=state.optInt("local_cooldown_seq",-1)==response.optInt("cooldown_trigger_seq",-2)&&state.optInt("local_cooldown_boot",-2)==boot()
                state.put("local_cooldown_end",if(known)minOf(end,state.optLong("local_cooldown_end",end))else end).put("local_cooldown_boot",boot())
            }else if(pendingCount()==0){state.remove("local_cooldown_end");state.remove("local_cooldown_seq")}
            state.put("lives_remaining",projectedLives.coerceAtLeast(0)).put("eliminated",response.optBoolean("eliminated") || projectedLives<=0&&cooldown<=0)
                .put("elimination_reason",response.optString("elimination_reason"))
            if(rejection.isNotEmpty()) state.put("last_rejection",rejection)
            val commands=JSONArray()
            val confirmed=response.optJSONArray("acknowledged_commands") ?: JSONArray()
            val confirmedIds=(0 until confirmed.length()).map { confirmed.getString(it) }.toSet()
            val oldApplied=state.optJSONArray("applied_commands") ?: JSONArray()
            val applied=JSONArray((0 until oldApplied.length()).map { oldApplied.getString(it) }.filter { it !in confirmedIds })
            state.put("applied_commands",applied)
            val ids=(0 until applied.length()).map { applied.getString(it) }.toSet()
            val incoming=response.getJSONArray("commands")
            for(i in 0 until incoming.length()) {
                val cmd=DeviceCrypto.verify(state.getString("organizer_key"),incoming.getJSONObject(i))
                if(cmd.getString("device_id")==state.getString("device_id") && cmd.getString("event_id")==state.getJSONObject("package").getString("event_id") &&
                    cmd.getLong("expires_at")>response.getLong("server_time") && cmd.getString("id") !in ids) commands.put(cmd)
            }
            state.put("commands",commands); save(db,state); db.setTransactionSuccessful()
        } finally { db.endTransaction() }
    }
    fun export(): JSONObject=JSONObject().put("protocol",2).put("device_id",state().getString("device_id"))
        .put("team_id",packageData()?.optString("team_id")).put("events",JSONArray().apply {
            val archived=readableDatabase.rawQuery("SELECT name FROM sqlite_master WHERE type='table' AND name='retired_outbox'",null).use { it.moveToFirst() }
            if(archived)readableDatabase.rawQuery("SELECT envelope FROM retired_outbox ORDER BY seq",null).use { while(it.moveToNext())put(JSONObject(it.getString(0))) }
            readableDatabase.rawQuery("SELECT envelope FROM outbox ORDER BY seq",null).use { while(it.moveToNext()) put(JSONObject(it.getString(0))) }
        })
}
