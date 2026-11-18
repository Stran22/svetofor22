package ru.svet22.app.ui.splash

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
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
import androidx.compose.ui.graphics.Brush
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.lerp
import androidx.compose.ui.unit.Dp
import kotlinx.coroutines.delay

private val Red = Color(0xFFFF3434)
private val Yellow = Color(0xFFFFDC26)
private val Green = Color(0xFF32FF65)
private val Unlit = Color(0xFF34383B)

/** Premium cold-start overlay; the existing navigation remains mounted underneath. */
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

    BoxWithConstraints(
        modifier = Modifier
            .fillMaxSize()
            .alpha(overlay.value)
            .background(
                Brush.verticalGradient(
                    colors = listOf(
                        Color(0xFF252A30),
                        Color(0xFF171B20),
                        Color(0xFF101317)
                    )
                )
            ),
        contentAlignment = Alignment.Center
    ) {
        val housingHeight = maxHeight * 0.70f
        val housingWidth = minOf(maxWidth * 0.65f, housingHeight * 0.42f)
        val lightSize = minOf(housingWidth * 0.78f, housingHeight * 0.26f)

        Column(
            modifier = Modifier
                .width(housingWidth)
                .height(housingHeight)
                .clip(RoundedCornerShape(housingWidth * 0.20f))
                .background(
                    Brush.horizontalGradient(
                        listOf(Color(0xFF171A1F), Color(0xFF080A0D), Color(0xFF171A1F))
                    )
                )
                .padding(vertical = housingHeight * 0.04f),
            horizontalAlignment = Alignment.CenterHorizontally,
            verticalArrangement = Arrangement.SpaceEvenly
        ) {
            SignalLight(Red, red.value, lightSize)
            SignalLight(Yellow, yellow.value, lightSize)
            SignalLight(Green, green.value, lightSize, glow = true)
        }
    }
}

@Composable
private fun SignalLight(color: Color, intensity: Float, diameter: Dp, glow: Boolean = false) {
    Box(
        modifier = Modifier.size(diameter),
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
                                colors = listOf(
                                    color.copy(alpha = 0.70f),
                                    color.copy(alpha = 0.22f),
                                    Color.Transparent
                                ),
                                center = Offset(size.width / 2f, size.height / 2f),
                                radius = size.minDimension / 2f
                            )
                        )
                    }
            )
        }
        Box(
            modifier = Modifier
                .size(diameter * 0.78f)
                .clip(CircleShape)
                .background(Color(0xFF202326))
                .padding(diameter * 0.035f)
                .clip(CircleShape)
                .background(
                    Brush.radialGradient(
                        colors = listOf(
                            lerp(Unlit, Color.White, intensity * if (glow) 0.32f else 0.12f),
                            lerp(Unlit, color, intensity),
                            lerp(Unlit, color.copy(alpha = 0.85f), intensity)
                        )
                    )
                )
        )
    }
}
