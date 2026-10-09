package com.zipoo.app;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.graphics.Color;
import android.net.ConnectivityManager;
import android.net.NetworkCapabilities;
import android.net.Uri;
import android.os.Bundle;
import android.os.Environment;
import android.provider.Settings;
import android.webkit.ValueCallback;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.view.Window;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.webkit.WebChromeClient;
import android.webkit.WebChromeClient.FileChooserParams;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.ImageView;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.biometric.BiometricManager;
import androidx.biometric.BiometricPrompt;
import androidx.core.content.ContextCompat;
import androidx.fragment.app.FragmentActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import java.util.concurrent.Executor;

public class MainActivity extends FragmentActivity {
    private static final String HOME_URL = BuildConfig.WEB_URL;
    private static final String HOST = Uri.parse(HOME_URL).getHost();
    private static final int ZIPOO_BLUE = Color.rgb(31, 47, 87);
    private static final int FILE_CHOOSER_REQUEST_CODE = 72;
    private static final String PREFS_NAME = "zipoo_android";
    private static final String PREF_HAS_AUTHENTICATED = "has_authenticated_session";

    private WebView webView;
    private SwipeRefreshLayout swipeRefreshLayout;
    private View splashView;
    private View lockView;
    private ProgressBar progressBar;
    private ValueCallback<Uri[]> fileChooserCallback;
    private boolean firstPageLoaded = false;
    private boolean waitingForUnlock = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        requestWindowFeature(Window.FEATURE_NO_TITLE);
        getWindow().setStatusBarColor(ZIPOO_BLUE);
        getWindow().setNavigationBarColor(ZIPOO_BLUE);

        FrameLayout root = new FrameLayout(this);
        root.setBackgroundColor(ZIPOO_BLUE);

        webView = new WebView(this);
        webView.setLayoutParams(new SwipeRefreshLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        swipeRefreshLayout = new SwipeRefreshLayout(this);
        swipeRefreshLayout.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));
        swipeRefreshLayout.setColorSchemeColors(Color.rgb(20, 119, 255), Color.rgb(11, 191, 154), ZIPOO_BLUE);
        swipeRefreshLayout.setProgressBackgroundColorSchemeColor(Color.WHITE);
        swipeRefreshLayout.setOnRefreshListener(() -> webView.reload());
        swipeRefreshLayout.setOnChildScrollUpCallback((parent, child) -> webView.getScrollY() > 0);
        swipeRefreshLayout.addView(webView);
        root.addView(swipeRefreshLayout);

        progressBar = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        FrameLayout.LayoutParams progressParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                dp(3),
                Gravity.TOP
        );
        progressBar.setLayoutParams(progressParams);
        progressBar.setMax(100);
        progressBar.setVisibility(View.GONE);
        root.addView(progressBar);

        splashView = buildSplash();
        root.addView(splashView);
        lockView = buildLockView();
        lockView.setVisibility(View.GONE);
        root.addView(lockView);

        setContentView(root);
        configureWebView();
        enterImmersiveMode();

        if (shouldRequireUnlock(savedInstanceState)) {
            showLockAndAuthenticate();
        } else if (savedInstanceState == null) {
            webView.loadUrl(HOME_URL);
        } else {
            webView.restoreState(savedInstanceState);
            hideSplash();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        enterImmersiveMode();
        webView.onResume();
    }

    @Override
    protected void onPause() {
        webView.onPause();
        super.onPause();
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        webView.saveState(outState);
    }

    @Override
    public void onBackPressed() {
        if (webView.canGoBack()) {
            webView.goBack();
            return;
        }
        super.onBackPressed();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            enterImmersiveMode();
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        super.onActivityResult(requestCode, resultCode, data);
        if (requestCode != FILE_CHOOSER_REQUEST_CODE || fileChooserCallback == null) {
            return;
        }

        Uri[] results = null;
        if (resultCode == RESULT_OK && data != null) {
            if (data.getClipData() != null) {
                int itemCount = data.getClipData().getItemCount();
                results = new Uri[itemCount];
                for (int i = 0; i < itemCount; i++) {
                    results[i] = data.getClipData().getItemAt(i).getUri();
                }
            } else if (data.getData() != null) {
                results = new Uri[]{data.getData()};
            }
        }

        fileChooserCallback.onReceiveValue(results);
        fileChooserCallback = null;
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void configureWebView() {
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setLoadsImagesAutomatically(true);
        settings.setMediaPlaybackRequiresUserGesture(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setUserAgentString(settings.getUserAgentString() + " ZipooAndroid/1.0");

        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        cookieManager.setAcceptThirdPartyCookies(webView, true);

        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int newProgress) {
                progressBar.setProgress(newProgress);
                progressBar.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> filePathCallback, FileChooserParams fileChooserParams) {
                if (fileChooserCallback != null) {
                    fileChooserCallback.onReceiveValue(null);
                }
                fileChooserCallback = filePathCallback;

                Intent chooserIntent;
                try {
                    chooserIntent = fileChooserParams.createIntent();
                } catch (Exception ex) {
                    chooserIntent = new Intent(Intent.ACTION_GET_CONTENT);
                    chooserIntent.addCategory(Intent.CATEGORY_OPENABLE);
                    chooserIntent.setType("image/*");
                    chooserIntent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE, true);
                }

                try {
                    startActivityForResult(chooserIntent, FILE_CHOOSER_REQUEST_CODE);
                    return true;
                } catch (Exception ex) {
                    fileChooserCallback = null;
                    Toast.makeText(MainActivity.this, "No image picker available", Toast.LENGTH_SHORT).show();
                    return false;
                }
            }
        });

        webView.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                if (uri == null) {
                    return false;
                }

                String scheme = uri.getScheme();
                if ("tel".equalsIgnoreCase(scheme) || "mailto".equalsIgnoreCase(scheme) || "sms".equalsIgnoreCase(scheme)) {
                    startActivity(new Intent(Intent.ACTION_VIEW, uri));
                    return true;
                }

                String host = uri.getHost();
                if (host != null && (host.equalsIgnoreCase(HOST) || host.endsWith(".zipoo.co.tz"))) {
                    return false;
                }

                startActivity(new Intent(Intent.ACTION_VIEW, uri));
                return true;
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                firstPageLoaded = true;
                swipeRefreshLayout.setRefreshing(false);
                rememberAuthenticatedPage(url);
                hideSplash();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) {
                    swipeRefreshLayout.setRefreshing(false);
                }
                if (request.isForMainFrame() && !firstPageLoaded && !isOnline()) {
                    view.loadDataWithBaseURL(
                            HOME_URL,
                            offlineHtml(),
                            "text/html",
                            "UTF-8",
                            null
                    );
                    hideSplash();
                }
            }
        });

        webView.setDownloadListener(downloadListener());
    }

    private DownloadListener downloadListener() {
        return (url, userAgent, contentDisposition, mimeType, contentLength) -> {
            try {
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                request.setMimeType(mimeType);
                request.addRequestHeader("User-Agent", userAgent);
                request.addRequestHeader("Cookie", CookieManager.getInstance().getCookie(url));
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setDestinationInExternalPublicDir(
                        Environment.DIRECTORY_DOWNLOADS,
                        URLUtil.guessFileName(url, contentDisposition, mimeType)
                );
                DownloadManager manager = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                if (manager != null) {
                    manager.enqueue(request);
                    Toast.makeText(this, "Download started", Toast.LENGTH_SHORT).show();
                }
            } catch (Exception ex) {
                startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url)));
            }
        };
    }

    private View buildSplash() {
        FrameLayout splash = new FrameLayout(this);
        splash.setBackgroundColor(ZIPOO_BLUE);
        splash.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        LinearLayout bottom = new LinearLayout(this);
        bottom.setOrientation(LinearLayout.VERTICAL);
        bottom.setGravity(Gravity.CENTER);
        bottom.setPadding(dp(24), dp(24), dp(24), dp(42));

        ImageView icon = new ImageView(this);
        icon.setImageResource(R.drawable.zipoo_icon);
        LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(92), dp(92));
        icon.setLayoutParams(iconParams);
        icon.setAdjustViewBounds(true);

        TextView title = new TextView(this);
        title.setText("Zipoo - Your business, Your numbers");
        title.setTextColor(Color.WHITE);
        title.setTextSize(17);
        title.setGravity(Gravity.CENTER);
        title.setPadding(0, dp(14), 0, 0);
        title.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);

        bottom.addView(icon);
        bottom.addView(title);

        FrameLayout.LayoutParams bottomParams = new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.BOTTOM
        );
        splash.addView(bottom, bottomParams);
        return splash;
    }

    private View buildLockView() {
        FrameLayout lock = new FrameLayout(this);
        lock.setBackgroundColor(ZIPOO_BLUE);
        lock.setLayoutParams(new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.MATCH_PARENT
        ));

        LinearLayout content = new LinearLayout(this);
        content.setOrientation(LinearLayout.VERTICAL);
        content.setGravity(Gravity.CENTER);
        content.setPadding(dp(28), dp(28), dp(28), dp(28));

        ImageView icon = new ImageView(this);
        icon.setImageResource(R.drawable.zipoo_icon);
        LinearLayout.LayoutParams iconParams = new LinearLayout.LayoutParams(dp(82), dp(82));
        icon.setLayoutParams(iconParams);
        icon.setAdjustViewBounds(true);

        TextView title = new TextView(this);
        title.setText("Unlock Zipoo");
        title.setTextColor(Color.WHITE);
        title.setTextSize(22);
        title.setGravity(Gravity.CENTER);
        title.setPadding(0, dp(16), 0, dp(6));
        title.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);

        TextView text = new TextView(this);
        text.setText("Use your phone security to continue.");
        text.setTextColor(Color.argb(220, 255, 255, 255));
        text.setTextSize(15);
        text.setGravity(Gravity.CENTER);

        TextView retry = new TextView(this);
        retry.setText("Tap to unlock");
        retry.setTextColor(Color.WHITE);
        retry.setTextSize(15);
        retry.setGravity(Gravity.CENTER);
        retry.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        retry.setPadding(dp(18), dp(18), dp(18), 0);
        retry.setOnClickListener(v -> showBiometricPrompt());

        content.addView(icon);
        content.addView(title);
        content.addView(text);
        content.addView(retry);

        lock.addView(content, new FrameLayout.LayoutParams(
                ViewGroup.LayoutParams.MATCH_PARENT,
                ViewGroup.LayoutParams.WRAP_CONTENT,
                Gravity.CENTER
        ));
        return lock;
    }

    private void hideSplash() {
        if (splashView.getVisibility() == View.GONE) {
            return;
        }
        splashView.animate()
                .alpha(0f)
                .setDuration(220)
                .withEndAction(() -> splashView.setVisibility(View.GONE))
                .start();
    }

    private void enterImmersiveMode() {
        if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.R) {
            WindowInsetsController controller = getWindow().getInsetsController();
            if (controller != null) {
                controller.show(WindowInsets.Type.statusBars());
                controller.hide(WindowInsets.Type.navigationBars());
                controller.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
            }
        } else {
            getWindow().getDecorView().setSystemUiVisibility(
                    View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                            | View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                            | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                            | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
            );
        }
    }

    private boolean shouldRequireUnlock(Bundle savedInstanceState) {
        return savedInstanceState == null
                && getPreferences().getBoolean(PREF_HAS_AUTHENTICATED, false)
                && canAuthenticate();
    }

    private SharedPreferences getPreferences() {
        return getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
    }

    private boolean canAuthenticate() {
        int authenticators = BiometricManager.Authenticators.BIOMETRIC_WEAK
                | BiometricManager.Authenticators.DEVICE_CREDENTIAL;
        return BiometricManager.from(this).canAuthenticate(authenticators) == BiometricManager.BIOMETRIC_SUCCESS;
    }

    private void showLockAndAuthenticate() {
        waitingForUnlock = true;
        hideSplash();
        lockView.setAlpha(1f);
        lockView.setVisibility(View.VISIBLE);
        showBiometricPrompt();
    }

    private void showBiometricPrompt() {
        if (!waitingForUnlock) {
            waitingForUnlock = true;
            lockView.setVisibility(View.VISIBLE);
        }
        int authenticators = BiometricManager.Authenticators.BIOMETRIC_WEAK
                | BiometricManager.Authenticators.DEVICE_CREDENTIAL;
        Executor executor = ContextCompat.getMainExecutor(this);
        BiometricPrompt prompt = new BiometricPrompt(this, executor, new BiometricPrompt.AuthenticationCallback() {
            @Override
            public void onAuthenticationSucceeded(BiometricPrompt.AuthenticationResult result) {
                super.onAuthenticationSucceeded(result);
                waitingForUnlock = false;
                lockView.setVisibility(View.GONE);
                if (webView.getUrl() == null) {
                    webView.loadUrl(HOME_URL);
                }
            }

            @Override
            public void onAuthenticationError(int errorCode, CharSequence errString) {
                super.onAuthenticationError(errorCode, errString);
                if (errorCode == BiometricPrompt.ERROR_NEGATIVE_BUTTON || errorCode == BiometricPrompt.ERROR_USER_CANCELED) {
                    Toast.makeText(MainActivity.this, "Zipoo is locked", Toast.LENGTH_SHORT).show();
                }
            }
        });

        BiometricPrompt.PromptInfo promptInfo = new BiometricPrompt.PromptInfo.Builder()
                .setTitle("Unlock Zipoo")
                .setSubtitle("Use face, fingerprint, PIN, pattern, or password")
                .setAllowedAuthenticators(authenticators)
                .build();
        prompt.authenticate(promptInfo);
    }

    private void rememberAuthenticatedPage(String url) {
        Uri uri = Uri.parse(url);
        String path = uri.getPath() == null ? "" : uri.getPath().toLowerCase();
        boolean isLoginPage = path.contains("/login") || path.contains("/register") || path.contains("/reset-password") || path.contains("/forgot-password");
        if (!isLoginPage && uri.getHost() != null && uri.getHost().endsWith("zipoo.co.tz")) {
            getPreferences().edit().putBoolean(PREF_HAS_AUTHENTICATED, true).apply();
        }
    }

    private boolean isOnline() {
        ConnectivityManager manager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (manager == null) {
            return false;
        }
        NetworkCapabilities capabilities = manager.getNetworkCapabilities(manager.getActiveNetwork());
        return capabilities != null
                && (capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI)
                || capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR)
                || capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET));
    }

    private String offlineHtml() {
        return "<!doctype html><html><head><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
                + "<style>body{margin:0;background:#1F2F57;color:#fff;font-family:sans-serif;min-height:100vh;display:grid;place-items:center;text-align:center;padding:28px;box-sizing:border-box}"
                + "h1{font-size:1.35rem;margin:0 0 8px}p{opacity:.84;line-height:1.45}.btn{margin-top:18px;border:1px solid rgba(255,255,255,.55);border-radius:12px;padding:12px 16px;color:#fff;text-decoration:none;display:inline-block}</style>"
                + "</head><body><main><h1>Zipoo is offline</h1><p>Open the app once while connected, then your cached Zipoo workspace will keep working offline.</p>"
                + "<a class=\"btn\" href=\"android-settings://wireless\">Open network settings</a></main></body></html>";
    }

    private int dp(int value) {
        return Math.round(value * getResources().getDisplayMetrics().density);
    }
}
