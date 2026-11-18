package ru.svet22.app.ui.splash

import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.tween
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.width
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
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.DrawScope
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
    val carsProgress = remember { Animatable(0f) }

    LaunchedEffect(Unit) {
        red.animateTo(1f, tween(220))
        delay(140)
        yellow.animateTo(1f, tween(220))
        delay(140)
        green.animateTo(1f, tween(260))
        carsProgress.animateTo(1f, tween(420))
        overlay.animateTo(0f, tween(300))
        onFinished()
    }

    BoxWithConstraints(
        modifier = Modifier
            .fillMaxSize()
            .alpha(overlay.value)
            .background(Color(0xFF17191C)),
        contentAlignment = Alignment.Center
    ) {
        CityBackdrop(carsProgress.value)

        val housingHeight = maxHeight * 0.70f
        val housingWidth = minOf(maxWidth * 0.65f, housingHeight * 0.42f)
        val lightSize = minOf(housingWidth * 0.78f, housingHeight * 0.26f)

        Column(
            modifier = Modifier
                .width(housingWidth)
                .height(housingHeight)
                .clip(RoundedCornerShape(housingWidth * 0.20f))
                .background(Color(0xFF090B0D))
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
private fun SignalLight(color: Color, intensity: Float, diameter: androidx.compose.ui.unit.Dp, glow: Boolean = false) {
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
                .size(diameter * 0.78f)
                .clip(CircleShape)
                .background(Color(0xFF202326))
                .padding(diameter * 0.035f)
                .clip(CircleShape)
                .background(
                    androidx.compose.ui.graphics.lerp(Unlit, color, intensity)
                )
        )
    }
}

/** Low-contrast city scenery painted behind the traffic light, without image assets. */
@Composable
private fun CityBackdrop(carProgress: Float) {
    Canvas(modifier = Modifier.fillMaxSize()) {
        val w = size.width
        val h = size.height
        val roadTop = h * 0.78f
        val skyline = Color(0xFF292F37)
        val windows = Color(0xFF454B53)

        // Buildings and a few dim windows.
        val widths = floatArrayOf(.13f, .17f, .12f, .19f, .15f, .14f, .16f)
        val heights = floatArrayOf(.24f, .33f, .19f, .29f, .36f, .22f, .28f)
        var x = 0f
        for (i in widths.indices) {
            val bw = w * widths[i]
            val bh = h * heights[i]
            drawRect(skyline, topLeft = Offset(x, roadTop - bh), size = Size(bw - 2f, bh))
            val windowWidth = bw * .11f
            val gap = bw * .24f
            for (col in 1..2) {
                for (row in 1..3) {
                    drawRect(
                        windows.copy(alpha = if ((i + col + row) % 3 == 0) .38f else .16f),
                        topLeft = Offset(x + gap * col, roadTop - bh + bh * row * .19f),
                        size = Size(windowWidth, bh * .035f)
                    )
                }
            }
            x += bw
        }

        drawRect(Color(0xFF30363D), topLeft = Offset(0f, roadTop), size = Size(w, h - roadTop))
        drawRect(Color(0xFF50565D), topLeft = Offset(0f, roadTop), size = Size(w, h * .004f))
        for (i in 0..5) {
            drawRect(
                Color(0xFF777B7E).copy(alpha = .35f),
                topLeft = Offset(i * w * .20f, roadTop + (h - roadTop) * .55f),
                size = Size(w * .10f, h * .004f)
            )
        }

        val carWidth = w * .19f
        val carHeight = h * .045f
        val travel = carProgress * w * .29f
        drawCar(
            x = w * .06f + travel,
            y = roadTop + h * .015f,
            width = carWidth,
            height = carHeight,
            body = Color(0xFF748A95)
        )
        drawCar(
            x = w * .73f + travel * .65f,
            y = roadTop + h * .095f,
            width = carWidth * .82f,
            height = carHeight * .85f,
            body = Color(0xFF626D7D)
        )
    }
}

private fun DrawScope.drawCar(x: Float, y: Float, width: Float, height: Float, body: Color) {
    val cabin = Path().apply {
        moveTo(x + width * .18f, y + height * .50f)
        lineTo(x + width * .31f, y + height * .12f)
        lineTo(x + width * .68f, y + height * .12f)
        lineTo(x + width * .83f, y + height * .50f)
        close()
    }
    drawPath(cabin, body.copy(alpha = .85f))
    drawRect(Color(0xFF394A55), Offset(x + width * .34f, y + height * .20f), Size(width * .29f, height * .24f))
    drawRoundRect(
        body,
        topLeft = Offset(x, y + height * .45f),
        size = Size(width, height * .40f),
        cornerRadius = androidx.compose.ui.geometry.CornerRadius(height * .13f)
    )
    drawCircle(Color(0xFF181C21), radius = height * .16f, center = Offset(x + width * .22f, y + height * .85f))
    drawCircle(Color(0xFF181C21), radius = height * .16f, center = Offset(x + width * .78f, y + height * .85f))
    drawRect(Color(0xFFB6AB84).copy(alpha = .7f), Offset(x + width * .93f, y + height * .53f), Size(width * .055f, height * .12f))
}
