@include('shells.surface', [
    'title' => 'Citizen',
    'surface' => 'citizen',
    'pwaManifest' => '/caller.webmanifest',
    'themeColor' => '#0f766e',
    'vite' => ['resources/css/shared.css', 'resources/css/citizen.css', 'resources/js/entries/citizen.js'],
])
