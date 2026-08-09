package com.olasentra.staff.core.ui.components

import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.LocationOn
import androidx.compose.material.icons.filled.Message
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Work
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector

data class OlasentraBottomNavItem(
    val route: String,
    val label: String,
    val icon: ImageVector,
)

val OlasentraBottomNavItems: List<OlasentraBottomNavItem> = listOf(
    OlasentraBottomNavItem(
        route = "dashboard",
        label = "Home",
        icon = Icons.Default.Home,
    ),
    OlasentraBottomNavItem(
        route = "shifts",
        label = "Shifts",
        icon = Icons.Default.Work,
    ),
    OlasentraBottomNavItem(
        route = "check_in",
        label = "Check-In",
        icon = Icons.Default.LocationOn,
    ),
    OlasentraBottomNavItem(
        route = "messages",
        label = "Messages",
        icon = Icons.Default.Message,
    ),
    OlasentraBottomNavItem(
        route = "profile",
        label = "Profile",
        icon = Icons.Default.Person,
    ),
)

@Composable
fun OlasentraBottomBar(
    currentRoute: String?,
    onNavigate: (String) -> Unit,
    modifier: Modifier = Modifier,
    items: List<OlasentraBottomNavItem> = OlasentraBottomNavItems,
) {
    NavigationBar(
        modifier = modifier,
        containerColor = MaterialTheme.colorScheme.surface,
        contentColor = MaterialTheme.colorScheme.onSurface,
    ) {
        items.forEach { item ->
            val selected = currentRoute == item.route
            NavigationBarItem(
                selected = selected,
                onClick = { onNavigate(item.route) },
                icon = {
                    Icon(
                        imageVector = item.icon,
                        contentDescription = item.label,
                    )
                },
                label = {
                    Text(text = item.label)
                },
                colors = NavigationBarItemDefaults.colors(
                    selectedIconColor = MaterialTheme.colorScheme.secondary,
                    selectedTextColor = MaterialTheme.colorScheme.secondary,
                    indicatorColor = MaterialTheme.colorScheme.secondaryContainer,
                    unselectedIconColor = MaterialTheme.colorScheme.onSurfaceVariant,
                    unselectedTextColor = MaterialTheme.colorScheme.onSurfaceVariant,
                ),
            )
        }
    }
}
