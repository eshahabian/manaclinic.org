plugins {
    id("com.android.application")
}

android {
    namespace = "org.manaclinic.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "org.manaclinic.app"
        minSdk = 24
        targetSdk = 35
        versionCode = 2
        versionName = "1.1.0"
        buildConfigField("String", "START_URL", "\"https://manaclinic.org\"")
        buildConfigField("boolean", "STAFF_ONLY", "false")
    }

    flavorDimensions += "audience"
    productFlavors {
        create("public") {
            dimension = "audience"
        }
        create("staff") {
            dimension = "audience"
            applicationId = "org.manaclinic.staff"
            buildConfigField("String", "START_URL", "\"https://manaclinic.org/app\"")
            buildConfigField("boolean", "STAFF_ONLY", "true")
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = false
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro"
            )
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }

    buildFeatures {
        buildConfig = true
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.15.0")
    implementation("androidx.activity:activity-ktx:1.9.3")
}
