pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
        maven(url = "https://nexus-external.vkteam.ru/repository/vkid-sdk-android/")
        maven(url = "https://nexus-external.vkteam.ru/repository/maven/")
    }
}

dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
        maven(url = "https://nexus-external.vkteam.ru/repository/vkid-sdk-android/")
        maven(url = "https://nexus-external.vkteam.ru/repository/maven/")
    }
}

rootProject.name = "Svet22"
include(":app")
