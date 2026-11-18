package com.campushunt.app

import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import org.json.JSONObject
import java.security.KeyFactory
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.Signature
import java.security.spec.ECGenParameterSpec
import java.security.spec.X509EncodedKeySpec
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.PBEKeySpec

object DeviceCrypto {
    private const val ALIAS="campus.hunt.device.v1"
    fun encode(bytes: ByteArray): String=Base64.encodeToString(bytes,Base64.NO_WRAP)
    fun decode(value: String): ByteArray=Base64.decode(value,Base64.NO_WRAP)
    private fun store(): KeyStore=KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    @Synchronized fun ensureKey() {
        if (!store().containsAlias(ALIAS)) KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC,"AndroidKeyStore").apply {
            initialize(KeyGenParameterSpec.Builder(ALIAS,KeyProperties.PURPOSE_SIGN or KeyProperties.PURPOSE_VERIFY)
                .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
                .setDigests(KeyProperties.DIGEST_SHA256).build())
        }.generateKeyPair()
    }
    fun publicKey(): String { ensureKey(); return encode(store().getCertificate(ALIAS).publicKey.encoded) }
    fun sign(value: JSONObject): JSONObject {
        ensureKey(); val raw=value.toString().toByteArray(Charsets.UTF_8)
        val signature=Signature.getInstance("SHA256withECDSA").apply {
            initSign(store().getKey(ALIAS,null) as java.security.PrivateKey); update(raw)
        }.sign()
        return JSONObject().put("payload",encode(raw)).put("signature",encode(signature))
    }
    fun verify(publicKey: String, envelope: JSONObject): JSONObject {
        val raw=decode(envelope.getString("payload"))
        val key=KeyFactory.getInstance("EC").generatePublic(X509EncodedKeySpec(decode(publicKey)))
        val valid=Signature.getInstance("SHA256withECDSA").apply { initVerify(key); update(raw) }.verify(decode(envelope.getString("signature")))
        check(valid) { "Organizer signature is invalid" }
        return JSONObject(String(raw,Charsets.UTF_8))
    }
    fun passwordMatches(pin: String, verifier: JSONObject): Boolean {
        val spec=PBEKeySpec(pin.toCharArray(),decode(verifier.getString("salt")),verifier.getInt("iterations"),256)
        return try {
            java.security.MessageDigest.isEqual(SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded,
                decode(verifier.getString("value")))
        } finally { spec.clearPassword() }
    }
}
