package com.olasentra.staff.core.util

sealed interface ApiResult<out T> {
    data class Success<T>(val data: T) : ApiResult<T>

    data class Error(
        val message: String,
        val throwable: Throwable? = null,
        val code: Int? = null,
        val errorCode: String? = null,
    ) : ApiResult<Nothing>

    data object Loading : ApiResult<Nothing>
}

inline fun <T> ApiResult<T>.onSuccess(action: (T) -> Unit): ApiResult<T> {
    if (this is ApiResult.Success) {
        action(data)
    }
    return this
}

inline fun <T> ApiResult<T>.onError(action: (ApiResult.Error) -> Unit): ApiResult<T> {
    if (this is ApiResult.Error) {
        action(this)
    }
    return this
}

fun <T> ApiResult<T>.getOrNull(): T? = (this as? ApiResult.Success)?.data

fun <T> ApiResult<T>.isLoading(): Boolean = this is ApiResult.Loading

fun <T> ApiResult<T>.isSuccess(): Boolean = this is ApiResult.Success

fun <T> ApiResult<T>.isError(): Boolean = this is ApiResult.Error
