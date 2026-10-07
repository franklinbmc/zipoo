# Zipoo Android Wrapper

Native Android WebView shell for `https://app.zipoo.co.tz/`.

## Debug APK

```powershell
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
$env:ANDROID_HOME='C:\Users\p_fra\AppData\Local\Android\Sdk'
& 'C:\Users\p_fra\.gradle\wrapper\dists\gradle-9.3.1-bin\23ovyewtku6u96viwx3xl3oks\gradle-9.3.1\bin\gradle.bat' --offline :app:assembleDebug
```

Output:

```text
android/app/build/outputs/apk/debug/app-debug.apk
```

## Debug App Bundle

```powershell
$env:JAVA_HOME='C:\Program Files\Android\Android Studio\jbr'
$env:ANDROID_HOME='C:\Users\p_fra\AppData\Local\Android\Sdk'
& 'C:\Users\p_fra\.gradle\wrapper\dists\gradle-9.3.1-bin\23ovyewtku6u96viwx3xl3oks\gradle-9.3.1\bin\gradle.bat' --offline :app:bundleDebug
```

Output:

```text
android/app/build/outputs/bundle/debug/app-debug.aab
```

For Google Play, add a release keystore and release signing config, then build `:app:bundleRelease`.
