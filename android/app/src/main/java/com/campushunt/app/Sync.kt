package com.campushunt.app

import android.content.Context
import android.net.ConnectivityManager
import androidx.work.*
import org.json.JSONObject
import java.io.ByteArrayInputStream
import java.io.ByteArrayOutputStream
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URI
import java.util.concurrent.TimeUnit
import java.util.zip.GZIPInputStream
import java.util.zip.GZIPOutputStream

class ApiError(val status: Int, message: String): IOException(message)

object HuntApi {
    const val SERVER="https://anazori.ccsdu.in/treasure-hunt"
    @Volatile var busy=false
    data class Result(val data: JSONObject,val sent: Int,val received: Int,val roundTripMs: Long)
    fun post(host: String, path: String, body: JSONObject, token: String=""): Result {
        require(BuildConfig.DEBUG||host.trimEnd('/')==SERVER) { "Use the Anazori event server." }
        val root=URI(host)
        require(root.scheme=="https" || (BuildConfig.DEBUG && root.scheme=="http")) { "Use HTTPS. HTTP is available only in debug builds for local testing." }
        require(!root.host.isNullOrBlank() && root.userInfo==null && root.query==null && root.fragment==null && root.normalize().path==root.path && root.path.orEmpty().split('/').none { it==".." || it=="." } && !root.rawPath.orEmpty().contains('%')) { "Enter the HTTPS hunt address, optionally including its Anazori subfolder." }
        val connection=URI(host.trimEnd('/')+path).toURL().openConnection() as HttpURLConnection
        connection.connectTimeout=5000; connection.readTimeout=8000; connection.instanceFollowRedirects=false
        connection.requestMethod="POST"; connection.doOutput=true
        connection.setRequestProperty("Content-Type","application/json"); connection.setRequestProperty("Accept-Encoding","gzip")
        if(token.isNotEmpty()) connection.setRequestProperty("Authorization","Bearer $token")
        var bytes=body.toString().toByteArray(Charsets.UTF_8)
        if(bytes.size>500) { val out=ByteArrayOutputStream(); GZIPOutputStream(out).use { it.write(bytes) }; bytes=out.toByteArray(); connection.setRequestProperty("Content-Encoding","gzip") }
        try {
            val requestElapsed=android.os.SystemClock.elapsedRealtime()
            connection.setFixedLengthStreamingMode(bytes.size); connection.outputStream.use { it.write(bytes) }
            val status=connection.responseCode
            val stream=if(status in 200..299) connection.inputStream else connection.errorStream
            val raw=stream?.use { it.readBytesLimited(2_000_000) } ?: byteArrayOf()
            val decoded=if(connection.contentEncoding=="gzip") GZIPInputStream(ByteArrayInputStream(raw)).use { it.readBytesLimited(2_000_000) } else raw
            val result=if(decoded.isNotEmpty()) JSONObject(String(decoded,Charsets.UTF_8)) else JSONObject()
            if(status !in 200..299) throw ApiError(status,result.opt("detail")?.toString() ?: "Server error $status")
            return Result(result,bytes.size,raw.size,android.os.SystemClock.elapsedRealtime()-requestElapsed)
        } finally { connection.disconnect() }
    }
    private fun java.io.InputStream.readBytesLimited(limit: Int): ByteArray {
        val out=ByteArrayOutputStream(); val buffer=ByteArray(8192)
        while(true) { val count=read(buffer); if(count<0) break; if(out.size()+count>limit) throw IOException("Response exceeds protocol limit"); out.write(buffer,0,count) }
        return out.toByteArray()
    }
    @Synchronized fun sync(context: Context): Boolean {
        if(BuildConfig.UI_PREVIEW) return false
        val store=HuntStore(context); val state=store.state()
        if(!state.has("token") || busy) { store.close(); return false }
        if((context.getSystemService(Context.CONNECTIVITY_SERVICE) as ConnectivityManager).activeNetwork==null) { store.close(); return false }
        busy=true
        var session=state.getString("token")
        try {
            val body=JSONObject().put("device_id",state.getString("device_id")).put("events",store.pending())
                .put("run_id",state.optJSONObject("package")?.optString("run_id")?:"")
                .put("client_version",BuildConfig.VERSION_CODE)
                .put("pending",store.pendingCount()).put("kiosk",state.optBoolean("kiosk"))
                .put("ready",listOf(android.Manifest.permission.CAMERA,android.Manifest.permission.ACCESS_FINE_LOCATION).all {
                    androidx.core.content.ContextCompat.checkSelfPermission(context,it)==android.content.pm.PackageManager.PERMISSION_GRANTED } &&
                    (context.getSystemService(Context.LOCATION_SERVICE) as android.location.LocationManager).isLocationEnabled)
                .put("package_hash",state.optString("package_hash")).put("leaderboard_revision",state.optJSONObject("leaderboard")?.optLong("revision",-1) ?: -1)
                .put("applied_commands",state.optJSONArray("applied_commands") ?: org.json.JSONArray())
                .put("opponents_revision",state.optJSONObject("opponents")?.optString("revision","") ?: "")
            val roundAck=JSONObject();val rounds=state.optJSONObject("rounds")
            for(n in 1..3)rounds?.optJSONObject(n.toString())?.let { roundAck.put(n.toString(),it.optInt("control_version")) }
            body.put("round_ack",roundAck)
            val location=state.optJSONObject("location")
            val age=android.os.SystemClock.elapsedRealtime()-state.optLong("location_elapsed")
            if(location!=null && state.optInt("location_boot",-2)==store.boot() && age in 0..15000 &&
                android.os.SystemClock.elapsedRealtime()-state.optLong("location_sent_elapsed")>=4500) body.put("location",JSONObject(location.toString()).put("age_ms",age))
            val host=if(BuildConfig.DEBUG)state.optString("host",SERVER)else SERVER
            val result=try { post(host,"/api/sync",body,session) } catch(error: ApiError) {
                if(error.status!=401)throw error
                // The keystore key proves this is the enrolled installation, even after an offline night.
                if(store.state().optString("token")!=session)return false
                val proof=DeviceCrypto.sign(JSONObject().put("kind","RECONNECT").put("device_id",state.getString("device_id"))
                    .put("session_hash",Rules.sha256(session)))
                val resumed=post(host,"/api/reconnect",JSONObject().put("proof",proof),session).data.getString("token")
                if(!store.replaceSession(session,resumed))return false
                session=resumed
                post(host,"/api/sync",body,session)
            }
            if(store.state().optString("token")!=session)return false
            store.applySync(result.data,result.roundTripMs)
            store.update { it.put("payload_bytes",it.optLong("payload_bytes")+result.sent+result.received)
                if(body.has("location")) it.put("location_sent_elapsed",android.os.SystemClock.elapsedRealtime()) }
            return true
        } catch(e: Exception) {
            if(e is ApiError&&e.status==401)store.replaceSession(session,null)
            store.update {
                it.put("sync_error",if(e is ApiError && e.status==401) "Please log in again to reconnect." else (e.message ?: "Offline")) }
            return false
        } finally { busy=false; store.close() }
    }
    fun schedule(context: Context) {
        val request=PeriodicWorkRequestBuilder<SyncWorker>(15,TimeUnit.MINUTES)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL,30,TimeUnit.SECONDS).build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork("hunt-sync",ExistingPeriodicWorkPolicy.KEEP,request)
    }
}

class SyncWorker(context: Context, params: WorkerParameters): Worker(context,params) {
    override fun doWork(): Result = if(HuntApi.sync(applicationContext)) Result.success() else Result.retry()
}
