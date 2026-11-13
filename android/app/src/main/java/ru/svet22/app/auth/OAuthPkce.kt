package ru.svet22.app.auth

import android.util.Base64
import java.security.MessageDigest
import java.security.SecureRandom

/**
 * PKCE values for a single OAuth authorization attempt.
 *
 * Keep the verifier on the device until the authorization code is received.
 * Never log it or persist it in analytics.
 */
object OAuthPkce {
    private val random = SecureRandom()

    data class Challenge(val verifier: String, val challenge: String, val state: String)

    fun create(): Challenge {
        val verifier = randomBase64Url(32)
        val digest = MessageDigest.getInstance("SHA-256")
            .digest(verifier.toByteArray(Charsets.US_ASCII))
        val challenge = Base64.encodeToString(
            digest, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP
        )
        return Challenge(verifier, challenge, randomBase64Url(32))
    }

    private fun randomBase64Url(byteCount: Int): String {
        val bytes = ByteArray(byteCount)
        random.nextBytes(bytes)
        return Base64.encodeToString(
            bytes, Base64.URL_SAFE or Base64.NO_PADDING or Base64.NO_WRAP
        )
    }
}
