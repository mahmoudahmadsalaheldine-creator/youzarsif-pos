<?php

/**
 * Format a number: remove unnecessary trailing zeros.
 * 10.00 → 10 | 10.50 → 10.50 | 10.500 → 10.50
 */
function formatNum($value) {
    $rounded = round((float)$value, 2);
    if ($rounded == intval($rounded)) {
        return number_format($rounded, 0);
    }
    return number_format($rounded, 2);
}