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
          navigator.serviceWorker.register('/sw.js').then((reg) => {
            // Browsers only re-check the SW file on navigation; a PWA left
            // open in standalone mode can sit on a stale worker for days
            // otherwise, so poll for a new one every 30 minutes too.
            setInterval(() => reg.update(), 30 * 60 * 1000);

            reg.addEventListener('updatefound', () => {
              const installing = reg.installing;
              if (!installing) return;
              installing.addEventListener('statechange', () => {
                // A controller already existing means this is an update,
                // not the very first install - only prompt in that case.
                if (installing.state === 'installed' && navigator.serviceWorker.controller) {
                  showUpdateAvailableNotice();
                }
              });
            });
          }).catch(() => {
            // Non-fatal: the app works fully without the service worker.
          });
        });
      }

      function showUpdateAvailableNotice() {
        let container = document.querySelector('.notif');
        if (!container) {
          container = document.createElement('div');
          container.className = 'notif';
          document.body.appendChild(container);
        }

        const isFa = document.documentElement.lang === 'fa';
        const item = document.createElement('div');
        item.className = 'notif-item';
        item.innerHTML = `
          <div class="notif-text">${isFa ? 'نسخه جدید موجود است' : 'A new version is available'}</div>
          <button class="notif-undo">${isFa ? 'به‌روزرسانی' : 'Refresh'}</button>
        `;
        item.querySelector('button').addEventListener('click', () => window.location.reload());
        container.appendChild(item);
      }
    </script>
    <?php
}
