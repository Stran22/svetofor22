package ru.svet22.app.ui.splash

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay

private val Red = Color(0xFFE53935)
private val Yellow = Color(0xFFFFD600)
private val Green = Color(0xFF22B455)

/** Decorative cold-start overlay; does not change navigation or network state. */
@Composable
fun TrafficLightSplash(onFinished: () -> Unit) {
    val width = androidx.compose.runtime.remember { Animatable(0.08f) }
    val height = androidx.compose.runtime.remember { Animatable(0.006f) }

    LaunchedEffect(Unit) {
        width.animateTo(1f, animationSpec = tween(650))
        delay(100)
        height.animateTo(1f, animationSpec = tween(550))
        delay(250)
        onFinished()
    }

    Box(
        modifier = Modifier.fillMaxSize().background(Color(0xFF202020)),
        contentAlignment = Alignment.Center
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(width.value).fillMaxHeight(height.value)
        ) {
            Box(Modifier.weight(1f).fillMaxSize().background(Red))
            Box(Modifier.weight(1f).fillMaxSize().background(Yellow))
            Box(Modifier.weight(1f).fillMaxSize().background(Green))
        }
    }
}
