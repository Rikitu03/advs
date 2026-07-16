<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model roster
    |--------------------------------------------------------------------------
    |
    | The five models the pipeline runs, in the order a document meets them.
    | This is the single source of truth for the landing page: the roster
    | section renders one avatar per entry, and the hero chips borrow the same
    | avatar for the model that produced each verdict.
    |
    | `avatar` is the public path to that model's portrait, e.g.
    | 'images/models/resnet50.png'. Leave it null and the roster falls back to a
    | lettered placeholder, so the page stays presentable until the art lands.
    |
    */

    'models' => [
        'tesseract' => [
            'name' => 'Tesseract',
            'role' => 'Reads the text',
            'initials' => 'TS',
            'avatar' => null,
        ],
        'resnet' => [
            'name' => 'ResNet-50',
            'role' => 'Names the document',
            'initials' => 'RN',
            'avatar' => null,
        ],
        'yolo' => [
            'name' => 'YOLOv8',
            'role' => 'Finds the marks',
            'initials' => 'YO',
            'avatar' => null,
        ],
        'siamese' => [
            'name' => 'Siamese CNN',
            'role' => 'Judges the signature',
            'initials' => 'SM',
            'avatar' => null,
        ],
        'efficientnet' => [
            'name' => 'EfficientNet',
            'role' => 'Judges the stamp',
            'initials' => 'EN',
            'avatar' => null,
        ],
    ],

];
