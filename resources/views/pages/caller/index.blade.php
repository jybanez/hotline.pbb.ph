@include('shells.surface', [
    'title' => 'Caller',
    'surface' => 'caller',
    'pwaManifest' => '/caller.webmanifest',
    'themeColor' => '#0f766e',
    'vite' => ['resources/css/shared.css', 'resources/css/caller.css', 'resources/js/entries/caller.js'],
])
