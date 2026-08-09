package com.olasentra.staff.data.remote

import com.olasentra.staff.data.remote.dto.ApiErrorResponse
import com.squareup.moshi.Moshi
import java.io.IOException
import javax.inject.Inject
import javax.inject.Singleton
import retrofit2.HttpException

@Singleton
class ApiCallHandler @Inject constructor(
    private val moshi: Moshi,
) {
    suspend fun <T> safeApiCall(block: suspend () -> T): ApiResult<T> {
        return try {
            ApiResult.Success(block())
        } catch (exception: HttpException) {
            val parsed = parseHttpError(exception)
            ApiResult.Error(
                message = parsed.message,
                httpCode = exception.code(),
                errorCode = parsed.code,
                throwable = exception,
            )
        } catch (exception: IOException) {
            ApiResult.Error(
                message = exception.message ?: "Network error",
                throwable = exception,
            )
        } catch (exception: Exception) {
            ApiResult.Error(
                message = exception.message ?: "Unknown error",
                throwable = exception,
            )
        }
    }

    private fun parseHttpError(exception: HttpException): ParsedHttpError {
        val errorBody = exception.response()?.errorBody()?.string()
        if (errorBody.isNullOrBlank()) {
            return ParsedHttpError(message = exception.message())
        }

        return runCatching {
            moshi.adapter(ApiErrorResponse::class.java).fromJson(errorBody)
        }.getOrNull()?.let { response ->
            ParsedHttpError(
                message = response.error?.takeIf { it.isNotBlank() }
                    ?: response.message?.takeIf { it.isNotBlank() }
                    ?: exception.message(),
                code = response.code,
            )
        } ?: ParsedHttpError(message = exception.message())
    }

    private data class ParsedHttpError(
        val message: String,
        val code: String? = null,
    )
}
