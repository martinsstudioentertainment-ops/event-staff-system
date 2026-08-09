package com.olasentra.staff.feature.auth.registration

data class RegistrationFormOption(
    val formSlug: String,
    val title: String,
    val description: String,
)

object RegistrationForms {
    const val DEFAULT_REGISTRATION_SITE_URL = "https://register.olasentra.com"

    val options: List<RegistrationFormOption> = listOf(
        RegistrationFormOption(
            formSlug = "dsp",
            title = "Door Supervisor",
            description = "PSA door supervisor — events, clubs, festivals",
        ),
        RegistrationFormOption(
            formSlug = "static",
            title = "Static Security",
            description = "PSA static and site security at events and venues",
        ),
        RegistrationFormOption(
            formSlug = "both",
            title = "DSP & Static",
            description = "For staff who do both door supervisor and static work",
        ),
        RegistrationFormOption(
            formSlug = "fire_marshal",
            title = "Fire Marshal",
            description = "Fire safety and evacuation at events and festivals",
        ),
    )

    fun registrationUrl(baseSiteUrl: String, formSlug: String): String {
        val base = baseSiteUrl.trim().trimEnd('/').ifBlank { DEFAULT_REGISTRATION_SITE_URL }
        return "$base/?form=$formSlug"
    }
}
