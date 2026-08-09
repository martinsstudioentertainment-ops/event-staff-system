package com.olasentra.staff.core.network

import com.olasentra.staff.core.network.dto.ApiErrorResponse
import com.olasentra.staff.core.util.ApiResult
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
            val parsedError = parseHttpError(exception)
            ApiResult.Error(
                message = parsedError.message,
                throwable = exception,
                code = exception.code(),
                errorCode = parsedError.errorCode,
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

    private data class ParsedHttpError(
        val message: String,
        val errorCode: String? = null,
    )

    private fun parseHttpError(exception: HttpException): ParsedHttpError {
        val errorBody = exception.response()?.errorBody()?.string()
        if (errorBody.isNullOrBlank()) {
            return ParsedHttpError(message = exception.message())
        }

        val parsed = runCatching {
            moshi.adapter(ApiErrorResponse::class.java).fromJson(errorBody)
        }.getOrNull()

        val message = parsed?.error?.takeIf { it.isNotBlank() } ?: exception.message()
        return ParsedHttpError(
            message = message,
            errorCode = parsed?.code?.takeIf { it.isNotBlank() },
        )
    }
}
