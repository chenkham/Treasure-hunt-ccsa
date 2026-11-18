package com.campushunt.app

import android.content.Context
import android.util.Size
import androidx.camera.core.*
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import androidx.lifecycle.LifecycleOwner
import com.google.zxing.*
import com.google.zxing.common.HybridBinarizer
import java.util.concurrent.Executors

class HuntScanner(context: Context, owner: LifecycleOwner, result: (String)->Unit, error: (String)->Unit) {
    val view=PreviewView(context)
    private val executor=Executors.newSingleThreadExecutor()
    private var provider: ProcessCameraProvider?=null
    private var camera: Camera?=null
    @Volatile private var completed=false
    @Volatile private var closed=false
    private var torch=false
    init {
        view.implementationMode=PreviewView.ImplementationMode.COMPATIBLE
        val future=ProcessCameraProvider.getInstance(context)
        future.addListener({
            if(closed) return@addListener
            try {
                val current=future.get(); provider=current
                val preview=Preview.Builder().build().also { it.setSurfaceProvider(view.surfaceProvider) }
                val analysis=ImageAnalysis.Builder().setTargetResolution(Size(640,480))
                    .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST).build()
                val reader=MultiFormatReader().apply { setHints(mapOf(DecodeHintType.POSSIBLE_FORMATS to listOf(BarcodeFormat.QR_CODE))) }
                analysis.setAnalyzer(executor) { frame ->
                    try {
                        if(!completed) {
                            val plane=frame.planes[0]; val buffer=plane.buffer; val bytes=ByteArray(buffer.remaining()); buffer.get(bytes)
                            val packed=ByteArray(frame.width*frame.height)
                            for(y in 0 until frame.height) for(x in 0 until frame.width) packed[y*frame.width+x]=bytes[y*plane.rowStride+x*plane.pixelStride]
                            val luminance=PlanarYUVLuminanceSource(packed,frame.width,frame.height,0,0,frame.width,frame.height,false)
                            val code=reader.decodeWithState(BinaryBitmap(HybridBinarizer(luminance))).text
                            completed=true; ContextCompat.getMainExecutor(context).execute { if(!closed) result(code) }
                        }
                    } catch(_: NotFoundException) { } catch(_: ChecksumException) { } catch(_: FormatException) { }
                    catch(e: Exception) { completed=true; ContextCompat.getMainExecutor(context).execute { if(!closed) error("The camera could not read this QR code. Try again.") } }
                    finally { reader.reset(); frame.close() }
                }
                camera=current.bindToLifecycle(owner,CameraSelector.DEFAULT_BACK_CAMERA,preview,analysis)
            } catch(e: Exception) { if(!closed) error("Camera unavailable. Close the scanner and retry, or ask an organizer.") }
        },ContextCompat.getMainExecutor(context))
    }
    fun torch() { if(camera?.cameraInfo?.hasFlashUnit()==true) { torch=!torch; camera?.cameraControl?.enableTorch(torch) } }
    fun close() { if(closed)return;closed=true;completed=true;camera?.cameraControl?.enableTorch(false);provider?.unbindAll();executor.shutdown() }
}
