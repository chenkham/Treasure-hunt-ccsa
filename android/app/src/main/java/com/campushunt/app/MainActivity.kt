package com.campushunt.app

import android.Manifest
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.coordinatorlayout.widget.CoordinatorLayout
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import com.google.android.material.button.MaterialButton
import com.google.android.material.card.MaterialCardView
import com.google.android.material.navigation.NavigationBarView
import com.google.android.material.bottomnavigation.BottomNavigationView
import com.google.android.material.bottomsheet.BottomSheetBehavior
import com.google.android.material.textfield.TextInputLayout
import com.google.android.material.textfield.TextInputEditText
import android.content.res.ColorStateList
import android.content.*
import android.content.pm.PackageManager
import android.graphics.BitmapFactory
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.location.*
import android.net.ConnectivityManager
import android.net.Network
import android.net.NetworkCapabilities
import android.os.*
import android.text.InputType
import android.view.*
import android.widget.*
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.core.content.ContextCompat
import org.json.JSONArray
import org.json.JSONObject
import java.util.Locale
import java.util.concurrent.Executors

class MainActivity: AppCompatActivity(), LocationListener {
    private lateinit var store: HuntStore
    private lateinit var kiosk: Kiosk
    private lateinit var map: CampusMapView
    private lateinit var root: LinearLayout
    private val pixel by lazy { Typeface.createFromAsset(assets,"BlitzPixel-Regular.ttf") }
    private var timer: TextView?=null
    private var clockTitle: TextView?=null
    private var hearts: TextView?=null
    private var gpsLabel: TextView?=null
    private var door: DoorTransition?=null
    private var screen="MAP"
    private var fingerprint=""
    private var lastPhase=""
    private var scanner: HuntScanner?=null
    private var scannerDialog: AlertDialog?=null
    private var gameDialog: AlertDialog?=null
    private val mapChrome=mutableListOf<View>()
    private var mapExploring=false
    private val restoreMapChrome=Runnable { mapExploring=false;showMapChrome(true) }
    private val handler=Handler(Looper.getMainLooper())
    private val executor=Executors.newSingleThreadExecutor()
    @Volatile private var nextSync=0L
    @Volatile private var backoff=5000L
    @Volatile private var syncInFlight=false
    private var stopped=false
    private var emergency=false
    private val green=Color.rgb(200,166,255)
    private val ink=Color.rgb(240,245,255)
    private val cream=Color.rgb(9,13,24)
    private val muted=Color.rgb(227,214,248)
    private lateinit var feedback: HuntFeedback
    private val feedbackPolicy=FeedbackPolicy()
    private var permissionRetry: (() -> Unit)?=null
    private var requestedAccess=emptyArray<String>()
    private var networkCallback: ConnectivityManager.NetworkCallback?=null
    private val tick=object: Runnable { override fun run() { refresh(); handler.postDelayed(this,1000) } }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        WindowCompat.setDecorFitsSystemWindows(window,false)
        window.statusBarColor=Color.TRANSPARENT; window.navigationBarColor=Color.TRANSPARENT
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)
        if(!BuildConfig.UI_PREVIEW) {
            window.addFlags(WindowManager.LayoutParams.FLAG_SECURE)
            if(Build.VERSION.SDK_INT>=31) window.setHideOverlayWindows(true)
        }
        store=HuntStore(this);feedback=HuntFeedback(this)
        if(BuildConfig.UI_PREVIEW && store.packageData()!=null && store.packageData()!!.optInt("design_version")<5)
            LocalPreview.install(this,store,store.packageData()!!.optString("team_name","Explorer"))
        kiosk=Kiosk(this,store); map=CampusMapView(this)
        onBackPressedDispatcher.addCallback(this,object:OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                when {
                    gameDialog!=null -> Unit
                    scannerDialog!=null -> scannerDialog?.dismiss()
                    !store.state().has("token") -> finish()
                    screen!="MAP" -> { screen="MAP"; render() }
                    BuildConfig.UI_PREVIEW -> finish()
                    !kiosk.locked() -> finish()
                    else -> message("This phone is locked for the hunt.")
                }
            }
        })
        if(!BuildConfig.UI_PREVIEW) HuntApi.schedule(this)
        render(); handler.post(tick)
        networkCallback=object: ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) { nextSync=0; backoff=5000 }
            override fun onCapabilitiesChanged(network: Network, capabilities: NetworkCapabilities) {
                if(capabilities.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)) { nextSync=0; backoff=5000 }
            }
        }
        (getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager).registerDefaultNetworkCallback(networkCallback!!)
    }
    override fun onResume() {
        super.onResume();stopped=false;feedbackPolicy.reset()
        if(store.state().has("token") && !BuildConfig.UI_PREVIEW) {
            try { kiosk.enter() } catch(e: Exception) { message("Ask an organizer to prepare this phone.") }
            startLocation()
        }
        emergency=false
    }
    override fun onStop() {
        super.onStop();stopped=true;scannerDialog?.dismiss();scanner?.close();scanner=null;gameDialog?.dismiss();feedback.dismiss()
        try { (getSystemService(LOCATION_SERVICE) as LocationManager).removeUpdates(this) } catch(_:SecurityException) { }
        if(store.packageData()!=null && kiosk.locked() && !emergency && permissionRetry==null) {
            try { store.record("INCIDENT",JSONObject().put("message","App left foreground; marshal review required"),false) }
            catch(_:Exception) { store.update { it.put("needs_review",true).put("sync_error","Evidence queue needs marshal attention") } }
        }
    }
    override fun onMultiWindowModeChanged(inMultiWindow: Boolean,newConfig: android.content.res.Configuration) {
        super.onMultiWindowModeChanged(inMultiWindow,newConfig)
        if(inMultiWindow&&!BuildConfig.UI_PREVIEW&&::store.isInitialized&&store.packageData()!=null)
            try { store.record("INCIDENT",JSONObject().put("message","Multi-window entered; review context before drawing a cheating conclusion"),false) } catch(_:Exception) { }
    }
    override fun onDestroy() { permissionRetry=null;feedback.close();door?.release(); handler.removeCallbacksAndMessages(null); networkCallback?.let { (getSystemService(CONNECTIVITY_SERVICE) as ConnectivityManager).unregisterNetworkCallback(it) }; scanner?.close(); executor.shutdown(); store.close(); super.onDestroy() }

    private fun dp(n: Int)=(n*resources.displayMetrics.density).toInt()
    private fun panel(color: Int=Color.WHITE,radius: Float=16f)=GradientDrawable().apply { setColor(color); cornerRadius=dp(radius.toInt()).toFloat() }
    private fun text(value: String,size: Float=16f,color: Int=ink,bold: Boolean=false)=TextView(this).apply {
        text=value; textSize=size; setTextColor(color); if(bold) setTypeface(null,Typeface.BOLD)
        setPadding(0,dp(5),0,dp(5))
    }
    private fun column()=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL; setPadding(dp(20),dp(16),dp(20),dp(16)) }
    private fun button(label: String,primary: Boolean=false,action: ()->Unit)=MaterialButton(this).apply {
        text=label;isAllCaps=false;textSize=14f;minHeight=dp(48);setTextColor(if(primary) cream else ink)
        iconTint=ColorStateList.valueOf(if(primary)cream else green)
        backgroundTintList=ColorStateList.valueOf(if(primary) green else Color.argb(38,200,166,255))
        strokeColor=ColorStateList.valueOf(Color.argb(100,200,166,255));strokeWidth=dp(1);cornerRadius=dp(14);insetTop=0;insetBottom=0
        layoutParams=LinearLayout.LayoutParams(-1,-2).apply { setMargins(0,dp(6),0,dp(6)) }
        setOnClickListener { performHapticFeedback(HapticFeedbackConstants.VIRTUAL_KEY);action() }
    }
    private fun message(value: String) { if(!isFinishing) HuntDialogBuilder(this).setMessage(value).setPositiveButton("OK",null).show() }
    private fun notice(title: String,detail: String,celebrate: Boolean=true,ranking: Boolean=false) {
        if(!isFinishing && !isDestroyed && !stopped) feedback.show(root,title,detail,ranking,celebrate)
    }
    private fun gamePopup(title: String,detail: String,label: String="Continue",celebrate: Boolean=false,effect: String=if(celebrate)"seal" else "",action: ()->Unit={}) {
        if(gameDialog!=null||isFinishing||isDestroyed||stopped)return
        val content=column().apply { gravity=Gravity.CENTER;setPadding(dp(24),dp(32),dp(24),dp(24)) }
        val badge=ImageView(this).apply { setImageDrawable(HuntIcon(when(effect){"win"->"trophy";"wrong"->"heart";else->if(celebrate)"check" else "clue"},green));scaleX=.75f;scaleY=.75f }
        content.addView(badge,LinearLayout.LayoutParams(dp(64),dp(64)))
        content.addView(text(title,28f,ink,true).apply { gravity=Gravity.CENTER;setPadding(0,dp(18),0,dp(12)) })
        content.addView(text(detail,17f,muted).apply { gravity=Gravity.CENTER;setPadding(0,0,0,dp(20)) })
        val dialog=HuntDialogBuilder(this).setBackground(GlassDrawable(245,true)).setView(content).setCancelable(false).create()
        content.addView(button(label,true) { dialog.dismiss();action() })
        dialog.setOnDismissListener { if(gameDialog===dialog)gameDialog=null }
        gameDialog=dialog;dialog.show();dialog.window?.setLayout((resources.displayMetrics.widthPixels-dp(32)).coerceAtLeast(dp(260)),-2)
        badge.animate().scaleX(1f).scaleY(1f).setDuration(240).start()
        if(effect.isNotEmpty())feedback.play(effect)
        if(celebrate||effect=="wrong")root.performHapticFeedback(if(Build.VERSION.SDK_INT>=30)if(celebrate)HapticFeedbackConstants.CONFIRM else HapticFeedbackConstants.REJECT else HapticFeedbackConstants.VIRTUAL_KEY)
    }
    private fun showScanNotice(): Boolean {
        val scan=store.state().optJSONObject("scan_notice")?:return false
        gamePopup(scan.getString("title"),scan.getString("detail"),scan.getString("button"),scan.optBoolean("correct"),scan.optString("effect",if(scan.optBoolean("correct"))"seal" else "wrong")) {
            store.update { it.remove("scan_notice") };screen=if(roundResult(store.state())!=null)"RESULT" else "CLUE";worldOnly=false;render()
        }
        return true
    }
    private fun showMapChrome(show: Boolean) {
        if(!::map.isInitialized||screen!="MAP")return
        map.controlsHidden=!show;map.invalidate()
        for(view in mapChrome){view.animate().cancel();if(show){view.visibility=View.VISIBLE;view.animate().alpha(1f).setDuration(120).start()}
            else view.animate().alpha(0f).setDuration(100).withEndAction { view.visibility=View.INVISIBLE }.start()}
    }
    private fun mapInteraction(active: Boolean) {
        handler.removeCallbacks(restoreMapChrome)
        if(active){if(!mapExploring){mapExploring=true;showMapChrome(false)};handler.postDelayed(restoreMapChrome,700)}
        else if(mapExploring)handler.postDelayed(restoreMapChrome,250)
    }
    private fun freshLocation(state: JSONObject=store.state()): JSONObject? {
        if(BuildConfig.UI_PREVIEW) return LocalPreview.location(state)
        if(state.optInt("location_boot",-2)!=store.boot() || SystemClock.elapsedRealtime()-state.optLong("location_elapsed",-100000) !in 0..15000) return null
        return state.optJSONObject("location")
    }
    private fun target(state: JSONObject=store.state()): JSONObject? {
        val r=store.activeRiddle(state) ?: return null
        val all=state.getJSONObject("package").getJSONArray("checkpoints")
        return (0 until all.length()).map { all.getJSONObject(it) }.firstOrNull { it.getString("id")==r.getString("checkpoint_id") }
    }
    private fun surface(content: View,tint: Int=Color.WHITE)=FrameLayout(this).apply {
        background=GlassDrawable(185,true);addView(content)
    }
    private fun field(label: String,password: Boolean=false): Pair<TextInputLayout,EditText> {
        val wrap=TextInputLayout(this).apply {
            hint=label;boxBackgroundMode=TextInputLayout.BOX_BACKGROUND_OUTLINE
            setBoxCornerRadii(dp(12).toFloat(),dp(12).toFloat(),dp(12).toFloat(),dp(12).toFloat())
            boxStrokeColor=green;defaultHintTextColor=ColorStateList.valueOf(ink)
            boxBackgroundColor=Color.argb(28,255,255,255)
        }
        val edit=TextInputEditText(wrap.context).apply {
            textSize=16f;setSingleLine();minHeight=dp(56);setTextColor(ink)
            inputType=if(password) InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_PASSWORD else InputType.TYPE_CLASS_TEXT
            importantForAutofill=View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS
            setShadowLayer(2f,0f,1f,Color.BLACK)
        }
        wrap.addView(edit,LinearLayout.LayoutParams(-1,-2));wrap.startIconDrawable=HuntIcon(if(password) "lock" else "person",Color.rgb(20,49,65))
        wrap.setStartIconTintList(ColorStateList.valueOf(green))
        if(password) { wrap.endIconMode=TextInputLayout.END_ICON_PASSWORD_TOGGLE;wrap.setEndIconTintList(ColorStateList.valueOf(ink)) }
        wrap.layoutParams=LinearLayout.LayoutParams(-1,-2).apply { setMargins(0,dp(8),0,dp(8)) }
        return wrap to edit
    }
    private fun pixelText(label: String,size: Float=12f,color: Int=green)=text(label,size,color).apply { typeface=pixel;setLineSpacing(dp(6).toFloat(),1f) }
    private fun ownStanding(row: JSONObject,pkg: JSONObject): Boolean = when {
        row.optBoolean("you") -> true
        row.has("team_id") -> row.optString("team_id")==pkg.optString("team_id","-")
        row.has("code") && pkg.has("team_code") -> row.optString("code")==pkg.optString("team_code")
        else -> row.optString("team")==pkg.optString("team_name","-")
    }
    private var worldOnly=false
    private fun render() {
        (map.parent as? ViewGroup)?.removeView(map)
        timer=null;clockTitle=null;gpsLabel=null;hearts=null;mapChrome.clear()
        root=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL;setBackgroundColor(cream) }
        setContentView(root)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view,insets ->
            val bars=insets.getInsets(WindowInsetsCompat.Type.systemBars());val ime=insets.getInsets(WindowInsetsCompat.Type.ime())
            view.setPadding(0,bars.top,0,maxOf(bars.bottom,ime.bottom));insets
        };ViewCompat.requestApplyInsets(root)
        if(!store.state().has("token")) { enrollment();return }
        val state=store.state();val body=FrameLayout(this);root.addView(body,LinearLayout.LayoutParams(-1,-1))
        map.setData(state.optJSONObject("package"),null,freshLocation(state));map.onNodeSelected={ showPlace(it) }
        map.onPlaceGroupSelected={ places->HuntDialogBuilder(this).setBackground(GlassDrawable(225,true)).setTitle("${places.size} nearby places")
            .setItems(places.map { it.getString("name") }.toTypedArray()) { _,index->showPlace(places[index]) }.setNegativeButton("Close",null).show() }
        map.overviewBackdrop=screen!="MAP"
        if(Build.VERSION.SDK_INT>=31)map.setRenderEffect(if(screen!="MAP")android.graphics.RenderEffect.createBlurEffect(dp(9).toFloat(),dp(9).toFloat(),android.graphics.Shader.TileMode.CLAMP)else null)
        map.viewportTop=dp(180);map.viewportBottom=dp(120);map.onMapInteraction=::mapInteraction
        body.addView(map,FrameLayout.LayoutParams(-1,-1))
        val top=LinearLayout(this).apply { tag="hunt-top";orientation=LinearLayout.VERTICAL }
        val hud=column().apply { tag="hunt-hud";background=GlassDrawable(175,true);setPadding(dp(16),dp(12),dp(16),dp(12)) }
        val row=LinearLayout(this).apply { gravity=Gravity.CENTER_VERTICAL }
        val heading=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL }
        heading.addView(pixelText("ANAZORI / TREASURE HUNT",7f,green).apply { maxLines=1;ellipsize=android.text.TextUtils.TruncateAt.END })
        heading.addView(text(state.getJSONObject("package").getString("team_name"),22f,ink,true).apply { maxLines=1;ellipsize=android.text.TextUtils.TruncateAt.END;setPadding(0,dp(3),0,0) })
        row.addView(heading,LinearLayout.LayoutParams(0,-2,1f))
        val clock=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL;gravity=Gravity.END;setPadding(dp(8),0,0,0) }
        clockTitle=text("WAITING",8f,muted);clock.addView(clockTitle);timer=text("—",19f,green,true);clock.addView(timer);row.addView(clock);hud.addView(row)
        val info=LinearLayout(this).apply { gravity=Gravity.CENTER_VERTICAL }
        hearts=text("",12f,Color.rgb(244,195,168),true).apply { maxLines=1;ellipsize=android.text.TextUtils.TruncateAt.END };info.addView(hearts,LinearLayout.LayoutParams(0,-2,1f))
        hud.addView(info)
        gpsLabel=text("",10f,muted).apply { maxLines=1;ellipsize=android.text.TextUtils.TruncateAt.END;setPadding(0,dp(5),0,0) };hud.addView(gpsLabel)
        top.addView(hud,LinearLayout.LayoutParams(-1,-2))
        val tabs=LinearLayout(this).apply { tag="hunt-tabs";setPadding(0,dp(8),0,0) }
        for((label,kind,page) in listOf(Triple("Quest","clue","CLUE"),Triple("Leaderboard","trophy","RACE"))){
            val active=screen==page
            tabs.addView(button(label,active){screen=if(active)"MAP" else page;worldOnly=true;render()}.apply {
                icon=HuntIcon(kind,if(active)cream else ink);iconSize=dp(18);iconPadding=dp(6);textSize=12f;maxLines=1;ellipsize=android.text.TextUtils.TruncateAt.END
                minWidth=0;minimumWidth=0;cornerRadius=dp(22);setPadding(dp(10),dp(4),dp(10),dp(4))
                backgroundTintList=ColorStateList.valueOf(if(active)green else Color.argb(200,16,10,35))
            },LinearLayout.LayoutParams(0,-2,1f).apply { if(page=="CLUE")rightMargin=dp(8) })
        }
        top.addView(tabs,LinearLayout.LayoutParams(-1,-2));mapChrome.add(top)
        var panel: View?=null
        if(screen!="MAP"){
            val shell=FrameLayout(this).apply { tag="hunt-panel";background=GlassDrawable(if(Build.VERSION.SDK_INT>=31)175 else 220,true) }
            val scroll=ScrollView(this).apply { isFillViewport=screen=="RESULT";isVerticalScrollBarEnabled=false }
            val content=column();scroll.addView(content);shell.addView(scroll)
            when(screen){"CLUE"->clue(content,state);"RESULT"->resultPanel(content,state);else->race(content,state)}
            body.addView(shell,FrameLayout.LayoutParams(-1,-1).apply { setMargins(dp(12),dp(200),dp(12),dp(12)) });panel=shell
        }else{
            val dock=LinearLayout(this).apply { tag="hunt-bottom";orientation=LinearLayout.VERTICAL;background=GlassDrawable(210,true);setPadding(dp(12),dp(6),dp(12),dp(6)) }
            dock.addView(button("Continue question",true){screen=if(roundResult(store.state())!=null)"RESULT" else "CLUE";worldOnly=false;render()},LinearLayout.LayoutParams(-1,-2))
            dock.addView(text("Developed by Blitz Club",10f,ink).apply { tag="hunt-credit";gravity=Gravity.CENTER;setPadding(0,dp(3),0,0) })
            dock.addView(text("© OpenStreetMap · Overture Maps · DU audit",8f,muted).apply { tag="hunt-attribution";gravity=Gravity.CENTER;setPadding(0,dp(2),0,dp(2)) })
            body.addView(dock,FrameLayout.LayoutParams(-1,-2,Gravity.BOTTOM).apply { setMargins(dp(12),0,dp(12),dp(12)) });mapChrome.add(dock)
            dock.addOnLayoutChangeListener { _,_,_,_,_,_,_,_,_->map.viewportBottom=dock.height+dp(24);map.invalidate() }
        }
        body.addView(top,FrameLayout.LayoutParams(-1,-2,Gravity.TOP).apply { setMargins(dp(12),dp(12),dp(12),0) })
        top.addOnLayoutChangeListener { _,_,_,_,_,_,_,_,_->
            map.viewportTop=top.bottom+dp(10);map.invalidate()
            panel?.let { v->val lp=v.layoutParams as FrameLayout.LayoutParams;if(lp.topMargin!=map.viewportTop){lp.topMargin=map.viewportTop;v.layoutParams=lp} }
        }
        if(screen=="MAP"&&mapExploring){map.controlsHidden=true;mapChrome.forEach { it.alpha=0f;it.visibility=View.INVISIBLE }}else map.controlsHidden=false
        fingerprint=stateFingerprint(state);updateLabels(state)
    }
    private fun enrollment() {
        screen="LOGIN"
        val stage=FrameLayout(this);root.addView(stage,LinearLayout.LayoutParams(-1,0,1f))
        val cover=ImageView(this).apply { setImageBitmap(assets.open("title_cover.webp").use { BitmapFactory.decodeStream(it) });scaleType=ImageView.ScaleType.FIT_XY }
        stage.addView(cover,FrameLayout.LayoutParams(-1,-1))
        val scroll=ScrollView(this).apply { isFillViewport=true;isVerticalScrollBarEnabled=false }
        val center=LinearLayout(this).apply { orientation=LinearLayout.VERTICAL;gravity=Gravity.CENTER;setPadding(dp(28),dp(32),dp(28),dp(24)) }
        scroll.addView(center);stage.addView(scroll,FrameLayout.LayoutParams(-1,-1))
        val spacer=View(this);center.addView(spacer,LinearLayout.LayoutParams(1,0,1f))
        val content=column().apply { setPadding(dp(22),dp(18),dp(22),dp(18)) }
        content.addView(text("Join the hunt",22f,ink,true))
        val (userWrap,user)=field(if(BuildConfig.UI_PREVIEW) "Your name / team name" else "Team ID")
        val (pinWrap,pin)=field("Login code",true);content.addView(userWrap);content.addView(pinWrap)
        val feedback=text(if(store.state().optBoolean("auth_expired"))"Log in again to reconnect. Your saved progress is kept." else "",12f,Color.rgb(177,38,53));content.addView(feedback)
        lateinit var submit: MaterialButton
        submit=button("Log in",true) {
            if(user.text.isBlank() || pin.text.isBlank()) { feedback.text=if(BuildConfig.UI_PREVIEW) "Enter any name and password to continue." else "Enter your Team ID and login code.";return@button }
            val name=user.text.toString();val secret=pin.text.toString();val url=HuntApi.SERVER
            (getSystemService(INPUT_METHOD_SERVICE) as android.view.inputmethod.InputMethodManager).hideSoftInputFromWindow(window.decorView.windowToken,0)
            if(BuildConfig.UI_PREVIEW) { LocalPreview.install(this,store,name);pin.text.clear();enterWithDoor();return@button }
            if(!ensureAccess(eventPermissions) { submit.performClick() }) return@button
            submit.isEnabled=false;feedback.text="Signing in…"
            executor.execute {
                try {
                    val before=store.state();val result=HuntApi.post(url,"/api/enroll",JSONObject().put("code",name).put("pin",secret).put("public_key",DeviceCrypto.publicKey()).put("device_id",before.getString("device_id")).put("client_version",BuildConfig.VERSION_CODE));val response=result.data
                    val key=response.getString("organizer_public_key")
                    check(response.has("rounds")&&response.has("leaderboard")&&response.has("ack")) { "Update the Anazori server files, then log in again." }
                    check(!before.has("organizer_key") || before.getString("organizer_key")==key) { "Ask an organizer to reconnect your phone safely." }
                    val pkg=DeviceCrypto.verify(key,response.getJSONObject("package"));check(pkg.getString("device_id")==before.getString("device_id"))
                    check(pkg.optInt("protocol")>=2 && pkg.optString("workflow")=="QR_ONLY") { "Ask an organizer to prepare the hunt before signing in." }
                    val old=before.optJSONObject("package");check(old==null || old.getString("event_id")==pkg.getString("event_id")&&(old.getString("team_id")==pkg.getString("team_id")||old.optString("run_id")!=pkg.optString("run_id"))) { "This phone belongs to another team. Ask an organizer to switch it." }
                    store.update { it.remove("auth_expired");it.put("host",url).put("token",response.getString("token")).put("organizer_key",key).put("package",pkg);if(it.optInt("seq")==0) it.put("seq",response.getInt("last_seq")).put("hash",response.getString("last_hash")) }
                    store.applySync(response,result.roundTripMs,before.optJSONObject("package")?.optString("run_id"))
                    runOnUiThread { if(!isDestroyed) { pin.text.clear();try { kiosk.enter() } catch(_:Exception) { };startLocation();nextSync=0;enterWithDoor() } }
                } catch(e: Exception) { runOnUiThread { if(!isDestroyed) feedback.text=when { e is ApiError && e.status==401->"Check your Team ID and login code.";e is ApiError && e.status==409->"This team is already using another phone. Ask an organizer for help.";e is ApiError && e.status==429->"Too many tries. Wait a minute, then try again.";e is java.io.IOException->"Cannot connect right now. Check your internet and try again.";else->"Ask an organizer to help you sign in." } } }
                finally { runOnUiThread { if(!isDestroyed)submit.isEnabled=true } }
            }
        }.apply { icon=HuntIcon("arrow",cream);iconGravity=MaterialButton.ICON_GRAVITY_END }
        content.addView(submit)
        content.addView(text(if(BuildConfig.UI_PREVIEW) "Any name · any password" else "Use one phone for your team · allow camera and location",11f,muted).apply { gravity=Gravity.CENTER })
        // 30% white fill: the requested 70% transparent form, with fully opaque text.
        val loginInk=Color.rgb(20,49,65)
        fun readable(view: View) {
            if(view is TextView && view !is MaterialButton) { view.setTextColor(loginInk);view.setShadowLayer(0f,0f,0f,Color.TRANSPARENT) }
            if(view is TextInputLayout) { view.defaultHintTextColor=ColorStateList.valueOf(loginInk);view.boxStrokeColor=loginInk;view.setStartIconTintList(ColorStateList.valueOf(loginInk));view.setEndIconTintList(ColorStateList.valueOf(loginInk)) }
            if(view is ViewGroup) for(i in 0 until view.childCount) readable(view.getChildAt(i))
        }
        readable(content);feedback.setTextColor(Color.rgb(150,25,38))
        val card=FrameLayout(this).apply { background=GlassDrawable(77);addView(content) };center.addView(card,LinearLayout.LayoutParams(-1,-2));card.alpha=0f;card.translationY=dp(24).toFloat();card.animate().alpha(1f).translationY(0f).setDuration(650).start()
        center.addView(View(this),LinearLayout.LayoutParams(1,0,.15f))
    }
    private fun enterWithDoor() {
        val transition=DoorTransition(this);door=transition;root.removeAllViews();root.addView(transition,LinearLayout.LayoutParams(-1,-1))
        transition.post { transition.play { if(!isDestroyed) { door=null;worldOnly=false;screen="MAP";render() } } }
    }
    private fun lobbyCopy(phase: String)=when(phase){
        "COUNTDOWN"->"GET READY" to "Your quest unlocks when the countdown ends."
        "PAUSED"->"HUNT PAUSED" to "Wait for the organizer to resume."
        "ENDED"->"HUNT ENDED" to "Check the leaderboard and wait for the organizer."
        "VERIFY_CLOCK"->"RECONNECT" to "Connect to the internet to check the hunt time."
        else->"WAITING FOR START" to "You are signed in. Keep the app open while the organizer prepares the hunt."
    }
    private fun clue(content: LinearLayout,state: JSONObject) {
        val n=store.currentRound(state);val p=store.localProgress(state,n);val r=store.activeRiddle(state)
        content.addView(pixelText("YOUR RIDDLE",12f))
        content.addView(text("${p.optInt("completed")}/${store.roundTotal(n,state)} riddles solved",13f,muted))
        content.addView(com.google.android.material.progressindicator.LinearProgressIndicator(this).apply {
            trackThickness=dp(6);trackCornerRadius=dp(3);setIndicatorColor(green);trackColor=Color.argb(45,200,166,255)
            max=store.roundTotal(n,state);setProgressCompat(p.optInt("completed"),true);contentDescription="${p.optInt("completed")} of $max riddles solved"
        },LinearLayout.LayoutParams(-1,dp(12)).apply { bottomMargin=dp(12) })
        val phase=store.phase(state)
        val result=roundResult(state)
        if(result!=null) { content.addView(pixelText(result.title,14f));content.addView(text(result.detail,16f))
        } else if(phase!="PLAYING") {
            val copy=lobbyCopy(phase);content.addView(pixelText(copy.first,13f));content.addView(text(copy.second,16f))
        } else if(state.optBoolean("eliminated")) {
            content.addView(pixelText("ELIMINATED",15f,Color.rgb(255,157,178)))
            content.addView(text("Your expedition has ended. Ask an organizer if you need a review.",16f))
        } else if(r==null) {
            content.addView(pixelText(if(n==1) "QUESTS CLEARED!" else "QUESTS CLEARED!",14f))
            content.addView(text(if(BuildConfig.UI_PREVIEW) "Your demo seals are collected." else "Your riddles are complete. Connect to the internet to receive your result.",16f))
            if(BuildConfig.UI_PREVIEW && n==1) content.addView(button("Enter round two",true) { LocalPreview.roundTwo(store);render() })
        } else {
            val difficulty=r.optString("difficulty")
            if(difficulty.isNotBlank()&&difficulty!="neutral")content.addView(pixelText(difficulty.uppercase(),8f,Color.rgb(255,218,137)))
            content.addView(text(r.getString("title"),22f,ink,true))
            content.addView(text(r.getString("question"),18f))
            if(state.optInt("lives_remaining",3)==0) {
                content.addView(pixelText("HEARTS RECOVERING",14f))
                content.addView(text("Think about this riddle while your hearts recover. Scanning unlocks when they reset.",16f))
            } else {
                content.addView(button("Scan location QR",true) { openScanner() }.apply { icon=HuntIcon("scan",cream) })
                if(BuildConfig.UI_PREVIEW) content.addView(button("Test camera") { launchCamera(true) }.apply { icon=HuntIcon("scan",green) })
            }
        }
        content.addView(button("Explore map") { worldOnly=true;screen="MAP";render() })
        content.addView(button("Help") {
            HuntDialogBuilder(this).setTitle("Help").setItems(arrayOf("Find a place","Find my team","Camera & location",if(feedback.enabled)"Mute sound" else "Enable sound","Helpline")){_,which->when(which){
                0->showPlaces()
                1->{screen="MAP";render();map.recenter()}
                2->if(ensureAccess(eventPermissions){startLocation()})startLocation()
                3->{feedback.enabled=!feedback.enabled}
                4->emergencyMenu()
            }}.setNegativeButton("Close",null).show()
        })
        if(BuildConfig.UI_PREVIEW)content.addView(button("Preview controls") { previewMenu() })
    }
    private fun resultPanel(content: LinearLayout,state: JSONObject) {
        val result=roundResult(state) ?: return
        content.gravity=Gravity.CENTER
        if(result.place>0)content.addView(ImageView(this).apply { setImageDrawable(HuntIcon("trophy",green)) },LinearLayout.LayoutParams(dp(88),dp(88)).apply { bottomMargin=dp(24) })
        content.addView(text(result.title,30f,ink,true).apply { gravity=Gravity.CENTER })
        content.addView(text(result.detail,17f,muted).apply { gravity=Gravity.CENTER;setPadding(0,dp(18),0,dp(28)) })
        content.addView(button("View leaderboard",true) { screen="RACE";render() })
        content.addView(button("Explore map") { screen="MAP";worldOnly=true;render() })
    }
    private fun playerStatus(value: String)=when(value) {
        "WAITING"->"Waiting for the start";"PLAYING"->"On the hunt";"COOLDOWN"->"Hearts recovering"
        "FINISHED","QUALIFIED"->"Finished";"WINNER"->"1st place";"SECOND_PLACE"->"2nd place";"THIRD_PLACE"->"3rd place"
        "ELIMINATED"->"Hunt ended";"DISQUALIFIED"->"Ask an organizer";"TIME_OVER"->"Time is up";else->"On the hunt"
    }
    private fun race(content: LinearLayout,state: JSONObject) {
        content.addView(pixelText("THE RACE",12f))
        content.addView(text(if(BuildConfig.UI_PREVIEW) "Demo standings" else "Follow the race",12f,muted))
        val rows=state.optJSONObject("leaderboard")?.optJSONArray("rows") ?: JSONArray()
        if(rows.length()==0) content.addView(text("The leaderboard will appear when you connect to the internet."))
        for(i in 0 until rows.length()) {
            val row=rows.getJSONObject(i);val yours=ownStanding(row,state.getJSONObject("package"))
            val card=column().apply { background=GlassDrawable(if(yours) 100 else 65,true);setPadding(dp(12),dp(12),dp(12),dp(12)) }
            val rank=if(store.phase(state) in listOf("WAITING","COUNTDOWN"))"" else "#${row.optInt("rank",i+1)}  "
            card.addView(pixelText(rank+when(row.optInt("podium_place")){1->"1ST PLACE";2->"2ND PLACE";3->"3RD PLACE";else->if(yours)"YOU" else "TEAM"},9f))
            card.addView(text(row.getString("team"),19f,ink,true))
            card.addView(text("${row.optInt("completed")}/${row.optInt("total")} riddles solved · ${playerStatus(row.optString("outcome",row.optString("status")))}",12f,muted))
            card.addView(text("${row.optInt("lives_remaining",3)} lives · ${row.optInt("score")} points",12f,green))
            val solved=row.optJSONArray("solved") ?: JSONArray()
            if(solved.length()>0)card.addView(text((0 until solved.length()).joinToString(" · "){solved.getJSONObject(it).getString("title")},12f,muted))
            content.addView(card,LinearLayout.LayoutParams(-1,-2).apply { topMargin=dp(12) })
        }
        content.addView(button("Back to the world") { worldOnly=true;screen="MAP";render() })
    }
    private fun showPlaces() {
        val places=map.places()
        val content=column();val search=EditText(this).apply { hint="Search campus places";setSingleLine() };content.addView(search)
        val list=ListView(this);content.addView(list,LinearLayout.LayoutParams(-1,dp(300)))
        var matches=places
        fun filter(query: String) { matches=places.filter { (it.getString("name")+" "+it.optString("aliases")+" "+it.optString("display_name")).contains(query.trim(),true) };list.adapter=ArrayAdapter(this,android.R.layout.simple_list_item_1,matches.map { it.getString("name") }) }
        filter("");search.addTextChangedListener(object:android.text.TextWatcher { override fun beforeTextChanged(s: CharSequence?,a: Int,c: Int,d: Int) {} ;override fun onTextChanged(s: CharSequence?,a: Int,b: Int,c: Int) { filter(s.toString()) };override fun afterTextChanged(s: android.text.Editable?) {} })
        if(!BuildConfig.UI_PREVIEW) { search.showSoftInputOnFocus=false;search.isFocusable=false;search.setOnClickListener { LocalKeypad.show(this,search,false) } }
        val dialog=HuntDialogBuilder(this).setTitle("Explore campus").setView(content).setNegativeButton("Close",null).create()
        list.setOnItemClickListener { _,_,index,_ -> dialog.dismiss();showPlace(matches[index]) };dialog.show()
    }
    private fun showPlace(place: JSONObject) {
        val detail=place.optString("category","Campus place").replace('_',' ')
        HuntDialogBuilder(this).setBackground(GlassDrawable(225,true)).setTitle(place.getString("name")).setMessage(detail)
            .setPositiveButton("Show on map") { _,_ -> worldOnly=true;screen="MAP";render();map.showDestination(place) }.setNegativeButton("Close",null).show()
    }

    private fun previewMenu() {
        HuntDialogBuilder(this).setTitle("Your preview").setItems(arrayOf("Replay welcome / change name","Explore the whole map","Restart this demo hunt","Emergency help")) { _,which -> when(which) {
            0 -> { store.update { it.remove("token") };render() }
            1 -> { worldOnly=true;screen="MAP";render();map.fitCampus() }
            2 -> { LocalPreview.install(this,store,store.packageData()?.optString("team_name") ?: "Explorer");screen="CLUE";render() }
            3 -> emergencyMenu()
        } }.setNegativeButton("Back to exploring",null).show()
    }
    private fun playable(): Boolean {
        store.refreshCooldown()
        val rest=store.cooldownRemaining()
        if(rest!=null&&rest>0){message("Hearts recover in %02d:%02d. Your route and progress are saved.".format(rest/60000,(rest/1000)%60));return false}
        if(store.state().optBoolean("eliminated") || store.state().optInt("lives_remaining",3)<=0) { message("Your hearts are empty. Ask an organizer for help.");return false }
        if(BuildConfig.UI_PREVIEW) return true
        val state=store.state(); val n=store.currentRound(state); val r=state.optJSONObject("rounds")?.optJSONObject(n.toString())
        val remaining=store.remaining(state)
        val error=when {
            state.getJSONObject("package").getJSONObject("settings").optString("hunt_mode")!="continuous-v1" -> "The organizer is preparing your hunt. Please wait."
            state.getJSONObject("package").getJSONObject("settings").optBoolean("require_kiosk",true) && !kiosk.locked() -> "Ask the organizer to prepare this phone before playing."
            remaining==null -> "Connect to the internet after restarting your phone, or ask an organizer for help."
            store.phase(state)!="PLAYING" -> "The hunt is waiting, counting down, paused or ended."
            remaining<=0 -> "Time is up. Your progress is saved. Check with an organizer for your result."
            state.optBoolean("needs_review") -> "Ask an organizer to check your progress before continuing."
            state.optJSONObject("official_progress")?.optJSONObject(n.toString())?.optString("status") in listOf("DISQUALIFIED","ELIMINATED","TIME_OVER") -> "Your team cannot continue. Ask an organizer if you need help."
            else -> null
        }
        if(error!=null) { message(error); return false }; return true
    }
    private fun openScanner() {
        if(!playable()) return
        if(BuildConfig.UI_PREVIEW) {
            val checkpoint=target() ?: run { message("You have solved all your riddles.");return }
            HuntDialogBuilder(this).setTitle("QR SEAL · DEMO")
                .setMessage("Choose a demo scan.")
                .setPositiveButton("Correct seal") { _,_ -> LocalPreview.arrive(store,checkpoint);feedbackPolicy.quest(SystemClock.elapsedRealtime());render();notice("Seal collected","+100 demo points") }
                .setNeutralButton("Wrong-order seal") { _,_ -> LocalPreview.wrongScan(store);render();message(if(store.state().optBoolean("eliminated")) "Third wrong seal. Team eliminated." else "Wrong sequence. One life lost.") }
                .setNegativeButton("Cancel",null).show();return
        }
        if(target()==null) { message("You have solved all your riddles."); return }
        val needed=if(store.qrOnly())arrayOf(Manifest.permission.CAMERA)else eventPermissions
        if(!ensureAccess(needed) { startLocation();openScanner() }) return
        if(!store.qrOnly()&&!(getSystemService(LOCATION_SERVICE) as LocationManager).isLocationEnabled) {
            HuntDialogBuilder(this).setTitle("Turn on location")
                .setMessage("Phone location is off. An organizer can enable it in Android settings before you scan.")
                .setPositiveButton("Location settings") { _,_ -> if(kiosk.locked())message("Ask an organizer to unlock phone settings.")else startActivity(Intent(android.provider.Settings.ACTION_LOCATION_SOURCE_SETTINGS)) }
                .setNegativeButton("Back to map",null).show();return
        }
        startLocation();launchCamera(false)
    }
    private fun launchCamera(preview: Boolean) {
        if(scannerDialog!=null || isFinishing) return
        if(!ensureAccess(if(preview||store.qrOnly())arrayOf(Manifest.permission.CAMERA)else eventPermissions) { launchCamera(preview) }) return
        val frame=FrameLayout(this); val overlay=column().apply { setBackgroundColor(Color.argb(160,0,0,0)) }
        overlay.addView(text(if(preview)"Camera test" else "Scan checkpoint QR",20f,Color.WHITE,true))
        val dialog=HuntDialogBuilder(this).setView(frame).setNegativeButton("Back to map",null).setNeutralButton("Torch",null).create()
        scannerDialog=dialog
        scanner=HuntScanner(this,this,{ raw -> dialog.dismiss();if(preview)notice("QR detected","Camera test complete · hunt unchanged",false)else processQR(raw) },{ err -> dialog.dismiss();message(err) })
        frame.addView(scanner!!.view,FrameLayout.LayoutParams(-1,dp(430))); frame.addView(overlay,FrameLayout.LayoutParams(-1,-2,Gravity.TOP))
        dialog.setOnDismissListener { scanner?.close(); scanner=null; scannerDialog=null }
        dialog.setOnShowListener { dialog.getButton(AlertDialog.BUTTON_NEUTRAL).setOnClickListener { scanner?.torch() } }; dialog.show()
    }
    private fun processQR(raw: String) {
        if(!playable()) return
        val state=store.state();val riddle=store.activeRiddle(state) ?: return
        try {
            val envelope=JSONObject(raw);val qr=DeviceCrypto.verify(state.getString("organizer_key"),envelope)
            val checkpoints=state.getJSONObject("package").getJSONArray("checkpoints")
            val scanned=(0 until checkpoints.length()).map { checkpoints.getJSONObject(it) }.firstOrNull { it.getString("id")==qr.optString("checkpoint_id") }
            if(qr.optString("event_id")!=state.getJSONObject("package").getString("event_id") || scanned==null ||
                scanned.optString("qr_nonce_hash").isBlank() || Rules.sha256(qr.optString("nonce"))!=scanned.optString("qr_nonce_hash")) {
                gamePopup("QR not recognised","This is not a checkpoint for this hunt. No heart lost.","Try again");return
            }
            val correct=scanned.getString("id")==riddle.getString("checkpoint_id")
            val fix=freshLocation(state)
            val gps=if(fix!=null) JSONObject(fix.toString()).put("age_ms",SystemClock.elapsedRealtime()-state.getLong("location_elapsed")) else JSONObject()
            val error=if(!correct||store.qrOnly(state)) null else if(fix==null) "We are finding your location. Move into open sky and try again."
                else Rules.gpsError(gps.getDouble("lat"),gps.getDouble("lon"),gps.getDouble("accuracy"),gps.getLong("age_ms"),gps.optBoolean("mock"),
                    scanned.getDouble("lat"),scanned.getDouble("lon"),scanned.getDouble("radius_m"))
            store.record("SCAN",JSONObject().put("riddle_id",riddle.getString("id")).put("qr",envelope).put("gps",gps).put("local_penalty",!correct),correct && error==null)
            if(correct && error==null) feedbackPolicy.quest(SystemClock.elapsedRealtime())
            nextSync=0
            val after=store.state();val cleared=store.localProgress(after,store.currentRound(after)).optInt("completed")>=store.roundTotal(store.currentRound(after),after)
            val title=when {error!=null->"Location needed";correct->"CORRECT!";after.optBoolean("eliminated")->"ELIMINATED";after.optInt("lives_remaining",3)==0->"HEARTS RECOVERING";else->"WRONG CHECKPOINT"}
            val detail=when {error!=null->"$error No heart lost.";correct&&cleared->"Last checkpoint collected. Your scans are saved; connect to confirm your result.";correct->"Checkpoint collected. Unlock your next riddle. Your progress is saved.";after.optBoolean("eliminated")->"No hearts remain. Your hunt has ended. Ask an organizer if you need help.";after.optInt("lives_remaining",3)==0->"One heart lost. Rest for 15 minutes, then continue this riddle.";else->"One heart lost. ${after.optInt("lives_remaining",3)} hearts remain. Solve your current riddle and try its location."}
            store.update { it.put("scan_notice",JSONObject().put("title",title).put("detail",detail).put("button",if(correct&&error==null)if(cleared)"View result" else "Unlock next riddle" else "Continue").put("correct",correct&&error==null).put("effect",if(error!=null)"" else if(correct)"seal" else "wrong")) }
            showScanNotice()
        } catch(error:Exception) { gamePopup("Scan unavailable",if(error is org.json.JSONException||error is java.security.GeneralSecurityException)"This QR code could not be read. No heart lost." else error.message?:"Try scanning again.","Continue") }
    }
    private fun stateFingerprint(state: JSONObject)=listOf(screen,state.optInt("lives_remaining",3),state.optBoolean("eliminated"),state.optString("package_hash"),state.optString("local_progress"),
        state.optJSONObject("rounds")?.optJSONObject("1")?.optString("status"),state.optJSONObject("rounds")?.optJSONObject("2")?.optString("status"),state.optJSONObject("rounds")?.optJSONObject("3")?.optString("status"),state.optBoolean("needs_review"),
        store.phase(state),roundResult(state)?.key,if(screen=="RACE") state.optJSONObject("leaderboard")?.optInt("revision") else 0).joinToString("|")
    private fun refresh() {
        store.refreshCooldown()
        if(door!=null) return
        if(!store.state().has("token")) { if(store.state().optBoolean("auth_expired")&&screen!="LOGIN")render();return }
        val state=store.state()
        if(!BuildConfig.UI_PREVIEW && !stopped && !syncInFlight && SystemClock.elapsedRealtime()>=nextSync) {
            syncInFlight=true
            val waiting=store.phase(state) in listOf("WAITING","COUNTDOWN")
            nextSync=SystemClock.elapsedRealtime()+backoff
            executor.execute { try{val ok=HuntApi.sync(applicationContext);backoff=if(ok||waiting)5000 else (backoff*2).coerceAtMost(300000)
                nextSync=SystemClock.elapsedRealtime()+backoff;runOnUiThread { if(!isFinishing&&!isDestroyed)updateLabels(store.state()) }
            }finally{syncInFlight=false} }
        }
        if(gameDialog!=null)return
        if(!stopped&&showScanNotice())return
        if(!stopped) {
            val phase=store.phase(state)
            val previousPhase=lastPhase
            val startNotice=lastPhase in listOf("WAITING","COUNTDOWN")&&phase=="PLAYING"
            if(startNotice) {screen="MAP";worldOnly=false}
            lastPhase=phase
            val commands=state.optJSONArray("commands") ?: JSONArray()
            if(commands.length()>0) {
                val command=commands.getJSONObject(0)
                val anchor=state.optLong("clock_elapsed")
                val clockValid=state.optInt("clock_boot",-2)==store.boot() && SystemClock.elapsedRealtime()>=anchor
                val trustedNow=if(clockValid) state.optLong("clock_server")+SystemClock.elapsedRealtime()-anchor else Long.MAX_VALUE
                if(command.getLong("expires_at")<=trustedNow) {
                    store.update { it.put("commands",JSONArray((1 until commands.length()).map { i -> commands.getJSONObject(i) })) }
                    return
                }
                store.update { val applied=it.optJSONArray("applied_commands") ?: JSONArray(); applied.put(command.getString("id"));
                    it.put("applied_commands",applied).put("commands",JSONArray((1 until commands.length()).map { i -> commands.getJSONObject(i) })) }
                if(command.getString("kind")=="EXIT") { kiosk.exit(); message("Phone unlocked. ${command.optString("message")}") }
                else if(command.getString("kind")=="RESUME_KIOSK") { store.update { it.put("admin_unlocked",false) };message(if(kiosk.enter())"Your phone is ready for the hunt." else "Ask an organizer to prepare this phone.") }
                else message(command.optString("message"))
                nextSync=0
            }
            if(stateFingerprint(state)!=fingerprint) render() else updateLabels(state)
            if(gameDialog!=null)return
            roundResult(state)?.let { result->
                val pkg=state.getJSONObject("package");val key="${pkg.optString("event_id")}/${pkg.optString("run_id")}/${store.currentRound(state)}/${result.key}"
                if(state.optString("last_result_notice")!=key){store.update { it.put("last_result_notice",key);if(result.key=="closed")it.put("podium_closed_notice",pkg.optString("event_id")+"/"+pkg.optString("run_id")) };screen="RESULT";worldOnly=false;render();gamePopup(result.title,result.detail,"View result",result.place>0,effect=if(result.place>0)"win" else "");return}
            }
            val closedKey=state.getJSONObject("package").optString("event_id")+"/"+state.getJSONObject("package").optString("run_id")
            val winners=winnerSummary(state)
            if(winners!=null&&state.optJSONObject("rounds")?.optJSONObject("1")?.optString("end_reason")=="PODIUM_COMPLETE"){
                if(state.optString("podium_closed_notice")!=closedKey){store.update { it.put("podium_closed_notice",closedKey) };gamePopup("HUNT COMPLETE",winners,"View leaderboard",true,effect="win"){screen="RACE";render()}}
                return
            }
            if(startNotice){gamePopup("HUNT STARTED","Your first riddle is ready.","Open riddle",true,effect="start"){screen="CLUE";render()};return}
            if(previousPhase.isNotEmpty()&&previousPhase!=phase&&phase in listOf("PAUSED","ENDED")){val copy=lobbyCopy(phase);gamePopup(copy.first,copy.second);return}
            val pkg=state.getJSONObject("package");val rows=state.optJSONObject("leaderboard")?.optJSONArray("rows")?:JSONArray()
            for(i in 0 until rows.length()){
                val row=rows.getJSONObject(i);val place=row.optInt("podium_place")
                if(place !in 1..3||ownStanding(row,pkg))continue
                val key="${pkg.optString("event_id")}/${pkg.optString("run_id")}/$place"
                val seen=state.optJSONObject("podium_notices")?:JSONObject()
                if(!seen.optBoolean(key)){store.update { val notices=it.optJSONObject("podium_notices")?:JSONObject();it.put("podium_notices",notices.put(key,true)) }
                    gamePopup("${row.optString("team")} finished!","${when(place){1->"1st";2->"2nd";else->"3rd"}} place has been confirmed.","Continue",true,effect="win");return}
            }
        }
    }
    private fun winnerSummary(state: JSONObject): String? {
        val rows=state.optJSONObject("leaderboard")?.optJSONArray("rows")?:return null
        val winners=(0 until rows.length()).map { rows.getJSONObject(it) }.filter { it.optInt("podium_place") in 1..3 }.sortedBy { it.optInt("podium_place") }
        if(winners.size!=3)return null
        return winners.joinToString("\n") { "${when(it.getInt("podium_place")){1->"1st";2->"2nd";else->"3rd"}} · ${it.optString("team")}" }
    }
    private fun roundResult(state: JSONObject): RoundResult.Copy? {
        if(BuildConfig.UI_PREVIEW)return null
        val n=store.currentRound(state);val result=RoundResult.copy(state,n,store.roundTotal(n,state))?:return null
        return if(result.key=="closed")result.copy(detail="All three winning teams have finished.\n\n"+(winnerSummary(state)?:"Open the leaderboard for the winners."))else result
    }
    private fun updateLabels(state: JSONObject) {
        map.setOpponents(state.optJSONObject("opponents"),state.optLong("clock_server")+(SystemClock.elapsedRealtime()-state.optLong("clock_elapsed")))
        val n=store.currentRound(state);val remaining=store.remaining(state);val phase=store.phase(state)
        val display=if(phase=="COUNTDOWN")store.startsIn(state) else remaining;val unlimited=display==Long.MAX_VALUE
        val seconds=((display?.takeUnless { it==Long.MAX_VALUE }?:0)+999)/1000
        clockTitle?.text=when(phase){"COUNTDOWN"->"STARTS IN";"WAITING"->"NOT STARTED";"PAUSED"->"PAUSED";else->if(unlimited)"NO TIME LIMIT" else "TIME LEFT"}
        timer?.text=if(phase=="WAITING")"Waiting" else if(display==null)"Connect" else if(unlimited)"∞" else "%02d:%02d".format(seconds/60,seconds%60)
        if(BuildConfig.UI_PREVIEW) {
            gpsLabel?.text="Simulated GPS · offline preview"
        } else {
            val fix=freshLocation(state);val locationManager=getSystemService(LOCATION_SERVICE) as LocationManager
            gpsLabel?.text=when {
                ContextCompat.checkSelfPermission(this,Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED->"Allow location to find your team"
                !locationManager.isLocationEnabled->"Turn on your phone's location"
                fix==null->"Finding your team · move into open sky"
                !map.contains(fix.optDouble("lat"),fix.optDouble("lon"))->"Your team is outside the campus map"
                fix.optDouble("accuracy")>30->"Move into open sky for a clearer location"
                else->"Your character shows your team's location"
            }
        }
        val rest=store.cooldownRemaining(state)
        hearts?.text=(1..3).joinToString(" ") { if(it<=state.optInt("lives_remaining",3)) "♥" else "♡" }+"   "+if(state.optBoolean("eliminated")) "ELIMINATED" else if(rest!=null&&rest>0) "REST %02d:%02d".format(rest/60000,(rest/1000)%60) else "THE HUNT"
        map.setData(state.optJSONObject("package"),null,freshLocation(state))
        if(!stopped && door==null && gameDialog==null) {
            val pkg=state.optJSONObject("package") ?: return
            val board=state.optJSONObject("leaderboard") ?: return
            val rows=board.optJSONArray("rows") ?: return
            val row=(0 until rows.length()).map { rows.getJSONObject(it) }.firstOrNull { ownStanding(it,pkg) }
            val rank=row?.optInt("rank",0)?.takeIf { it>0 }
            val eligible=!state.optBoolean("eliminated") && row?.optString("status") !in listOf("ELIMINATED","DISQUALIFIED","TIME_OVER") && row?.optInt("round",n)==n
            if(feedbackPolicy.standing("${pkg.optString("event_id")}/${pkg.optString("team_id")}/$n",board.optLong("revision",-1),rank,eligible,SystemClock.elapsedRealtime()))
                notice("You climbed to #$rank","Race board updated",true,true)
        }
    }
    private val eventPermissions=arrayOf(Manifest.permission.CAMERA,Manifest.permission.ACCESS_FINE_LOCATION,Manifest.permission.ACCESS_COARSE_LOCATION)
    private fun accessGranted(permissions: Array<String>)=permissions.all { ContextCompat.checkSelfPermission(this,it)==PackageManager.PERMISSION_GRANTED }
    internal fun ensureAccess(permissions: Array<String>,resume: () -> Unit): Boolean {
        if(accessGranted(permissions)) return true
        if(permissionRetry!=null) return false
        permissionRetry=resume;requestedAccess=permissions
        requestPermissions(permissions,110);return false
    }
    override fun onRequestPermissionsResult(requestCode: Int,permissions: Array<out String>,grantResults: IntArray) {
        super.onRequestPermissionsResult(requestCode,permissions,grantResults)
        if(requestCode!=110) return
        val retry=permissionRetry ?: return;permissionRetry=null
        if(isFinishing || isDestroyed) return
        if(accessGranted(requestedAccess)) { retry();return }
        val needed=requestedAccess.copyOf()
        val dialog=HuntDialogBuilder(this).setBackground(GlassDrawable(220,true)).setTitle(if(needed.size==1)"Camera access" else "Camera & precise location")
            .setMessage(if(needed.size==1)"Allow Camera to test QR scanning." else "Allow Camera and Location → Precise to scan checkpoints. Saved maps remain available without access.")
            .setPositiveButton("Try again",null).setNeutralButton("Settings",null).setNegativeButton("Later",null).create()
        dialog.show()
        dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener {
            dialog.dismiss();if(ensureAccess(needed,retry)) retry()
        }
        dialog.getButton(AlertDialog.BUTTON_NEUTRAL).setOnClickListener {
            dialog.dismiss()
            val open={ startActivity(Intent(android.provider.Settings.ACTION_APPLICATION_DETAILS_SETTINGS,android.net.Uri.parse("package:$packageName")));Unit }
            if(kiosk.locked())message("Ask an organizer to unlock phone settings.")else open()
        }
    }
    private var acceptedLocation: Location?=null
    private fun startLocation() {
        if(ContextCompat.checkSelfPermission(this,Manifest.permission.ACCESS_FINE_LOCATION)!=PackageManager.PERMISSION_GRANTED) return
        val manager=getSystemService(LOCATION_SERVICE) as LocationManager
        try {
            manager.removeUpdates(this)
            // A stationary team still needs a fresh fix when scanning. A distance filter would
            // make a valid checkpoint sample expire while the team stands and solves its clue.
            val providers=if(Build.VERSION.SDK_INT>=31 && manager.allProviders.contains(LocationManager.FUSED_PROVIDER))
                listOf(LocationManager.FUSED_PROVIDER,LocationManager.GPS_PROVIDER)
            else listOf(LocationManager.GPS_PROVIDER,LocationManager.NETWORK_PROVIDER)
            (providers+LocationManager.NETWORK_PROVIDER).distinct().filter { manager.allProviders.contains(it) }.forEach { provider->
                try { if(manager.isProviderEnabled(provider)){
                    if(Build.VERSION.SDK_INT>=31)manager.requestLocationUpdates(provider,android.location.LocationRequest.Builder(5000).setQuality(android.location.LocationRequest.QUALITY_HIGH_ACCURACY).setMinUpdateDistanceMeters(0f).build(),mainExecutor,this)
                    else manager.requestLocationUpdates(provider,5000,0f,this)
                    manager.getLastKnownLocation(provider)?.let { onLocationChanged(it) }
                } }
                catch(_:SecurityException) { } catch(_:IllegalArgumentException) { }
            }
        } catch(_:SecurityException) { } catch(_:IllegalArgumentException) { }
    }
    override fun onLocationChanged(location: Location) {
        val now=SystemClock.elapsedRealtimeNanos()
        if(!LocationQuality.accept(location,acceptedLocation,now)) return
        acceptedLocation=Location(location)
        val age=(now-location.elapsedRealtimeNanos)/1_000_000
        val fix=JSONObject().put("lat",location.latitude).put("lon",location.longitude).put("accuracy",location.accuracy.toDouble())
            .put("mock",if(Build.VERSION.SDK_INT>=31) location.isMock else location.isFromMockProvider)
        store.update { it.put("location",fix).put("location_elapsed",SystemClock.elapsedRealtime()-age).put("location_boot",store.boot()) }
        if(location.hasBearing()) map.heading(location.bearing)
        updateLabels(store.state())
    }
    private fun emergencyMenu() {
        val number=store.packageData()?.optJSONObject("settings")?.optString("organizer_phone")?.trim().orEmpty()
        HuntDialogBuilder(this).setTitle("Organizer helpline")
            .setMessage(number.ifEmpty { "No helpline is configured. Ask a nearby organizer." })
            .setPositiveButton(if(number.isEmpty())"OK" else "Open dialer") { _,_->
                if(number.isNotEmpty()){emergency=true;try{if(!kiosk.emergency(number))message("Dialer unavailable. Ask a nearby organizer.")}catch(_:Exception){message("Unable to open the helpline. Ask a nearby organizer.")}}
            }.setNegativeButton("Back",null).show()
    }
}
