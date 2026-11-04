<?php

/**
 * Returns the importmap for this application.
 *
 * - "path" is a path inside the asset mapper system. Use the
 *     "debug:asset-map" command to see the full list of paths.
 *
 * - "entrypoint" (JavaScript only) set to true for any module that will
 *     be used as an "entrypoint" (and passed to the importmap() Twig function).
 *
 * The "importmap:require" command can be used to add new entries to this file.
 */
return [
    'app' => [
        'path' => './assets/app.js',
        'entrypoint' => true,
    ],
    '@hotwired/stimulus' => [
        'version' => '3.2.2',
    ],
    '@symfony/stimulus-bundle' => [
        'path' => './vendor/symfony/stimulus-bundle/assets/dist/loader.js',
    ],
    'jquery' => [
        'version' => '3.7.1',
    ],
    'select2/dist/js/select2.min.js' => [
        'version' => '4.1.0-rc.0',
    ],
    'select2/dist/css/select2.min.css' => [
        'version' => '4.1.0-rc.0',
        'type' => 'css',
    ],
    'notyf' => [
        'version' => '3.10.0',
    ],
    'notyf/notyf.min.css' => [
        'version' => '3.10.0',
        'type' => 'css',
    ],
    'sweetalert2' => [
        'version' => '11.26.25',
    ],
    'sweetalert2/dist/sweetalert2.min.css' => [
        'version' => '11.26.25',
        'type' => 'css',
    ],
    'sortablejs' => [
        'version' => '1.15.7',
    ],
];
