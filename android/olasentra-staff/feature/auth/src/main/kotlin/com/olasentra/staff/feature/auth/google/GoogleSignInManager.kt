package com.olasentra.staff.feature.auth.google

import android.app.Activity
import android.content.Context
import android.content.ContextWrapper
import androidx.credentials.CredentialManager
import androidx.credentials.CustomCredential
import androidx.credentials.GetCredentialRequest
import androidx.credentials.exceptions.GetCredentialCancellationException
import androidx.credentials.exceptions.GetCredentialException
import com.google.android.libraries.identity.googleid.GetGoogleIdOption
import com.google.android.libraries.identity.googleid.GoogleIdTokenCredential
import javax.inject.Inject
import javax.inject.Named
import javax.inject.Singleton

sealed class GoogleSignInResult {
    data class Success(val idToken: String) : GoogleSignInResult()

    data object Cancelled : GoogleSignInResult()

    data class Error(val message: String) : GoogleSignInResult()
}

@Singleton
class GoogleSignInManager @Inject constructor(
    @Named("google_web_client_id") private val webClientId: String,
) {

    suspend fun signIn(context: Context): GoogleSignInResult {
        if (webClientId.isBlank()) {
            return GoogleSignInResult.Error(
                "Google Web Client ID is not configured. Set GOOGLE_WEB_CLIENT_ID in local.properties.",
            )
        }

        val activity = context.findActivity()
            ?: return GoogleSignInResult.Error("Unable to start Google Sign-In")

        return try {
            val credentialManager = CredentialManager.create(activity)
            val googleIdOption = GetGoogleIdOption.Builder()
                .setFilterByAuthorizedAccounts(false)
                .setServerClientId(webClientId)
                .setAutoSelectEnabled(false)
                .build()

            val request = GetCredentialRequest.Builder()
                .addCredentialOption(googleIdOption)
                .build()

            val result = credentialManager.getCredential(
                request = request,
                context = activity,
            )

            val credential = result.credential
            if (credential is CustomCredential &&
                credential.type == GoogleIdTokenCredential.TYPE_GOOGLE_ID_TOKEN_CREDENTIAL
            ) {
                val googleCredential = GoogleIdTokenCredential.createFrom(credential.data)
                val idToken = googleCredential.idToken
                if (idToken.isNullOrBlank()) {
                    GoogleSignInResult.Error("Google did not return an ID token")
                } else {
                    GoogleSignInResult.Success(idToken)
                }
            } else {
                GoogleSignInResult.Error("Unexpected Google credential type")
            }
        } catch (cancellation: GetCredentialCancellationException) {
            GoogleSignInResult.Cancelled
        } catch (exception: GetCredentialException) {
            GoogleSignInResult.Error(exception.message ?: "Google Sign-In failed")
        } catch (exception: Exception) {
            GoogleSignInResult.Error(exception.message ?: "Google Sign-In failed")
        }
    }

    private fun Context.findActivity(): Activity? {
        var currentContext = this
        while (currentContext is ContextWrapper) {
            if (currentContext is Activity) {
                return currentContext
            }
            currentContext = currentContext.baseContext
        }
        return null
    }
}
