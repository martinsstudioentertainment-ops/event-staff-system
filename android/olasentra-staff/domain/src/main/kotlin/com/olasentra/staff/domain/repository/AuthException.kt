package com.olasentra.staff.domain.repository

class AuthException(
    override val message: String,
    val httpCode: Int? = null,
    val errorCode: String? = null,
) : Exception(message)
