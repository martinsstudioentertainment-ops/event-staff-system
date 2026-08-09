package com.olasentra.staff.feature.notifications.navigation



import androidx.navigation.NavGraphBuilder

import androidx.navigation.compose.composable

import com.olasentra.staff.core.navigation.Route

import com.olasentra.staff.feature.notifications.ui.NotificationsScreen



fun NavGraphBuilder.notificationsGraph(

    onOpenRoute: (String) -> Unit = {},

    onOpenShiftDetail: (Long) -> Unit = {},

) {

    composable(Route.Notifications.route) {

        NotificationsScreen(

            onOpenRoute = onOpenRoute,

            onOpenShiftDetail = onOpenShiftDetail,

        )

    }

}