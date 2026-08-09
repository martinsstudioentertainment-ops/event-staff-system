package com.olasentra.staff.feature.documents.ui

import androidx.lifecycle.ViewModel
import androidx.lifecycle.viewModelScope
import com.olasentra.staff.core.util.DispatcherProvider
import com.olasentra.staff.domain.model.DocumentsOverview
import com.olasentra.staff.domain.model.StaffDocument
import com.olasentra.staff.domain.repository.DocumentsRepository
import dagger.hilt.android.lifecycle.HiltViewModel
import javax.inject.Inject
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

@HiltViewModel
class DocumentsViewModel @Inject constructor(
    private val documentsRepository: DocumentsRepository,
    private val dispatchers: DispatcherProvider,
) : ViewModel() {

    private val _uiState = MutableStateFlow(DocumentsUiState(isInitialLoading = true))
    val uiState: StateFlow<DocumentsUiState> = _uiState.asStateFlow()

    init {
        viewModelScope.launch(dispatchers.io) {
            documentsRepository.observeDocuments().collect { resource ->
                _uiState.update { current ->
                    current.copy(
                        overview = resource.data,
                        lastSyncedAtEpochMs = resource.lastSyncedAtEpochMs,
                        isRefreshing = resource.isRefreshing,
                        errorMessage = if (resource.data == null) resource.errorMessage else null,
                        isInitialLoading = resource.data == null && resource.isRefreshing,
                        showOfflineBanner = resource.isFromCache && resource.errorMessage != null,
                    )
                }
            }
        }
        refresh()
    }

    fun refresh() {
        viewModelScope.launch(dispatchers.io) {
            documentsRepository.refreshDocuments()
        }
    }

    fun openDocument(document: StaffDocument) {
        if (!document.hasFile) return
        viewModelScope.launch(dispatchers.io) {
            _uiState.update { it.copy(downloadingKey = document.key, actionMessage = null, actionError = null) }
            val result = documentsRepository.downloadDocumentFile(document.key)
            _uiState.update {
                it.copy(
                    downloadingKey = null,
                    downloadedFilePath = result.localFilePath,
                    downloadedMimeType = result.mimeType,
                    actionMessage = if (result.success) "Document ready to open" else null,
                    actionError = result.message,
                )
            }
        }
    }

    fun clearDownloadedFile() {
        _uiState.update {
            it.copy(
                downloadedFilePath = null,
                downloadedMimeType = null,
            )
        }
    }
}

data class DocumentsUiState(
    val overview: DocumentsOverview? = null,
    val lastSyncedAtEpochMs: Long? = null,
    val isRefreshing: Boolean = false,
    val isInitialLoading: Boolean = false,
    val errorMessage: String? = null,
    val showOfflineBanner: Boolean = false,
    val downloadingKey: String? = null,
    val downloadedFilePath: String? = null,
    val downloadedMimeType: String? = null,
    val actionMessage: String? = null,
    val actionError: String? = null,
)
