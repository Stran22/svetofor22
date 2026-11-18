package ru.svet22.app.ui.splash

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.delay

private val Red = Color(0xFFFF3434)
private val Yellow = Color(0xFFFFDC26)
private val Green = Color(0xFF32FF65)
private val Unlit = Color(0xFF34383B)

/** Cold-start overlay: red, yellow, then a bright glowing green traffic light. */
@Composable
fun TrafficLightSplash(onFinished: () -> Unit) {
    val red = remember { Animatable(0f) }
    val yellow = remember { Animatable(0f) }
    val green = remember { Animatable(0f) }
    val overlay = remember { Animatable(1f) }

    LaunchedEffect(Unit) {
        red.animateTo(1f, tween(220))
        delay(140)
        yellow.animateTo(1f, tween(220))
        delay(140)
        green.animateTo(1f, tween(260))
        delay(230)
        overlay.animateTo(0f, tween(300))
        onFinished()
    }

    Box(
        modifier = Modifier
            .fillMaxSize()
            .alpha(overlay.value)
            .background(Color(0xFF17191C)),
        contentAlignment = Alignment.Center
    ) {
        Column(
            modifier = Modifier
                .size(width = 132.dp, height = 326.dp)
                .clip(RoundedCornerShape(30.dp))
                .background(Color(0xFF090B0D))
                .padding(vertical = 16.dp),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.SpaceEvenly
        ) {
            SignalLight(Red, red.value)
            SignalLight(Yellow, yellow.value)
            SignalLight(Green, green.value, glow = true)
        }
    }
}

@Composable
private fun SignalLight(color: Color, intensity: Float, glow: Boolean = false) {
    Box(
        modifier = Modifier.size(88.dp),
        contentAlignment = Alignment.Center
    ) {
        if (glow) {
            Box(
                modifier = Modifier
                    .fillMaxSize()
                    .alpha(intensity)
                    .drawBehind {
                        drawCircle(
                            brush = Brush.radialGradient(
                                colors = listOf(color.copy(alpha = 0.65f), Color.Transparent),
                                center = Offset(size.width / 2f, size.height / 2f),
                                radius = size.minDimension / 2f
                            )
                        )
                    }
            )
        }
        Box(
            modifier = Modifier
                .size(68.dp)
                .clip(CircleShape)
                .background(Color(0xFF202326))
                .padding(3.dp)
                .clip(CircleShape)
                .background(
                    androidx.compose.ui.graphics.lerp(Unlit, color, intensity)
                )
        )
    }
}
