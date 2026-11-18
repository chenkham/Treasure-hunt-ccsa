package com.campushunt.app

import android.app.Activity
import android.app.ActivityManager
import android.app.admin.DeviceAdminReceiver
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.BroadcastReceiver
import android.os.Build
import android.os.UserManager
import org.json.JSONObject

class HuntAdminReceiver: DeviceAdminReceiver() {
    override fun onLockTaskModeEntering(context: Context,intent: Intent,pkg: String) {
        val store=HuntStore(context); try { store.update { it.put("kiosk",true) } } finally { store.close() }
    }
    override fun onLockTaskModeExiting(context: Context,intent: Intent) {
        val store=HuntStore(context); try { store.update { it.put("kiosk",false) } } finally { store.close() }
    }
}

class HuntBootReceiver: BroadcastReceiver() {
    override fun onReceive(context: Context,intent: Intent) {
        if(intent.action!=Intent.ACTION_BOOT_COMPLETED) return
        val manager=context.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        val enabled=context.createDeviceProtectedStorageContext().getSharedPreferences("hunt-kiosk",Context.MODE_PRIVATE).getBoolean("active",false)
        if(manager.isDeviceOwnerApp(context.packageName) && enabled) {
            context.startActivity(Intent(context,MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TOP))
        }
    }
}

class Kiosk(private val activity: Activity, private val store: HuntStore) {
    private val manager=activity.getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
    private val component=ComponentName(activity,HuntAdminReceiver::class.java)
    private val restrictions=listOf(UserManager.DISALLOW_CREATE_WINDOWS,UserManager.DISALLOW_SAFE_BOOT,
        UserManager.DISALLOW_ADD_USER,UserManager.DISALLOW_INSTALL_APPS,UserManager.DISALLOW_UNINSTALL_APPS) +
        (if(Build.VERSION.SDK_INT>=35) listOf(UserManager.DISALLOW_ASSIST_CONTENT) else emptyList()) +
        if(!BuildConfig.DEBUG) listOf(UserManager.DISALLOW_DEBUGGING_FEATURES,UserManager.DISALLOW_USB_FILE_TRANSFER) else emptyList()
    fun owner()=manager.isDeviceOwnerApp(activity.packageName)
    fun locked()=(activity.getSystemService(Context.ACTIVITY_SERVICE) as ActivityManager).lockTaskModeState==ActivityManager.LOCK_TASK_MODE_LOCKED
    fun enter(): Boolean {
        if(!owner()) {
            if(manager.isLockTaskPermitted(activity.packageName) && !store.state().optBoolean("admin_unlocked")) activity.startLockTask()
            store.update { it.put("kiosk",locked()) }; return locked()
        }
        if(store.state().optBoolean("admin_unlocked")) return false
        // Record only restrictions introduced by this app, so exit preserves prior administrator policy.
        val existing=store.state().optJSONArray("owned_restrictions")
        if(existing==null) {
            val before=manager.getUserRestrictions(component)
            store.update { it.put("owned_restrictions",org.json.JSONArray(restrictions.filter { r -> !before.getBoolean(r) })) }
        }
        manager.setLockTaskPackages(component,arrayOf(activity.packageName))
        if(!store.state().has("capture_policy_before"))store.update { it.put("capture_policy_before",manager.getScreenCaptureDisabled(component)) }
        manager.setScreenCaptureDisabled(component,true)
        manager.addPersistentPreferredActivity(component,IntentFilter(Intent.ACTION_MAIN).apply {
            addCategory(Intent.CATEGORY_HOME); addCategory(Intent.CATEGORY_DEFAULT)
        },ComponentName(activity,MainActivity::class.java))
        manager.setLockTaskFeatures(component,DevicePolicyManager.LOCK_TASK_FEATURE_GLOBAL_ACTIONS)
        restrictions.forEach { manager.addUserRestriction(component,it) }
        manager.setStatusBarDisabled(component,true)
        activity.createDeviceProtectedStorageContext().getSharedPreferences("hunt-kiosk",Context.MODE_PRIVATE).edit().putBoolean("active",true).apply()
        activity.startLockTask(); store.update { it.put("kiosk",locked()) }; return locked()
    }
    fun exit() {
        store.update { it.put("admin_unlocked",true).put("kiosk",false) }
        activity.createDeviceProtectedStorageContext().getSharedPreferences("hunt-kiosk",Context.MODE_PRIVATE).edit().putBoolean("active",false).apply()
        if(locked()) activity.stopLockTask()
        if(owner()) {
            if(store.state().has("capture_policy_before")) {
                manager.setScreenCaptureDisabled(component,store.state().optBoolean("capture_policy_before"))
                store.update { it.remove("capture_policy_before") }
            }
            manager.setStatusBarDisabled(component,false)
            val owned=store.state().optJSONArray("owned_restrictions") ?: org.json.JSONArray()
            for(i in 0 until owned.length()) manager.clearUserRestriction(component,owned.getString(i))
            manager.setLockTaskPackages(component,emptyArray())
            manager.clearPackagePersistentPreferredActivities(component,activity.packageName)
            store.update { it.remove("owned_restrictions") }
        }
    }
    fun emergency(number: String): Boolean {
        val phone=number.filter { it.isDigit()||it=='+' };require(Regex("\\+?[0-9]{3,25}").matches(phone)) { "Configure a valid helpline" }
        // Public SDK dial intent; emergency-dialer-only APIs are reserved for system apps.
        val intent=Intent(Intent.ACTION_DIAL,android.net.Uri.fromParts("tel",phone,null))
        val packageName=intent.resolveActivity(activity.packageManager)?.packageName ?: return false
        if(owner()) manager.setLockTaskPackages(component,arrayOf(activity.packageName,packageName))
        activity.startActivity(intent)
        if(store.packageData()!=null) store.record("INCIDENT",JSONObject().put("message","Organizer helpline opened"),false)
        return true
    }
}
