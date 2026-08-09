package com.olasentra.staff.feature.documents.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.documents.ui.DocumentsScreen

fun NavGraphBuilder.documentsGraph() {
    composable(Route.Documents.route) {
        DocumentsScreen()
    }
}
