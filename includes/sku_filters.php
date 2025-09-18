<?php
// Shared SKU filter mapping used by catalogue and header mega menu.
// Keys are the human-readable category labels; values are arrays of SKU prefixes or codes used by catalogue.php.
$skuFilters = [
    'BATHROOM' => ['BAT'],
    'BROOMS AND BRUSHES' => ['BRB'],
    'BUBBLE PRODUCTS' => ['BPA'],
    'CLEANERS AND DEGREASERS' => ['CLD'],
    'CORRUGATED BOXES' => ['BAR','PAD','CLEARANCEBOX050514','SBO','ROL','OPF','SFC','SIZ','SOB'],
    'DEODORIZER' => ['DEO'],
    'DISINFECTANTS' => ['DIS'],
    'FLOOR PRODUCTS' => ['FLO'],
    'FOAM' => ['FOA'],
    'FOODSERVICE' => ['FOO'],
    'GLOVES' => ['GLO'],
    'MAILERS' => ['BMA','MDC','PMA'],
    'MATS' => ['MAT'],
    'OFFICE' => ['OFF'],
    'PACKAGING SUPPLIES' => ['PAC'],
    'PAPER PRODUCTS' => ['PAP'],
    'PEST CONTROL' => ['PCO'],
    'POLY' => ['POS'],
    'POLY BAGS' => ['POL'],
    'RAGS' => ['RAG'],
    'SAFETY EQUIPMENT' => ['SAF'],
    'SOAP AND SANITIZER' => ['SAN'],
    'SPONGES AND SCRUBBERS' => ['SPS'],
    'TAPE' => ['TAP'],
    'TOOLS & EQUIPMENT' => ['TEQ'],
    'TRASH CAN LINERS' => ['LIN']
];

// Group labels into Packaging / Janitorial / Safety using sensible defaults
$skuGroups = [
    'Packaging' => [
        'CORRUGATED BOXES', 'TAPE', 'PACKAGING SUPPLIES', 'PAPER PRODUCTS', 'POLY', 'POLY BAGS', 'MAILERS', 'TRASH CAN LINERS', 'BUBBLE PRODUCTS', 'FOAM'
    ],
    'Janitorial' => [
        'BATHROOM', 'BROOMS AND BRUSHES', 'CLEANERS AND DEGREASERS', 'DEODORIZER', 'DISINFECTANTS', 'FLOOR PRODUCTS', 'MATS', 'SOAP AND SANITIZER', 'SPONGES AND SCRUBBERS', 'RAGS', 'FOODSERVICE'
    ],
    'Safety' => [
        'GLOVES', 'SAFETY EQUIPMENT', 'TOOLS & EQUIPMENT', 'PEST CONTROL'
    ]
];

// Expose $skuFilters and $skuGroups to including files.
return ['filters' => $skuFilters, 'groups' => $skuGroups];
