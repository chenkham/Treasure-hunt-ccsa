package com.campushunt.app

import android.content.Context
import android.graphics.Color
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.SoundPool
import android.view.View
import android.widget.TextView
import com.google.android.material.snackbar.Snackbar
import java.io.File
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.math.*

/** Tiny local chimes; notification volume, silent mode and Android's DND routing apply. */
class HuntFeedback(private val context: Context) {
    private val preferences=context.getSharedPreferences("hunt_feedback",Context.MODE_PRIVATE)
    var enabled: Boolean
        get()=preferences.getBoolean("sound",true)
        set(value) { preferences.edit().putBoolean("sound",value).apply() }
    private val audio=context.getSystemService(Context.AUDIO_SERVICE) as AudioManager
    private val pool=SoundPool.Builder().setMaxStreams(1).setAudioAttributes(AudioAttributes.Builder()
        .setUsage(AudioAttributes.USAGE_NOTIFICATION_EVENT).setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION).build()).build()
    private val loaded=mutableSetOf<Int>()
    private val quest: Int
    private val rank: Int
    private val wrong: Int
    private val start: Int
    private val win: Int
    private var current: Snackbar?=null
    init {
        pool.setOnLoadCompleteListener { _,id,status -> if(status==0) loaded.add(id) }
        quest=load("seal",doubleArrayOf(659.25,987.77))
        rank=load("rise",doubleArrayOf(783.99,1046.50))
        wrong=load("wrong",doubleArrayOf(329.63,220.0))
        start=load("start",doubleArrayOf(392.0,523.25,783.99))
        win=load("win",doubleArrayOf(523.25,659.25,783.99,1046.50))
    }
    private fun load(name: String,notes: DoubleArray): Int {
        val file=File(context.cacheDir,"hunt-$name-v1.wav")
        if(!file.exists()) {
            val rate=22050;val perNote=(rate*.095).toInt();val samples=perNote*notes.size
            val b=ByteBuffer.allocate(44+samples*2).order(ByteOrder.LITTLE_ENDIAN)
            b.put("RIFF".toByteArray()).putInt(36+samples*2).put("WAVEfmt ".toByteArray()).putInt(16)
                .putShort(1).putShort(1).putInt(rate).putInt(rate*2).putShort(2).putShort(16).put("data".toByteArray()).putInt(samples*2)
            for(f in notes) for(i in 0 until perNote) {
                val t=i.toDouble()/rate;val envelope=min(1.0,i/(rate*.008))*max(0.0,1-i.toDouble()/perNote).pow(2)
                b.putShort((sin(2*PI*f*t)*envelope*32767*.18).toInt().toShort())
            }
            file.writeBytes(b.array())
        }
        return pool.load(file.absolutePath,1)
    }
    fun show(anchor: View,title: String,detail: String,ranking: Boolean=false,sound: Boolean=true) {
        current?.dismiss()
        val snackbar=Snackbar.make(anchor,"$title\n$detail",Snackbar.LENGTH_LONG)
        snackbar.view.background=GlassDrawable(220,true)
        snackbar.view.findViewById<TextView>(com.google.android.material.R.id.snackbar_text)?.apply {
            maxLines=3;setTextColor(Color.rgb(240,245,255));textSize=14f
            setCompoundDrawablesRelativeWithIntrinsicBounds(HuntIcon(if(ranking)"rise" else "check",Color.rgb(145,243,214)),null,null,null)
            compoundDrawablePadding=(12*resources.displayMetrics.density).toInt()
        }
        snackbar.view.importantForAccessibility=View.IMPORTANT_FOR_ACCESSIBILITY_YES
        snackbar.show();current=snackbar
        if(sound)chime(ranking)
    }
    fun chime(ranking: Boolean=false)=play(if(ranking)"rank" else "seal")
    fun play(kind: String) {
        val soundId=when(kind){"rank"->rank;"wrong"->wrong;"start"->start;"win"->win;else->quest}
        if(enabled && soundId in loaded && audio.ringerMode==AudioManager.RINGER_MODE_NORMAL && audio.getStreamVolume(AudioManager.STREAM_NOTIFICATION)>0)
            pool.play(soundId,.45f,.45f,1,0,1f)
    }
    fun dismiss() { current?.dismiss();current=null }
    fun close() { dismiss();pool.release() }
}
