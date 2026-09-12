package org.manaclinic.app

import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.CalendarMonth
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Phone
import androidx.compose.material3.Icon
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.navigation.NavGraph.Companion.findStartDestination
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import org.manaclinic.app.ui.RelativeScaffold
import org.manaclinic.app.ui.screens.BookScreen
import org.manaclinic.app.ui.screens.ContactScreen
import org.manaclinic.app.ui.screens.HomeScreen
import org.manaclinic.app.ui.theme.ManaClinicTheme

class MainActivity : ComponentActivity() {
    override fun attachBaseContext(newBase: android.content.Context) {
        val config = android.content.res.Configuration(newBase.resources.configuration)
        config.setLocale(java.util.Locale.forLanguageTag("fa"))
        super.attachBaseContext(newBase.createConfigurationContext(config))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            ManaClinicTheme {
                ManaClinicRoot()
            }
        }
    }
}

private sealed class Dest(
    val route: String,
    val label: String,
    val icon: ImageVector,
) {
    data object Home : Dest("home", "خانه", Icons.Filled.Home)
    data object Book : Dest("book", "نوبت", Icons.Filled.CalendarMonth)
    data object Contact : Dest("contact", "تماس", Icons.Filled.Phone)
}

private val destinations = listOf(Dest.Home, Dest.Book, Dest.Contact)

@Composable
fun ManaClinicRoot() {
    val navController = rememberNavController()
    val backStack by navController.currentBackStackEntryAsState()
    val currentRoute = backStack?.destination?.route

    RelativeScaffold(modifier = Modifier.fillMaxSize()) { metrics ->
        Scaffold(
            modifier = Modifier.fillMaxSize(),
            bottomBar = {
                NavigationBar {
                    destinations.forEach { dest ->
                        NavigationBarItem(
                            selected = currentRoute == dest.route,
                            onClick = {
                                navController.navigate(dest.route) {
                                    popUpTo(navController.graph.findStartDestination().id) {
                                        saveState = true
                                    }
                                    launchSingleTop = true
                                    restoreState = true
                                }
                            },
                            icon = { Icon(dest.icon, contentDescription = dest.label) },
                            label = {
                                Text(dest.label, fontSize = metrics.bodySize * 0.85f)
                            },
                        )
                    }
                }
            },
        ) { innerPadding ->
            NavHost(
                navController = navController,
                startDestination = Dest.Home.route,
                modifier = Modifier
                    .fillMaxSize()
                    .padding(innerPadding),
            ) {
                composable(Dest.Home.route) {
                    HomeScreen(
                        metrics = metrics,
                        onBookClick = {
                            navController.navigate(Dest.Book.route) {
                                launchSingleTop = true
                            }
                        },
                    )
                }
                composable(Dest.Book.route) {
                    BookScreen(metrics = metrics)
                }
                composable(Dest.Contact.route) {
                    ContactScreen(metrics = metrics)
                }
            }
        }
    }
}

private operator fun androidx.compose.ui.unit.TextUnit.times(factor: Float) =
    androidx.compose.ui.unit.TextUnit(value * factor, type)
