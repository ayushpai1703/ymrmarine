plugins {
    id("com.android.application")
    id("kotlin-android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

android {
    namespace = "com.ymrmarine.ysms"
    compileSdk = flutter.compileSdkVersion
    // NDK 27: strips the Flutter engine's debug symbols (a much smaller APK).
    ndkVersion = "27.0.12077973"

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    defaultConfig {
        applicationId = "com.ymrmarine.ysms"
        minSdk = 24
        targetSdk = flutter.targetSdkVersion
        // flutter build --build-number sets the version code (CI: 100000 + run number, so a new APK
        // always installs over the earlier test APKs).
        versionCode = flutter.versionCode
        versionName = flutter.versionName
    }

    // Two separate apps: staging (staging server and data) and live. Both can be on one phone.
    flavorDimensions += "server"
    productFlavors {
        create("staging") {
            dimension = "server"
            applicationId = "com.ymrmarine.ysms.staging"
            manifestPlaceholders["appName"] = "YSMS Staging"
        }
        create("live") {
            dimension = "server"
            // The same app id as the earlier live test APK, so this installs over it.
            applicationId = "com.ymrmarine.ysms"
            manifestPlaceholders["appName"] = "YMR YSMS"
        }
    }

    signingConfigs {
        // Test builds only: one fixed test key so a newer APK installs over the older one.
        // The Play Store release key is separate and is never kept in the repository.
        create("test") {
            storeFile = file("test-debug.keystore")
            storePassword = "android"
            keyAlias = "androiddebugkey"
            keyPassword = "android"
        }
    }

    buildTypes {
        getByName("debug") {
            signingConfig = signingConfigs.getByName("test")
        }
        getByName("release") {
            signingConfig = signingConfigs.getByName("test")
        }
    }
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
