// Service Worker for Restaurant Reservation PWA
const CACHE_NAME = "restaurant-reservation-v1";

// Install event - cache essential resources
self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CACHE_URLS))
  );
  self.skipWaiting();
});

// Activate event - clean up old caches
self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((cacheNames) => {
      return Promise.all(
        cacheNames.map((cacheName) => {
          if (cacheName !== CACHE_NAME) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});

// Fetch event - serve from cache when offline
self.addEventListener("fetch", (event) => {
  event.respondWith(
    caches.match(event.request).then((response) => {
      // Return cached version or fetch from network
      return response || fetch(event.request);
    })
  );
});

// Handle background sync for offline reservations (future enhancement)
/*
self.addEventListener('sync', event => {
    if (event.tag === 'background-reservation-sync') {
        event.waitUntil(syncReservations());
    }
});

function syncReservations() {
    // Sync offline reservations when connection restored
    return getPendingReservations().then(reservations => {
        return Promise.all(
            reservations.map(reservation => syncReservation(reservation))
        );
    });
}
*/
