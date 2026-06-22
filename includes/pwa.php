<?php

/**
 * PWA boilerplate shared across every page: manifest link, theme color,
 * icons, and service worker registration. Call render_pwa_head() inside
 * <head> and render_pwa_register_script() right before </body>.
 */

function render_pwa_head(): void
{
    ?>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#111827">
    <link rel="icon" href="/assets/icons/icon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/assets/icons/icon.svg">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <?php
}

function render_pwa_register_script(): void
{
    ?>
    <script>
      if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
          navigator.serviceWorker.register('/sw.js').catch(() => {
            // Non-fatal: the app works fully without the service worker.
          });
        });
      }
    </script>
    <?php
}
