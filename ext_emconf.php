<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Easy Workspace',
    'description' => 'TYPO3 v14 workspace toolbar item that lets editors publish the current page (or news record) together with its content elements in one click.',
    'category' => 'be',
    'author' => 'Kurt Dirnbauer',
    'author_email' => 'office@webconsulting.at',
    'author_company' => 'webconsulting.at',
    'state' => 'beta',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'workspaces' => '14.3.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
