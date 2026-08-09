package com.olasentra.staff.data.remote

sealed interface ApiResult<out T> {
    data class Success<T>(val data: T) : ApiResult<T>

    data class Error(
        val message: String,
        val httpCode: Int? = null,
        val errorCode: String? = null,
        val throwable: Throwable? = null,
    ) : ApiResult<Nothing>
}
