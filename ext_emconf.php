<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'CB Plotdesk',
    'description' => 'Plotdesk-Integration: Reactions zum Auslesen von Seiteneigenschaften',
    'category' => 'misc',
    'author' => 'Torben Aschmons',
    'author_email' => 'aschmons@cybay.de',
    'author_company' => 'Cybay',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.99.99',
            'reactions' => '13.4.0-13.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
