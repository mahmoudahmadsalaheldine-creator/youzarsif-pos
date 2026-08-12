<?php

// Simple file-based login throttle, keyed by IP address. Not as robust as a
// dedicated rate-limiter (e.g. Redis) or a WAF, but stops naive brute-force
// scripts without needing a new DB table or external service.
define("LOGIN_THROTTLE_FILE", __DIR__ . "/../storage/login_throttle.json");
define("LOGIN_THROTTLE_MAX_ATTEMPTS", 5);
define("LOGIN_THROTTLE_WINDOW_SECONDS", 600);   // count failures within this window
define("LOGIN_THROTTLE_LOCKOUT_SECONDS", 300);  // lockout duration once tripped

function loginThrottleKey()
{
    return $_SERVER["REMOTE_ADDR"] ?? "unknown";
}

function loginThrottleLoad()
{
    if (!file_exists(LOGIN_THROTTLE_FILE)) {
        return [];
    }
    $data = json_decode((string) @file_get_contents(LOGIN_THROTTLE_FILE), true);
    return is_array($data) ? $data : [];
}

function loginThrottleSave($data)
{
    @file_put_contents(LOGIN_THROTTLE_FILE, json_encode($data), LOCK_EX);
}

// Returns the number of seconds remaining if this IP is currently locked out,
// or null if it's free to attempt a login.
function loginThrottleLockedSecondsRemaining()
{
    $entry = loginThrottleLoad()[loginThrottleKey()] ?? null;
    if ($entry && !empty($entry["locked_until"]) && $entry["locked_until"] > time()) {
        return $entry["locked_until"] - time();
    }
    return null;
}

function loginThrottleRecordFailure()
{
    $key = loginThrottleKey();
    $now = time();
    $data = loginThrottleLoad();
    $entry = $data[$key] ?? ["attempts" => [], "locked_until" => 0];

    $entry["attempts"] = array_values(array_filter(
        $entry["attempts"],
        function ($t) use ($now) {
            return $t > $now - LOGIN_THROTTLE_WINDOW_SECONDS;
        }
    ));
    $entry["attempts"][] = $now;

    if (count($entry["attempts"]) >= LOGIN_THROTTLE_MAX_ATTEMPTS) {
        $entry["locked_until"] = $now + LOGIN_THROTTLE_LOCKOUT_SECONDS;
        $entry["attempts"] = [];
    }

    $data[$key] = $entry;
    loginThrottleSave($data);
}

function loginThrottleClear()
{
    $data = loginThrottleLoad();
    unset($data[loginThrottleKey()]);
    loginThrottleSave($data);
}
