package com.olasentra.staff.feature.availability.ui

import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.aspectRatio
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowLeft
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material3.AssistChip
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.pulltorefresh.PullToRefreshBox
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.dp
import androidx.hilt.navigation.compose.hiltViewModel
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.olasentra.staff.core.ui.components.LastSyncBanner
import com.olasentra.staff.domain.model.AvailabilityDay
import java.time.LocalDate
import java.time.YearMonth
import java.time.format.TextStyle
import java.util.Locale

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AvailabilityScreen(
    modifier: Modifier = Modifier,
    viewModel: AvailabilityViewModel = hiltViewModel(),
) {
    val uiState by viewModel.uiState.collectAsStateWithLifecycle()
    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = true)

    Scaffold(
        modifier = modifier,
        topBar = {
            Column(modifier = Modifier.fillMaxWidth()) {
                Text(
                    text = "Availability",
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
                    Column(
                        modifier = Modifier
                            .fillMaxSize()
                            .padding(16.dp),
                        verticalArrangement = Arrangement.spacedBy(12.dp),
                    ) {
                        MonthHeader(
                            month = uiState.currentMonth,
                            onPrevious = viewModel::previousMonth,
                            onNext = viewModel::nextMonth,
                        )
                        StatusLegend()
                        AvailabilityCalendar(
                            month = uiState.currentMonth,
                            days = uiState.overview?.days.orEmpty(),
                            onDayClick = viewModel::selectDate,
                        )
                        uiState.actionMessage?.let {
                            Text(text = it, color = MaterialTheme.colorScheme.primary)
                        }
                        uiState.actionError?.let {
                            Text(text = it, color = MaterialTheme.colorScheme.error)
                        }
                    }
                }
            }
        }
    }

    val selectedDate = uiState.selectedDate
    if (selectedDate != null) {
        val day = viewModel.dayForDate(selectedDate)
        val isPast = viewModel.isPastDate(selectedDate)
        ModalBottomSheet(
            onDismissRequest = viewModel::dismissEditor,
            sheetState = sheetState,
        ) {
            DayEditorSheet(
                date = selectedDate,
                day = day,
                isPast = isPast,
                isSaving = uiState.isSaving,
                onSetStatus = viewModel::setStatus,
                onSubmitLeave = viewModel::submitLeave,
            )
        }
    }
}

@Composable
private fun MonthHeader(
    month: YearMonth,
    onPrevious: () -> Unit,
    onNext: () -> Unit,
    modifier: Modifier = Modifier,
) {
    Row(
        modifier = modifier.fillMaxWidth(),
        horizontalArrangement = Arrangement.SpaceBetween,
        verticalAlignment = Alignment.CenterVertically,
    ) {
        IconButton(onClick = onPrevious) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowLeft, contentDescription = "Previous month")
        }
        Text(
            text = "${month.month.getDisplayName(TextStyle.FULL, Locale.getDefault())} ${month.year}",
            style = MaterialTheme.typography.titleMedium,
            fontWeight = FontWeight.SemiBold,
        )
        IconButton(onClick = onNext) {
            Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, contentDescription = "Next month")
        }
    }
}

@Composable
private fun StatusLegend(modifier: Modifier = Modifier) {
    Row(modifier = modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        LegendChip("Available", statusColor("available"))
        LegendChip("Unavailable", statusColor("unavailable"))
        LegendChip("Preferred", statusColor("preferred"))
        LegendChip("Leave", statusColor("leave"))
    }
}

@Composable
private fun LegendChip(label: String, color: Color) {
    AssistChip(onClick = {}, label = { Text(label, style = MaterialTheme.typography.labelSmall) }, enabled = false, modifier = Modifier.background(color.copy(alpha = 0.15f)))
}

@Composable
private fun AvailabilityCalendar(
    month: YearMonth,
    days: List<AvailabilityDay>,
    onDayClick: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    val dayMap = days.associateBy { it.date }
    val firstDay = month.atDay(1)
    val leadingEmpty = firstDay.dayOfWeek.value - 1
    val cells = buildList {
        repeat(leadingEmpty) { add(null) }
        for (day in 1..month.lengthOfMonth()) {
            add(month.atDay(day).toString())
        }
    }

    Column(modifier = modifier) {
        Row(modifier = Modifier.fillMaxWidth()) {
            listOf("Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun").forEach { label ->
                Text(
                    text = label,
                    modifier = Modifier.weight(1f),
                    textAlign = TextAlign.Center,
                    style = MaterialTheme.typography.labelSmall,
                    color = MaterialTheme.colorScheme.onSurfaceVariant,
                )
            }
        }
        Spacer(modifier = Modifier.height(8.dp))
        LazyVerticalGrid(
            columns = GridCells.Fixed(7),
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.spacedBy(4.dp),
            verticalArrangement = Arrangement.spacedBy(4.dp),
        ) {
            items(cells) { date ->
                if (date == null) {
                    Box(modifier = Modifier.aspectRatio(1f))
                } else {
                    val day = dayMap[date]
                    CalendarDayCell(
                        date = date,
                        day = day,
                        onClick = { onDayClick(date) },
                    )
                }
            }
        }
    }
}

@Composable
private fun CalendarDayCell(
    date: String,
    day: AvailabilityDay?,
    onClick: () -> Unit,
    modifier: Modifier = Modifier,
) {
    val dayNumber = LocalDate.parse(date).dayOfMonth.toString()
    val status = day?.status ?: "available"
    val color = statusColor(status)
    Box(
        modifier = modifier
            .aspectRatio(1f)
            .background(color.copy(alpha = 0.25f), MaterialTheme.shapes.small)
            .clickable(onClick = onClick)
            .padding(4.dp),
        contentAlignment = Alignment.TopCenter,
    ) {
        Column(horizontalAlignment = Alignment.CenterHorizontally) {
            Text(text = dayNumber, style = MaterialTheme.typography.labelLarge, fontWeight = FontWeight.Medium)
            if (day?.approvalStatus == "pending") {
                Text(text = "Pending", style = MaterialTheme.typography.labelSmall, color = MaterialTheme.colorScheme.secondary)
            }
        }
    }
}

@Composable
private fun DayEditorSheet(
    date: String,
    day: AvailabilityDay?,
    isPast: Boolean,
    isSaving: Boolean,
    onSetStatus: (String) -> Unit,
    onSubmitLeave: (String) -> Unit,
    modifier: Modifier = Modifier,
) {
    Column(
        modifier = modifier
            .fillMaxWidth()
            .padding(horizontal = 16.dp, vertical = 24.dp),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text(text = date, style = MaterialTheme.typography.titleLarge, fontWeight = FontWeight.SemiBold)
        day?.let {
            Text(text = "Current: ${it.status.replaceFirstChar { c -> c.uppercase() }} · ${it.approvalStatus}")
            if (it.notes.isNotBlank()) {
                Text(text = it.notes, style = MaterialTheme.typography.bodyMedium)
            }
        }
        if (isPast) {
            Text(text = "Past dates cannot be changed.", color = MaterialTheme.colorScheme.error)
        } else {
            Text(text = "Set availability", style = MaterialTheme.typography.titleMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { onSetStatus("available") }, enabled = !isSaving) { Text("Available") }
                OutlinedButton(onClick = { onSetStatus("unavailable") }, enabled = !isSaving) { Text("Unavailable") }
                OutlinedButton(onClick = { onSetStatus("preferred") }, enabled = !isSaving) { Text("Preferred") }
            }
            Text(text = "Request leave", style = MaterialTheme.typography.titleMedium)
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedButton(onClick = { onSubmitLeave("leave") }, enabled = !isSaving) { Text("Leave") }
                OutlinedButton(onClick = { onSubmitLeave("holiday") }, enabled = !isSaving) { Text("Holiday") }
            }
        }
        if (isSaving) {
            CircularProgressIndicator(modifier = Modifier.align(Alignment.CenterHorizontally))
        }
    }
}

@Composable
private fun statusColor(status: String): Color {
    return when (status.lowercase()) {
        "unavailable" -> Color(0xFFEF4444)
        "preferred" -> Color(0xFF2563EB)
        "leave", "holiday" -> Color(0xFFF59E0B)
        else -> Color(0xFF22C55E)
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
