package com.olasentra.staff.core.network.dto



import com.squareup.moshi.JsonClass



@JsonClass(generateAdapter = true)

data class ApiErrorResponse(

    val ok: Boolean? = null,

    val error: String? = null,

    val code: String? = null,

)