package com.olasentra.staff.data.registration

import com.olasentra.staff.core.network.ReliableDns
import java.net.CookieManager
import java.net.CookiePolicy
import javax.inject.Inject
import javax.inject.Singleton
import okhttp3.JavaNetCookieJar
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.MultipartBody
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.TimeUnit
import kotlin.io.use

data class GoogleVerifyResult(
    val email: String = "",
    val csrfToken: String = "",
    val error: String? = null,
)

data class EmailOtpResult(
    val resendInSeconds: Int = 60,
    val error: String? = null,
)

data class UploadPart(
    val fileName: String,
    val mimeType: String,
    val bytes: ByteArray,
) {
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is UploadPart) return false
        return fileName == other.fileName &&
            mimeType == other.mimeType &&
            bytes.contentEquals(other.bytes)
    }

    override fun hashCode(): Int {
        var result = fileName.hashCode()
        result = 31 * result + mimeType.hashCode()
        result = 31 * result + bytes.contentHashCode()
        return result
    }
}

data class RegistrationMultipartPayload(
    val csrfToken: String,
    val formSlug: String,
    val staffRole: String,
    val verifiedGoogleEmail: String,
    val surname: String,
    val firstName: String,
    val fullAddress: String,
    val eircode: String,
    val email: String,
    val mobile: String,
    val dateOfBirth: String,
    val gender: String,
    val ppsNumber: String,
    val bankIban: String,
    val psaLicence: String,
    val psaExpiryDate: String,
    val eventIds: List<Long>,
    val psaFrontImage: UploadPart? = null,
    val psaBackImage: UploadPart? = null,
)

data class RegistrationOptionsJson(
    val formSlug: String,
    val staffRole: String,
    val events: List<RegistrationEventJson>,
    val genders: List<RegistrationGenderJson> = emptyList(),
)

data class RegistrationEventJson(
    val eventId: Long,
    val label: String,
    val venueName: String,
    val eventDate: String,
    val timeLabel: String,
    val isFull: Boolean,
)

data class RegistrationGenderJson(
    val value: String,
    val label: String,
)

data class RegistrationSubmitJson(
    val success: Boolean,
    val message: String,
    val count: Int,
    val statusUrl: String? = null,
    val errors: List<String> = emptyList(),
    val httpCode: Int = 200,
)

private const val DEFAULT_REGISTRATION_SITE_URL = "https://register.olasentra.com"

@Singleton
class RegistrationSiteClient @Inject constructor() {

    private val cookieManager = CookieManager(null, CookiePolicy.ACCEPT_ALL)
    private val client = OkHttpClient.Builder()
        .dns(ReliableDns)
        .cookieJar(JavaNetCookieJar(cookieManager))
        .connectTimeout(30, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .writeTimeout(120, TimeUnit.SECONDS)
        .build()

    fun verifyGoogle(siteUrl: String, idToken: String): GoogleVerifyResult {
        val base = normalizeBase(siteUrl)
        val request = Request.Builder()
            .url("$base/api/registration-google-verify.php")
            .post(
                JSONObject()
                    .put("id_token", idToken)
                    .toString()
                    .toRequestBody("application/json".toMediaType()),
            )
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val serverError = parseError(body)
                val message = when {
                    response.code == 404 && serverError == null ->
                        "Registration verification endpoint not found on server."
                    serverError != null -> serverError
                    else -> "Google verification failed (HTTP ${response.code})."
                }
                return GoogleVerifyResult(error = message)
            }

            val json = parseJsonObject(body, "Google verification response")
            if (!json.optBoolean("ok")) {
                return GoogleVerifyResult(
                    error = json.optString("error", "Google verification failed."),
                )
            }
            return GoogleVerifyResult(
                email = json.optString("email"),
                csrfToken = json.optString("csrf_token"),
            )
        }
    }

    fun sendRegistrationOtp(siteUrl: String, email: String): EmailOtpResult {
        val base = normalizeBase(siteUrl)
        val request = Request.Builder()
            .url("$base/api/registration-email-otp-send.php")
            .post(
                JSONObject()
                    .put("email", email.trim())
                    .toString()
                    .toRequestBody("application/json".toMediaType()),
            )
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                return EmailOtpResult(error = parseError(body) ?: "Could not send verification code.")
            }

            val json = parseJsonObject(body, "OTP send response")
            if (!json.optBoolean("ok")) {
                return EmailOtpResult(
                    error = json.optString("error", "Could not send verification code."),
                )
            }
            return EmailOtpResult(resendInSeconds = json.optInt("resend_in", 60))
        }
    }

    fun verifyRegistrationOtp(siteUrl: String, email: String, code: String): GoogleVerifyResult {
        val base = normalizeBase(siteUrl)
        val request = Request.Builder()
            .url("$base/api/registration-email-otp-verify.php")
            .post(
                JSONObject()
                    .put("email", email.trim())
                    .put("code", code.trim())
                    .toString()
                    .toRequestBody("application/json".toMediaType()),
            )
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                val serverError = parseError(body)
                return GoogleVerifyResult(
                    error = serverError ?: "Verification failed (HTTP ${response.code}).",
                )
            }

            val json = parseJsonObject(body, "OTP verify response")
            if (!json.optBoolean("ok")) {
                return GoogleVerifyResult(
                    error = json.optString("error", "Verification failed."),
                )
            }
            return GoogleVerifyResult(
                email = json.optString("email"),
                csrfToken = json.optString("csrf_token"),
            )
        }
    }

    fun loadOptions(siteUrl: String, formSlug: String): RegistrationOptionsJson {
        val base = normalizeBase(siteUrl)
        val request = Request.Builder()
            .url("$base/api/registration-options.php?form=${formSlug.trim()}")
            .get()
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (!response.isSuccessful) {
                throw IllegalStateException(
                    parseError(body) ?: "Could not load registration options.",
                )
            }
            return parseRegistrationOptions(body, formSlug)
        }
    }

    fun refreshCsrf(siteUrl: String, formSlug: String): String {
        val base = normalizeBase(siteUrl)
        val request = Request.Builder()
            .url("$base/?form=${formSlug.trim()}")
            .get()
            .build()

        client.newCall(request).execute().use { response ->
            val html = response.body?.string().orEmpty()
            return parseCsrfFromHtml(html)
                ?: throw IllegalStateException("Could not start registration session.")
        }
    }

    fun submit(siteUrl: String, parts: RegistrationMultipartPayload): RegistrationSubmitJson {
        val base = normalizeBase(siteUrl)
        val formBuilder = MultipartBody.Builder()
            .setType(MultipartBody.FORM)
            .addFormDataPart("csrf_token", parts.csrfToken)
            .addFormDataPart("form_slug", parts.formSlug)
            .addFormDataPart("staff_role", parts.staffRole)
            .addFormDataPart("registration_verified_google_email", parts.verifiedGoogleEmail)
            .addFormDataPart("surname", parts.surname)
            .addFormDataPart("first_name", parts.firstName)
            .addFormDataPart("full_address", parts.fullAddress)
            .addFormDataPart("eircode", parts.eircode)
            .addFormDataPart("email", parts.email)
            .addFormDataPart("mobile", parts.mobile)
            .addFormDataPart("date_of_birth", parts.dateOfBirth)
            .addFormDataPart("gender", parts.gender)
            .addFormDataPart("pps_number", parts.ppsNumber)
            .addFormDataPart("bank_iban", parts.bankIban)
            .addFormDataPart("psa_licence", parts.psaLicence)
            .addFormDataPart("psa_expiry_date", parts.psaExpiryDate)
            .addFormDataPart("privacy_consent", "1")

        parts.eventIds.forEach { eventId ->
            formBuilder.addFormDataPart("event_ids[]", eventId.toString())
        }

        parts.psaFrontImage?.let { upload ->
            formBuilder.addFormDataPart(
                "psa_front_image",
                upload.fileName,
                upload.bytes.toRequestBody(upload.mimeType.toMediaType()),
            )
        }
        parts.psaBackImage?.let { upload ->
            formBuilder.addFormDataPart(
                "psa_back_image",
                upload.fileName,
                upload.bytes.toRequestBody(upload.mimeType.toMediaType()),
            )
        }

        val request = Request.Builder()
            .url("$base/submit.php")
            .header("Accept", "application/json")
            .header("X-Requested-With", "XMLHttpRequest")
            .header("X-Olasentra-App", "android")
            .post(formBuilder.build())
            .build()

        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            if (body.isBlank()) {
                val message = if (response.code in 300..399) {
                    "Session expired — go back and open the form again."
                } else {
                    "Server returned no data (HTTP ${response.code})."
                }
                throw IllegalStateException("Registration failed: $message")
            }

            val json = parseJsonObject(body, "registration response")
            val errorsJson = json.optJSONObject("errors")
            val errors = buildList {
                if (errorsJson != null) {
                    val keys = errorsJson.keys()
                    while (keys.hasNext()) {
                        add(errorsJson.optString(keys.next()))
                    }
                }
            }
            val statusUrl = json.optString("status_url").takeIf { it.isNotBlank() }
            return RegistrationSubmitJson(
                success = json.optBoolean("success"),
                message = json.optString("message", json.optString("error", "Registration failed.")),
                count = json.optInt("count", 0),
                statusUrl = statusUrl,
                errors = errors,
                httpCode = response.code,
            )
        }
    }

    private fun parseRegistrationOptions(body: String, formSlug: String): RegistrationOptionsJson {
        val root = parseJsonObject(body, "registration options")
        if (root.has("error")) {
            throw IllegalStateException(root.optString("error", "Registration form unavailable."))
        }

        val form = root.optJSONObject("form") ?: JSONObject()
        val eventsByVenue = root.optJSONObject("eventsByVenue") ?: JSONObject()
        val genders = buildList {
            val array = root.optJSONArray("genders")
            if (array != null) {
                for (index in 0 until array.length()) {
                    val item = array.optJSONObject(index) ?: continue
                    val value = item.optString("value").trim()
                    if (value.isBlank()) continue
                    val label = item.optString("label", value.replace('_', ' '))
                        .ifBlank { value }
                    add(RegistrationGenderJson(value = value, label = label))
                }
            }
            if (isEmpty()) {
                addAll(defaultRegistrationGenders())
            }
        }

        val events = buildList {
            val venueKeys = eventsByVenue.keys()
            while (venueKeys.hasNext()) {
                val venueEvents = eventsByVenue.optJSONArray(venueKeys.next()) ?: continue
                for (index in 0 until venueEvents.length()) {
                    val item = venueEvents.optJSONObject(index) ?: continue
                    val eventId = item.optLong("id", item.optLong("event_id", 0L))
                    if (eventId < 1L) continue
                    add(
                        RegistrationEventJson(
                            eventId = eventId,
                            label = item.optString("name", item.optString("label", "Event")),
                            venueName = item.optString("venueName", item.optString("location", "—")),
                            eventDate = item.optString("date", item.optString("dateLabel", "—")),
                            timeLabel = item.optString("time", "—"),
                            isFull = item.optBoolean("isFull", false) || item.optBoolean("full", false),
                        ),
                    )
                }
            }
        }.sortedBy { it.eventDate }

        return RegistrationOptionsJson(
            formSlug = form.optString("slug", formSlug),
            staffRole = form.optString("staffRole", formSlug),
            events = events,
            genders = genders,
        )
    }

    private fun defaultRegistrationGenders(): List<RegistrationGenderJson> {
        return listOf(
            RegistrationGenderJson("male", "Male"),
            RegistrationGenderJson("female", "Female"),
            RegistrationGenderJson("other", "Other"),
            RegistrationGenderJson("prefer_not_to_say", "Prefer not to say"),
        )
    }

    private fun parseJsonObject(body: String, contextLabel: String): JSONObject {
        val trimmed = body.trim().removePrefix("\uFEFF")
        if (trimmed.isEmpty()) {
            throw IllegalStateException("Empty response from registration server.")
        }
        if (trimmed.first() != '{' && trimmed.first() != '[') {
            if (trimmed.startsWith("<!DOCTYPE", ignoreCase = true) ||
                trimmed.startsWith("<html", ignoreCase = true)
            ) {
                throw IllegalStateException("Registration server returned an unexpected page. Please try again.")
            }
            val start = trimmed.indexOf('{')
            if (start >= 0) {
                return parseJsonObject(trimmed.substring(start), contextLabel)
            }
            throw IllegalStateException("Could not read $contextLabel.")
        }
        return try {
            JSONObject(trimmed)
        } catch (_: Exception) {
            throw IllegalStateException("Could not read $contextLabel. Please try again.")
        }
    }

    private fun parseCsrfFromHtml(html: String): String? {
        val hiddenMatch = Regex("""name="csrf_token"\s+value="([^"]+)"""")
            .find(html)
            ?: Regex("""data-analytics-csrf="([^"]+)"""")
                .find(html)
        return hiddenMatch
            ?.groupValues
            ?.getOrNull(1)
            ?.trim()
            ?.takeIf { it.isNotBlank() }
    }

    private fun parseError(body: String): String? {
        return runCatching {
            JSONObject(body).optString("error").takeIf { it.isNotBlank() }
        }.getOrNull()
    }

    private fun normalizeBase(siteUrl: String): String {
        val trimmed = siteUrl.trim().trimEnd('/')
        return trimmed.ifBlank { DEFAULT_REGISTRATION_SITE_URL }
    }
}
