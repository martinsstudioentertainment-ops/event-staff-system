package com.olasentra.staff.feature.documents.ui

import android.content.Intent
import android.graphics.BitmapFactory
import android.graphics.ImageDecoder
import android.os.Build
import androidx.compose.foundation.Image
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.core.content.FileProvider
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.LastSyncBanner
import com.olasentra.staff.domain.model.StaffDocument
import java.io.File

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DocumentsScreen(
    modifier: Modifier = Modifier,
    viewModel: DocumentsViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val context = LocalContext.current
    var previewPath by remember { mutableStateOf<String?>(null) }
    var previewMime by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(uiState.downloadedFilePath, uiState.downloadedMimeType) {
        val path = uiState.downloadedFilePath ?: return@LaunchedEffect
        val mime = uiState.downloadedMimeType ?: "application/octet-stream"
        val file = File(path)
        if (!file.exists()) return@LaunchedEffect

        if (mime.startsWith("image/")) {
            previewPath = path
            previewMime = mime
            viewModel.clearDownloadedFile()
            return@LaunchedEffect
        }

        val uri = FileProvider.getUriForFile(
            context,
            "${context.packageName}.fileprovider",
            file,
        )
        val intent = Intent(Intent.ACTION_VIEW).apply {
            setDataAndType(uri, mime)
            addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
        }
        context.startActivity(Intent.createChooser(intent, "Open document"))
        viewModel.clearDownloadedFile()
    }

    previewPath?.let { path ->
        DocumentPreviewDialog(
            filePath = path,
            mimeType = previewMime.orEmpty(),
            onDismiss = {
                previewPath = null
                previewMime = null
            },
        )
    }

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Text(
                    text = "Documents",
                    modifier = Modifier.padding(horizontal = 16.dp, vertical = 16.dp),
                    style = MaterialTheme.typography.headlineSmall,
                    fontWeight = FontWeight.SemiBold,
                )
                LastSyncBanner(
                    lastSyncedAtEpochMs = uiState.lastSyncedAtEpochMs,
                    isOfflineData = uiState.showOfflineBanner,
                )
            }
        },
    ) { innerPadding ->
        PullToRefreshBox(
            isRefreshing = uiState.isRefreshing,
            onRefresh = viewModel::refresh,
            modifier = Modifier
                .fillMaxSize()
                .padding(innerPadding),
        ) {
            when {
                uiState.isInitialLoading -> LoadingState()
                uiState.overview == null && uiState.errorMessage != null -> ErrorState(uiState.errorMessage.orEmpty())
                else -> {
                    val overview = uiState.overview
                    LazyColumn(
                        modifier = Modifier.fillMaxSize(),
                        contentPadding = PaddingValues(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        overview?.summary?.let { summary ->
                            item {
                                SummaryCard(
                                    total = summary.total,
                                    valid = summary.valid,
                                    expiring = summary.expiring,
                                    expired = summary.expired,
                                    missing = summary.missing,
                                )
                            }
                        }

                        val documents = overview?.documents.orEmpty()
                        if (documents.isEmpty()) {
                            item {
                                Text(
                                    text = "No documents on file",
                                    style = MaterialTheme.typography.bodyMedium,
                                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                                )
                            }
                        } else {
                            items(documents, key = { it.key }) { document ->
                                DocumentCard(
                                    document = document,
                                    isDownloading = uiState.downloadingKey == document.key,
                                    onOpen = { viewModel.openDocument(document) },
                                )
                            }
                        }

                        uiState.actionError?.let { error ->
                            item {
                                Text(text = error, color = MaterialTheme.colorScheme.error)
                            }
                        }
                    }
                }
            }
        }
    }
}

private fun loadDocumentPreviewBitmap(filePath: String): androidx.compose.ui.graphics.ImageBitmap? {
    return runCatching {
        val file = File(filePath)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            ImageDecoder.decodeBitmap(ImageDecoder.createSource(file)).asImageBitmap()
        } else {
            BitmapFactory.decodeFile(filePath)?.asImageBitmap()
        }
    }.getOrNull()
}

@Composable
private fun DocumentPreviewDialog(
    filePath: String,
    mimeType: String,
    onDismiss: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val bitmap = remember(filePath) {
        loadDocumentPreviewBitmap(filePath)
    }

    Dialog(onDismissRequest = onDismiss) {
        Card(modifier = modifier.fillMaxWidth()) {
            Column(
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(16.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text(text = "Document preview", style = MaterialTheme.typography.titleMedium)
                if (bitmap != null) {
                    Image(
                        bitmap = bitmap,
                        contentDescription = "Document preview",
                        modifier = Modifier
                            .fillMaxWidth()
                            .height(360.dp)
                            .verticalScroll(rememberScrollState()),
                        contentScale = ContentScale.Fit,
                    )
                } else {
                    Text(
                        text = "Could not render this ${mimeType.ifBlank { "file" }}.",
                        color = MaterialTheme.colorScheme.error,
                    )
                }
                TextButton(onClick = onDismiss, modifier = Modifier.align(Alignment.End)) {
                    Text(text = "Close")
                }
            }
        }
    }
}

@Composable
private fun SummaryCard(
    total: Int,
    valid: Int,
    expiring: Int,
    expired: Int,
    missing: Int,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp)) {
            Text(text = "Compliance summary", style = MaterialTheme.typography.titleMedium)
            Spacer(modifier = Modifier.height(8.dp))
            Text(text = "$total documents · $valid valid · $expiring expiring · $expired expired · $missing missing")
        }
    }
}

@Composable
private fun DocumentCard(
    document: StaffDocument,
    isDownloading: Boolean,
    onOpen: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Card(modifier = modifier.fillMaxWidth()) {
        Column(modifier = Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
            Text(text = document.label, style = MaterialTheme.typography.titleMedium, fontWeight = FontWeight.SemiBold)
            Text(text = document.category.replaceFirstChar { it.uppercase() }, style = MaterialTheme.typography.labelMedium, color = MaterialTheme.colorScheme.secondary)
            document.licenceNumber?.let {
                Text(text = "Licence: $it", style = MaterialTheme.typography.bodyMedium)
            }
            document.expiry?.takeIf { it.isNotBlank() }?.let {
                Text(text = "Expiry: $it", style = MaterialTheme.typography.bodyMedium)
            }
            Text(text = "Status: ${document.status.replace('_', ' ')}", style = MaterialTheme.typography.bodyMedium)
            Text(text = "Approval: ${document.approvalStatus.replace('_', ' ')}", style = MaterialTheme.typography.bodyMedium)
            if (document.hasFile) {
                OutlinedButton(onClick = onOpen, enabled = !isDownloading, modifier = Modifier.fillMaxWidth()) {
                    if (isDownloading) {
                        CircularProgressIndicator(strokeWidth = 2.dp, modifier = Modifier.height(18.dp))
                    } else {
                        Text(text = "Open file")
                    }
                }
            }
        }
    }
}

@Composable
private fun LoadingState(modifier: Modifier = Modifier) {
    Column(modifier = modifier.fillMaxSize(), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        CircularProgressIndicator()
    }
}

@Composable
private fun ErrorState(message: String, modifier: Modifier = Modifier) {
    Column(modifier = modifier.fillMaxSize().padding(24.dp), verticalArrangement = Arrangement.Center) {
        Text(text = message, color = MaterialTheme.colorScheme.error)
    }
}

