package com.olasentra.staff.data.repository

import android.content.Context
import com.olasentra.staff.core.database.ApiCacheKeys
import com.olasentra.staff.core.database.dao.ApiCacheDao
import com.olasentra.staff.core.database.entity.ApiCacheEntity
import com.olasentra.staff.core.network.ApiCallHandler
import com.olasentra.staff.core.network.MobileApiService
import com.olasentra.staff.core.network.dto.DocumentsListResponse
import com.olasentra.staff.core.util.ApiResult
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.data.remote.mapper.DocumentsMapper
import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DocumentFileResult
import com.olasentra.staff.domain.model.DocumentsOverview
import com.olasentra.staff.domain.repository.DocumentsRepository
import com.squareup.moshi.Moshi
import dagger.hilt.android.qualifiers.ApplicationContext
import java.io.File
import javax.inject.Inject
import javax.inject.Singleton
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.combine
import kotlinx.coroutines.withContext
import okhttp3.ResponseBody

@Singleton
class DocumentsRepositoryImpl @Inject constructor(
    @ApplicationContext private val context: Context,
    private val api: MobileApiService,
    private val apiCacheDao: ApiCacheDao,
    private val apiCallHandler: ApiCallHandler,
    private val documentsMapper: DocumentsMapper,
    private val moshi: Moshi,
    private val dispatchers: DispatcherProvider,
) : DocumentsRepository {

    private val refreshState = MutableStateFlow(false)
    private val errorState = MutableStateFlow<String?>(null)

    override fun observeDocuments(): Flow<CachedResource<DocumentsOverview>> {
        return combine(
            apiCacheDao.observe(ApiCacheKeys.DOCUMENTS),
            refreshState,
            errorState,
        ) { cacheEntity, isRefreshing, errorMessage ->
            val overview = cacheEntity?.payloadJson?.let(::decodeDocuments)
            CachedResource(
                data = overview,
                lastSyncedAtEpochMs = cacheEntity?.syncedAtEpochMs,
                isRefreshing = isRefreshing,
                errorMessage = errorMessage,
                isFromCache = cacheEntity != null,
            )
        }
    }

    override suspend fun refreshDocuments() {
        refreshState.value = true
        errorState.value = null

        when (val result = apiCallHandler.safeApiCall { api.getDocuments() }) {
            is ApiResult.Success -> {
                val response = result.data
                if (response.ok != true) {
                    errorState.value = "Documents unavailable"
                } else {
                    persistDocuments(response)
                    errorState.value = null
                }
            }
            is ApiResult.Error -> errorState.value = result.message
            ApiResult.Loading -> Unit
        }

        refreshState.value = false
    }

    override suspend fun downloadDocumentFile(key: String): DocumentFileResult {
        return withContext(dispatchers.io) {
            when (val result = apiCallHandler.safeApiCall { api.getDocumentFile(key) }) {
                is ApiResult.Success -> saveDocumentFile(key, result.data)
                is ApiResult.Error -> DocumentFileResult(
                    success = false,
                    message = result.message,
                )
                ApiResult.Loading -> DocumentFileResult(success = false, message = "Unexpected state")
            }
        }
    }

    private suspend fun persistDocuments(response: DocumentsListResponse) {
        withContext(dispatchers.io) {
            apiCacheDao.upsert(
                ApiCacheEntity(
                    cacheKey = ApiCacheKeys.DOCUMENTS,
                    payloadJson = moshi.adapter(DocumentsListResponse::class.java).toJson(response),
                    syncedAtEpochMs = System.currentTimeMillis(),
                ),
            )
        }
    }

    private fun decodeDocuments(payloadJson: String): DocumentsOverview? {
        return runCatching {
            val response = moshi.adapter(DocumentsListResponse::class.java).fromJson(payloadJson)
                ?: return null
            documentsMapper.toDocumentsOverview(response)
        }.getOrNull()
    }

    private fun saveDocumentFile(key: String, body: ResponseBody): DocumentFileResult {
        return runCatching {
            val directory = File(context.cacheDir, "documents").apply { mkdirs() }
            val extension = when {
                body.contentType()?.subtype?.contains("jpeg") == true -> "jpg"
                body.contentType()?.subtype?.contains("png") == true -> "png"
                body.contentType()?.subtype?.contains("pdf") == true -> "pdf"
                else -> "bin"
            }
            val target = File(directory, "$key.$extension")
            body.byteStream().use { input ->
                target.outputStream().use { output -> input.copyTo(output) }
            }
            DocumentFileResult(
                success = true,
                localFilePath = target.absolutePath,
                mimeType = body.contentType()?.toString(),
            )
        }.getOrElse { error ->
            DocumentFileResult(
                success = false,
                message = error.message ?: "Could not save document",
            )
        }
    }
}
