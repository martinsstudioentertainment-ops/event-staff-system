package com.olasentra.staff.domain.model

data class RegistrationSession(
    val email: String,
    val csrfToken: String,
)

data class RegistrationEventOption(
    val eventId: Long,
    val label: String,
    val venueName: String,
    val eventDate: String,
    val timeLabel: String,
    val isFull: Boolean,
)

data class RegistrationOptions(
    val formSlug: String,
    val staffRole: String,
    val events: List<RegistrationEventOption>,
)

data class RegistrationSubmitPayload(
    val formSlug: String,
    val staffRole: String,
    val csrfToken: String,
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
    val privacyConsent: Boolean,
    val psaFrontImage: RegistrationUploadFile?,
    val psaBackImage: RegistrationUploadFile?,
)

data class RegistrationUploadFile(
    val fileName: String,
    val mimeType: String,
    val bytes: ByteArray,
) {
    override fun equals(other: Any?): Boolean {
        if (this === other) return true
        if (other !is RegistrationUploadFile) return false
        return fileName == other.fileName && mimeType == other.mimeType && bytes.contentEquals(other.bytes)
    }

    override fun hashCode(): Int {
        var result = fileName.hashCode()
        result = 31 * result + mimeType.hashCode()
        result = 31 * result + bytes.contentHashCode()
        return result
    }
}

data class RegistrationSubmitResult(
    val success: Boolean,
    val message: String,
    val registrationCount: Int,
)
