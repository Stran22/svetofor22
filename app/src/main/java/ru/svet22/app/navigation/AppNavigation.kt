package ru.svet22.app.navigation

import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Apps
import androidx.compose.material.icons.filled.ArrowBack
import androidx.compose.material.icons.filled.BookmarkBorder
import androidx.compose.material.icons.filled.Home
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.Visibility
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.NavigationBarItemDefaults
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.lifecycle.viewmodel.compose.viewModel
import androidx.navigation.compose.NavHost
import androidx.navigation.compose.composable
import androidx.navigation.compose.currentBackStackEntryAsState
import androidx.navigation.compose.rememberNavController
import ru.svet22.app.data.model.Product
import ru.svet22.app.ui.catalog.CatalogScreen
import ru.svet22.app.ui.catalog.CatalogViewModel
import ru.svet22.app.ui.catalog.ProductDetailScreen
import ru.svet22.app.ui.home.HomeScreen
import ru.svet22.app.ui.overview.OverviewScreen
import ru.svet22.app.ui.profile.ProfileScreen
import ru.svet22.app.ui.tracked.TrackedScreen

private val MenuRed = Color(0xFFE53935)
private val MenuYellow = Color(0xFFFFD600)
private val MenuGray = Color(0xFF888888)
private val MenuWhite = Color.White

private object AppRoutes {
    const val HOME = "home"
    const val CATALOG = "catalog"
    const val OVERVIEW = "overview"
    const val TRACKED = "tracked"
    const val PROFILE = "profile"
    const val PRODUCT_DETAIL = "product_detail"
}

private data class BottomMenuItem(val route: String, val title: String)

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun AppNavigation() {
    val navController = rememberNavController()
    val catalogViewModel: CatalogViewModel = viewModel()
    val backStackEntry by navController.currentBackStackEntryAsState()
    val currentRoute = backStackEntry?.destination?.route
    var selectedProduct by remember { mutableStateOf<Product?>(null) }

    val bottomItems = listOf(
        BottomMenuItem(AppRoutes.HOME, "Главная"),
        BottomMenuItem(AppRoutes.CATALOG, "Каталог"),
        BottomMenuItem(AppRoutes.OVERVIEW, "Обзор"),
        BottomMenuItem(AppRoutes.TRACKED, "Отслеживаемое"),
        BottomMenuItem(AppRoutes.PROFILE, "Профиль")
    )
    val showBottomBar = currentRoute != AppRoutes.PRODUCT_DETAIL

    Scaffold(
        bottomBar = {
            if (showBottomBar) {
                Surface(color = MenuWhite, shadowElevation = 12.dp) {
                    NavigationBar(containerColor = MenuWhite, tonalElevation = 0.dp) {
                        bottomItems.forEachIndexed { index, item ->
                            val isSelected = currentRoute == item.route
                            NavigationBarItem(
                                selected = isSelected,
                                onClick = {
                                    if (currentRoute != item.route) {
                                        navController.navigate(item.route) {
                                            popUpTo(AppRoutes.HOME) { inclusive = false }
                                            launchSingleTop = true
                                        }
                                    }
                                },
                                icon = {
                                    if (index == 2) {
                                        Surface(
                                            modifier = Modifier.size(48.dp),
                                            shape = CircleShape,
                                            color = MenuRed,
                                            shadowElevation = 5.dp
                                        ) {
                                            Icon(
                                                imageVector = Icons.Default.Visibility,
                                                contentDescription = "Обзор",
                                                tint = MenuWhite,
                                                modifier = Modifier.padding(12.dp)
                                            )
                                        }
                                    } else {
                                        val icon = when (index) {
                                            0 -> Icons.Default.Home
                                            1 -> Icons.Default.Apps
                                            3 -> Icons.Default.BookmarkBorder
                                            else -> Icons.Default.Person
                                        }
                                        Icon(imageVector = icon, contentDescription = item.title)
                                    }
                                },
                                label = { Text(text = item.title, maxLines = 1) },
                                colors = NavigationBarItemDefaults.colors(
                                    selectedIconColor = MenuRed,
                                    selectedTextColor = MenuRed,
                                    unselectedIconColor = MenuGray,
                                    unselectedTextColor = MenuGray,
                                    indicatorColor = MenuYellow.copy(alpha = 0.25f)
                                )
                            )
                        }
                    }
                }
            }
        }
    ) { innerPadding ->
        NavHost(
            navController = navController,
            startDestination = AppRoutes.HOME,
            modifier = Modifier.padding(innerPadding)
        ) {
            composable(AppRoutes.HOME) {
                HomeScreen(
                    onOpenCatalog = { navController.navigate(AppRoutes.CATALOG) },
                    onOpenOverview = { navController.navigate(AppRoutes.OVERVIEW) }
                )
            }
            composable(AppRoutes.CATALOG) {
                CatalogScreen(
                    vm = catalogViewModel,
                    onProductClick = { product ->
                        selectedProduct = product
                        navController.navigate(AppRoutes.PRODUCT_DETAIL)
                    }
                )
            }
            composable(AppRoutes.OVERVIEW) {
                OverviewScreen(
                    vm = catalogViewModel,
                    onOpenCatalog = { navController.navigate(AppRoutes.CATALOG) }
                )
            }
            composable(AppRoutes.TRACKED) { TrackedScreen() }
            composable(AppRoutes.PROFILE) { ProfileScreen() }
            composable(AppRoutes.PRODUCT_DETAIL) {
                val product = selectedProduct
                if (product != null) {
                    Scaffold(
                        topBar = {
                            TopAppBar(
                                title = { Text(text = product.name, maxLines = 1) },
                                navigationIcon = {
                                    IconButton(onClick = { navController.popBackStack() }) {
                                        Icon(Icons.Default.ArrowBack, contentDescription = "Назад")
                                    }
                                }
                            )
                        }
                    ) { detailPadding ->
                        ProductDetailScreen(
                            product = product,
                            branchName = catalogViewModel.selectedBranch?.name,
                            modifier = Modifier.padding(detailPadding),
                            onLoginClick = {
                                navController.navigate(AppRoutes.PROFILE) {
                                    launchSingleTop = true
                                }
                            }
                        )
                    }
                }
            }
        }
    }
}
