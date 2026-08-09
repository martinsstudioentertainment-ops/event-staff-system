package com.olasentra.staff.feature.messages.navigation

import androidx.navigation.NavGraphBuilder
import androidx.navigation.compose.composable
import com.olasentra.staff.core.navigation.Route
import com.olasentra.staff.feature.messages.ui.MessagesScreen

fun NavGraphBuilder.messagesGraph() {
    composable(Route.Messages.route) {
        MessagesScreen()
    }
}
