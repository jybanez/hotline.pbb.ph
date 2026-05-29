<?php

require __DIR__.'/../src/Html.php';
require __DIR__.'/../src/SitrepPayload.php';
require __DIR__.'/../src/SitrepViewOptions.php';
require __DIR__.'/../src/SitrepDocumentRenderer.php';
require __DIR__.'/../src/SitrepViewer.php';

use Pbb\Sitreps\Viewer\SitrepViewer;

$input = __DIR__.'/input/sitrep.json';
$output = __DIR__.'/output/sitrep.html';

$viewer = new SitrepViewer();
$html = $viewer->render((string) file_get_contents($input));

if (! is_dir(dirname($output))) {
    mkdir(dirname($output), 0777, true);
}

file_put_contents($output, $html);

echo "Rendered {$output}".PHP_EOL;
