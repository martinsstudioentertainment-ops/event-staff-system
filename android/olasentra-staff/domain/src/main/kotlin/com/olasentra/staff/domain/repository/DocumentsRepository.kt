package com.olasentra.staff.domain.repository

import com.olasentra.staff.domain.model.CachedResource
import com.olasentra.staff.domain.model.DocumentFileResult
import com.olasentra.staff.domain.model.DocumentsOverview
import kotlinx.coroutines.flow.Flow

interface DocumentsRepository {
    fun observeDocuments(): Flow<CachedResource<DocumentsOverview>>

    suspend fun refreshDocuments()

    suspend fun downloadDocumentFile(key: String): DocumentFileResult
}
