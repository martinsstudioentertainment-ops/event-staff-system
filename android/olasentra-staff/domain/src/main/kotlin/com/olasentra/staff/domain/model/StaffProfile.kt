package com.olasentra.staff.domain.model



data class StaffProfile(

    val id: Long,

    val displayName: String,

    val email: String,

    val phone: String,

    val address: String,

    val eircode: String,

    val staffRole: String,

    val approvalLabel: String,

    val approvalDetail: String,

    val documentItems: List<ProfileDocumentItem>,

    val profileComplete: Boolean,

    val canEditLimitedFields: Boolean,

    val mustUseWebProfile: Boolean,

)



data class ProfileDocumentItem(

    val label: String,

    val status: String,

    val expiry: String?,

    val approvalStatus: String?,

    val hasFile: Boolean,

)