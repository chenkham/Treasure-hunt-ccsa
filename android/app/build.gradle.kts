plugins { id("com.android.application"); id("org.jetbrains.kotlin.android") }

android {
    namespace = "com.campushunt.app"
    compileSdk = 35
    defaultConfig {
        applicationId = "com.campushunt.app"
        minSdk = 28
        targetSdk = 35
        versionCode = 21
        versionName = "2.15.0"
        testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
        resValue("string", "app_name", "Treasure Hunt")
        resourceConfigurations += listOf("en")
    }
    buildFeatures { buildConfig = true }
    buildTypes {
        create("preview") {
            initWith(getByName("release"))
            applicationIdSuffix = ".preview"
            versionNameSuffix = "-preview"
            resValue("string", "app_name", "Treasure Hunt Preview")
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
            signingConfig = signingConfigs.getByName("debug")
            buildConfigField("boolean", "UI_PREVIEW", "true")
            manifestPlaceholders["allowCleartext"] = "false"
            matchingFallbacks += "release"
        }
        debug { applicationIdSuffix = ".debug"; manifestPlaceholders["allowCleartext"] = "true" }
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            manifestPlaceholders["allowCleartext"] = "false"
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }
    defaultConfig { buildConfigField("boolean", "UI_PREVIEW", "false") }
    compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
    kotlinOptions { jvmTarget = "17" }
    packaging {
        dex.useLegacyPackaging = true
        jniLibs.useLegacyPackaging = true
        resources.excludes += setOf("META-INF/LICENSE*", "META-INF/NOTICE*")
    }
    testOptions { unitTests.isIncludeAndroidResources = true }
}
dependencies {
    implementation("com.google.android.material:material:1.13.0")
    implementation("androidx.core:core-ktx:1.13.1")
    implementation("androidx.activity:activity-ktx:1.9.3")
    implementation("androidx.lifecycle:lifecycle-runtime-ktx:2.8.7")
    implementation("androidx.camera:camera-camera2:1.5.3")
    implementation("androidx.camera:camera-lifecycle:1.5.3")
    implementation("androidx.camera:camera-view:1.5.3")
    implementation("com.google.zxing:core:3.5.3")
    implementation("androidx.work:work-runtime-ktx:2.9.0")
    testImplementation("junit:junit:4.13.2")
    testImplementation("org.robolectric:robolectric:4.17")
    androidTestImplementation("androidx.test:runner:1.6.2")
    androidTestImplementation("androidx.test:core:1.6.1")
    androidTestImplementation("androidx.test.ext:junit:1.2.1")
}

tasks.withType<Test>().configureEach {
    systemProperty("hunt.screenshots", layout.buildDirectory.dir("reports/ui-preview").get().asFile.absolutePath)
    if(project.hasProperty("compatibleHostTests")) {
        systemProperty("robolectric.enabledSdks","28")
        exclude("**/PreviewFlowTest.class")
    }
}
