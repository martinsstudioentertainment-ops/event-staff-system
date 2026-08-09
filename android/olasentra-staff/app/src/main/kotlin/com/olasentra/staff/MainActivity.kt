package com.olasentra.staff

import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.core.splashscreen.SplashScreen.Companion.installSplashScreen
import com.olasentra.staff.core.ui.theme.OlasentraTheme
import com.olasentra.staff.navigation.OlasentraNavHost
import dagger.hilt.android.AndroidEntryPoint

@AndroidEntryPoint
class MainActivity : ComponentActivity() {

    private var pendingDeepLinkRoute: String? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        installSplashScreen()
        super.onCreate(savedInstanceState)
        pendingDeepLinkRoute = intent?.getStringExtra(EXTRA_DEEP_LINK_ROUTE)
        enableEdgeToEdge()
        setContent {
            OlasentraTheme {
                OlasentraNavHost(initialDeepLinkRoute = consumeInitialDeepLinkRoute())
            }
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        pendingDeepLinkRoute = intent.getStringExtra(EXTRA_DEEP_LINK_ROUTE)
    }

    fun consumeInitialDeepLinkRoute(): String? {
        val route = pendingDeepLinkRoute
        pendingDeepLinkRoute = null
        return route
    }

    companion object {
        const val EXTRA_DEEP_LINK_ROUTE = "deep_link_route"
    }
}
