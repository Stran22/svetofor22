# Svet22 Android

Native Android client for the Svet22 grocery catalog.

## Build environment

- Android Studio Panda 4 or newer
- JDK 17 (Android Studio Gradle JDK: Embedded JDK 17 or Microsoft OpenJDK 17)
- Gradle Wrapper 8.7 (included; do not select a separately installed Gradle 9.x)
- Android Gradle Plugin 8.6.1
- Kotlin 2.0.0
- Android SDK 34

The project uses the checked-in Gradle Wrapper. On Windows run `gradlew.bat --version` from this `android` directory. It must report Gradle 8.7 and JVM 17. On macOS/Linux run `./gradlew --version`.

## Current foundation

- Loads active branches from `GET /api/v1/branches`.
- Allows the user to switch the selected store.
- Loads products for the selected branch from `GET /api/v1/products?branch_id={id}`.
- Filters the currently loaded product list by name.
- Shows the branch-specific price and stock quantity.
- Contains no cart or checkout.

API base URL: `https://baza.altaj-otdyh.ru/api/v1/`.

## Open in Android Studio

Open the `android` directory (not the repository root) as a project. In Settings → Build Tools → Gradle select **Distribution: Wrapper** and **Gradle JDK: JDK 17**. Sync the project. Android Studio will download Gradle 8.7 using the included wrapper configuration.

## Next implementation steps

1. Extract API models, Retrofit client and repository from the activity.
2. Add proper loading/error/empty states and pagination.
3. Add product details and per-branch availability.
4. Add categories and category navigation.
5. Add persistent selected-branch preference.
6. Add guest device registration and token storage.
7. Add social sign-in and push notifications after the core catalog is stable.
