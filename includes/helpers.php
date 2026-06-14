<?php

/**
 * Loads config/config.php, falling back to an error if it hasn't been created.
 */
function app_config(): array
{
    static $config = null;

    if ($config === null) {
        $path = __DIR__ . '/../config/config.php';
        if (!file_exists($path)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Missing config/config.php. Copy config/config.sample.php to config/config.php and fill in your credentials.',
            ]);
            exit;
        }
        $config = require $path;
    }

    return $config;
}

/**
 * Sends a JSON response and stops execution.
 */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Reads and decodes the JSON request body.
 */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Normalizes an Iranian mobile number to the 09xxxxxxxxx format (digits only).
 */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (str_starts_with($digits, '0098')) {
        $digits = '0' . substr($digits, 4);
    } elseif (str_starts_with($digits, '98')) {
        $digits = '0' . substr($digits, 2);
    } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $digits = '0' . $digits;
    }

    return $digits;
}

/**
 * Applies a percentage increase/decrease and a rounding mode to a price.
 *
 * @param float  $price    Original price (numeric, never negative).
 * @param float  $percent  Percentage to apply (e.g. 10 for +10%, -10 for -10%).
 * @param string $mode     One of: 'none', 'nearest', 'up', 'down', 'step', 'ending'.
 * @param float  $stepOrEnding For 'step': the rounding step (e.g. 1000, 0.5).
 *                              For 'ending': the desired decimal ending (e.g. 0.99, 0.95, 0).
 */
function adjust_price(float $price, float $percent, string $mode = 'none', float $stepOrEnding = 0): float
{
    $newPrice = $price * (1 + ($percent / 100));

    if ($newPrice < 0) {
        $newPrice = 0;
    }

    switch ($mode) {
        case 'nearest':
            $newPrice = round($newPrice);
            break;

        case 'up':
            $newPrice = ceil($newPrice);
            break;

        case 'down':
            $newPrice = floor($newPrice);
            break;

        case 'step':
            $step = $stepOrEnding > 0 ? $stepOrEnding : 1;
            $newPrice = round($newPrice / $step) * $step;
            break;

        case 'ending':
            // Round up to the nearest value ending in the given decimal (e.g. .99, .95).
            $ending = max(0, min(0.99, $stepOrEnding));
            $candidate = floor($newPrice) + $ending;
            if ($candidate < $newPrice) {
                $candidate += 1;
            }
            $newPrice = $candidate;
            break;

        case 'none':
        default:
            $newPrice = round($newPrice, 2);
            break;
    }

    return max(0, round($newPrice, 2));
}

/**
 * Formats a numeric price for WooCommerce (string, up to 2 decimals, no trailing zeros issues).
 */
function format_wc_price(float $price): string
{
    return number_format($price, 2, '.', '');
}
