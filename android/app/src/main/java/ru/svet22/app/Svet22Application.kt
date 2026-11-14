package ru.svet22.app

import android.app.Application
import com.vk.id.VKID
import java.util.Locale

class Svet22Application : Application() {
    override fun onCreate() {
        super.onCreate()
        VKID.init(this)
        VKID.instance.setLocale(Locale("ru"))
    }
}
