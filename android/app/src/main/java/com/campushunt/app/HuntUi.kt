package com.campushunt.app

import android.animation.ValueAnimator
import android.content.Context
import android.content.res.ColorStateList
import android.graphics.*
import android.graphics.drawable.Drawable
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.view.*
import android.widget.*
import androidx.appcompat.content.res.AppCompatResources
import com.google.android.material.button.MaterialButton
import kotlin.math.*

/** Small vector graphics: no icon font, remote asset or bitmap downloads at runtime. */
class HuntIcon(private val kind: String,private val tint: Int=Color.rgb(56,43,76)): Drawable() {
    private val p=Paint(Paint.ANTI_ALIAS_FLAG).apply { color=tint; style=Paint.Style.STROKE; strokeWidth=1.8f; strokeCap=Paint.Cap.ROUND; strokeJoin=Paint.Join.ROUND }
    override fun draw(c: Canvas) {
        c.save();c.translate(bounds.left.toFloat(),bounds.top.toFloat());c.scale(bounds.width()/24f,bounds.height()/24f)
        p.color=tint;p.style=Paint.Style.STROKE
        fun line(a: Float,b: Float,d: Float,e: Float)=c.drawLine(a,b,d,e,p)
        when(kind) {
            "map" -> { val path=Path();path.moveTo(3f,5f);path.lineTo(9f,3f);path.lineTo(15f,5f);path.lineTo(21f,3f);path.lineTo(21f,19f);path.lineTo(15f,21f);path.lineTo(9f,19f);path.lineTo(3f,21f);path.close();c.drawPath(path,p);line(9f,3f,9f,19f);line(15f,5f,15f,21f) }
            "clue" -> { val path=Path();path.moveTo(5f,3f);path.lineTo(15f,3f);path.lineTo(19f,7f);path.lineTo(19f,21f);path.lineTo(5f,21f);path.close();c.drawPath(path,p);line(14f,3f,14f,8f);line(14f,8f,19f,8f);line(9f,12f,15f,12f);line(9f,16f,12f,16f);line(2f,7f,5f,7f) }
            "trophy" -> { line(3f,21f,21f,21f);c.drawRoundRect(4f,14f,8f,21f,1f,1f,p);c.drawRoundRect(10f,10f,14f,21f,1f,1f,p);c.drawRoundRect(16f,16f,20f,21f,1f,1f,p);line(8f,7f,12f,3f);line(12f,3f,16f,7f);line(12f,3f,12f,6f) }
            "rise" -> { line(4f,19f,10f,13f);line(10f,13f,14f,15f);line(14f,15f,20f,5f);line(13f,5f,20f,5f);line(20f,5f,21f,12f);c.drawPoint(4f,5f,p) }
            "scan" -> { line(3f,8f,3f,4f);line(3f,4f,7f,4f);line(17f,4f,21f,4f);line(21f,4f,21f,8f);line(3f,16f,3f,20f);line(3f,20f,7f,20f);line(17f,20f,21f,20f);line(21f,20f,21f,16f);c.drawRect(8f,8f,11f,11f,p);c.drawRect(14f,8f,16f,11f,p);line(8f,15f,11f,15f);line(14f,14f,16f,16f) }
            "locate" -> { c.drawCircle(12f,12f,7f,p);c.drawCircle(12f,12f,2f,p);line(12f,2f,12f,5f);line(12f,19f,12f,22f);line(2f,12f,5f,12f);line(19f,12f,22f,12f) }
            "layers" -> { val path=Path();path.moveTo(3f,8f);path.lineTo(12f,3f);path.lineTo(21f,8f);path.lineTo(12f,13f);path.close();c.drawPath(path,p);line(3f,12f,12f,17f);line(12f,17f,21f,12f);line(3f,16f,12f,21f);line(12f,21f,21f,16f) }
            "compass" -> { c.drawCircle(12f,12f,9f,p);val path=Path();path.moveTo(16f,7f);path.lineTo(10f,10f);path.lineTo(8f,17f);path.lineTo(14f,14f);path.close();c.drawPath(path,p) }
            "arrow" -> { line(4f,12f,20f,12f);line(14f,6f,20f,12f);line(14f,18f,20f,12f) }
            "back" -> { line(20f,12f,4f,12f);line(10f,6f,4f,12f);line(10f,18f,4f,12f) }
            "check" -> { line(4f,12f,9f,17f);line(9f,17f,20f,6f) }
            "heart" -> { val heart=Path();heart.moveTo(12f,21f);heart.cubicTo(8f,18f,2f,13f,2f,7f);heart.cubicTo(2f,1f,10f,1f,12f,6f);heart.cubicTo(14f,1f,22f,1f,22f,7f);heart.cubicTo(22f,13f,16f,18f,12f,21f);c.drawPath(heart,p) }
            "help" -> { c.drawCircle(12f,12f,9f,p);c.drawArc(9f,6f,15f,12f,180f,230f,false,p);line(12f,12f,12f,14f);c.drawPoint(12f,18f,p) }
            "person" -> { c.drawRoundRect(9f,3f,15f,10f,2f,2f,p);val path=Path();path.moveTo(5f,21f);path.lineTo(5f,17f);path.lineTo(9f,14f);path.lineTo(15f,14f);path.lineTo(19f,17f);path.lineTo(19f,21f);c.drawPath(path,p);line(9f,21f,15f,21f) }
            "lock" -> { val path=Path();path.moveTo(5f,10f);path.lineTo(19f,10f);path.lineTo(19f,18f);path.lineTo(16f,21f);path.lineTo(8f,21f);path.lineTo(5f,18f);path.close();c.drawPath(path,p);c.drawArc(8f,2f,16f,15f,180f,180f,false,p);c.drawCircle(12f,14f,1f,p);line(12f,15f,12f,17f) }
            "clock" -> { c.drawCircle(12f,12f,9f,p);line(12f,6f,12f,12f);line(12f,12f,16f,14f) }
            "more" -> { p.style=Paint.Style.FILL;for(y in listOf(5f,12f,19f))c.drawCircle(12f,y,1.6f,p) }
            "plus" -> { line(5f,12f,19f,12f);line(12f,5f,12f,19f) }
            "minus" -> line(5f,12f,19f,12f)
            else -> { c.drawCircle(12f,12f,8f,p);c.drawPoint(12f,12f,p) }
        };c.restore()
    }
    override fun setAlpha(alpha: Int) { p.alpha=alpha }
    override fun setColorFilter(filter: ColorFilter?) { p.colorFilter=filter }
    override fun getOpacity()=PixelFormat.TRANSLUCENT
    override fun getIntrinsicWidth()=(24*android.content.res.Resources.getSystem().displayMetrics.density).toInt()
    override fun getIntrinsicHeight()=intrinsicWidth
}

/** Translucent festival glass; opaque children stay legible without expensive live blur. */
class GlassDrawable(private val fillAlpha: Int=128,private val dark: Boolean=false): android.graphics.drawable.Drawable() {
    private val p=Paint(Paint.ANTI_ALIAS_FLAG)
    override fun draw(c: Canvas) {
        val r=android.graphics.RectF(bounds);val radius=18*android.content.res.Resources.getSystem().displayMetrics.density
        p.style=Paint.Style.FILL;p.shader=null;p.color=if(dark) Color.argb(fillAlpha,16,10,35) else Color.argb(fillAlpha,255,255,255)
        c.drawRoundRect(r,radius,radius,p)
        p.shader=LinearGradient(r.left,r.top,r.right,r.bottom,intArrayOf(Color.argb(if(dark)28 else 38,200,166,255),Color.TRANSPARENT,Color.argb(12,131,244,237)),null,Shader.TileMode.CLAMP)
        c.drawRoundRect(r,radius,radius,p);p.shader=null
        p.style=Paint.Style.STROKE;p.strokeWidth=android.content.res.Resources.getSystem().displayMetrics.density
        p.color=if(dark) Color.argb(120,200,166,255) else Color.argb(175,255,255,255);c.drawRoundRect(r,radius,radius,p)
    }
    override fun setAlpha(alpha: Int) {}
    override fun setColorFilter(filter: android.graphics.ColorFilter?) {}
    override fun getOpacity()=android.graphics.PixelFormat.TRANSLUCENT
}

/** TextureView keeps a poster visible until a real video frame arrives, avoiding SurfaceView black flashes. */
class DoorTransition(context: Context): FrameLayout(context),TextureView.SurfaceTextureListener {
    private val poster=ImageView(context).apply { setImageBitmap(context.assets.open("door_poster.webp").use { BitmapFactory.decodeStream(it) });scaleType=ImageView.ScaleType.FIT_XY }
    private val texture=TextureView(context).apply { isOpaque=false;alpha=0f;surfaceTextureListener=this@DoorTransition }
    private var player: android.media.MediaPlayer?=null
    private var surface: android.view.Surface?=null
    private var finished=false
    private var done: (() -> Unit)?=null
    init { setBackgroundColor(Color.rgb(213,242,239));addView(poster,LayoutParams(-1,-1));addView(texture,LayoutParams(-1,-1)) }
    fun play(done: ()->Unit) { this.done=done;if(texture.isAvailable) prepare(texture.surfaceTexture!!);postDelayed({ finish() },3500) }
    private fun finish() { if(finished) return;finished=true;releasePlayer();done?.invoke();done=null }
    private fun releasePlayer() { try { player?.reset();player?.release() } catch(_:Exception) {};player=null;surface?.release();surface=null }
    private fun prepare(st: android.graphics.SurfaceTexture) {
        if(finished || player!=null || done==null) return
        try {
            surface=android.view.Surface(st)
            player=android.media.MediaPlayer().apply {
                setSurface(surface);val fd=resources.openRawResourceFd(R.raw.door_opening);setDataSource(fd.fileDescriptor,fd.startOffset,fd.length);fd.close();setVolume(0f,0f)
                // Match the uncropped welcome/poster composition on tall phone screens.
                setOnInfoListener { _,what,_ -> if(what==android.media.MediaPlayer.MEDIA_INFO_VIDEO_RENDERING_START) { texture.animate().alpha(1f).setDuration(70).start() };false }
                setOnPreparedListener { if(!finished) it.start() };setOnCompletionListener { finish() };setOnErrorListener { _,_,_ -> finish();true };prepareAsync()
            }
        } catch(_:Exception) { finish() }
    }
    override fun onSurfaceTextureAvailable(st: android.graphics.SurfaceTexture,width: Int,height: Int)=prepare(st)
    override fun onSurfaceTextureSizeChanged(st: android.graphics.SurfaceTexture,width: Int,height: Int) {}
    override fun onSurfaceTextureDestroyed(st: android.graphics.SurfaceTexture): Boolean { releasePlayer();return true }
    override fun onSurfaceTextureUpdated(st: android.graphics.SurfaceTexture) {}
    fun release() { finished=true;done=null;releasePlayer() }
}
