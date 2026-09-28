<?php

// Unit tests for the pure helper functions in includes/helpers.php.
// These need no database connection — Database::get() is only called lazily
// from inside functions we don't exercise here.

require_once __DIR__ . '/../includes/helpers.php';

// ---- normalize_phone() -----------------------------------------------------

test('normalize_phone keeps a valid 09xxxxxxxxx number', function () {
    assert_same('09123456789', normalize_phone('09123456789'));
});

test('normalize_phone strips spaces and dashes', function () {
    assert_same('09123456789', normalize_phone('0912 345 6789'));
    assert_same('09123456789', normalize_phone('0912-345-6789'));
});

test('normalize_phone converts 0098 prefix to leading 0', function () {
    assert_same('09123456789', normalize_phone('00989123456789'));
});

test('normalize_phone converts 98 prefix to leading 0', function () {
    assert_same('09123456789', normalize_phone('989123456789'));
});

test('normalize_phone adds leading 0 to a bare 9xxxxxxxxx number', function () {
    assert_same('09123456789', normalize_phone('9123456789'));
});

// ---- adjust_price() --------------------------------------------------------

test('adjust_price applies a positive percentage', function () {
    assert_same(110.0, adjust_price(100, 10));
});

test('adjust_price applies a negative percentage', function () {
    assert_same(90.0, adjust_price(100, -10));
});

test('adjust_price never returns a negative price', function () {
    assert_same(0.0, adjust_price(100, -200));
});

test('adjust_price nearest rounds to the nearest integer', function () {
    assert_same(115.0, adjust_price(100, 15.4, 'nearest'));
});

test('adjust_price up rounds up', function () {
    assert_same(101.0, adjust_price(100, 0.1, 'up'));
});

test('adjust_price down rounds down', function () {
    assert_same(100.0, adjust_price(100, 0.9, 'down'));
});

test('adjust_price step rounds to the nearest step', function () {
    // round(102400 / 1000) * 1000 = 102 * 1000
    assert_same(102000.0, adjust_price(102400, 0, 'step', 1000));
    // round(102600 / 1000) * 1000 = 103 * 1000
    assert_same(103000.0, adjust_price(102600, 0, 'step', 1000));
});

test('adjust_price ending forces a .99 ending', function () {
    assert_same(100.99, adjust_price(100, 0, 'ending', 0.99));
});

// ---- format_wc_price() -----------------------------------------------------

test('format_wc_price formats to two decimals', function () {
    assert_same('100.00', format_wc_price(100));
    assert_same('99.90', format_wc_price(99.9));
    assert_same('12.34', format_wc_price(12.344));
    assert_same('100.00', format_wc_price(99.999));
});
