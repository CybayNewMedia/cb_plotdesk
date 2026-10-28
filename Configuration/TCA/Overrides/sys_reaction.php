<?php

defined('TYPO3') or die();

use Cybay\CbPlotdesk\Reaction\PageSeoExportReaction;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addTcaSelectItem(
    'sys_reaction',
    'reaction_type',
    [
        'label' => PageSeoExportReaction::getDescription(),
        'value' => PageSeoExportReaction::getType(),
        'icon' => PageSeoExportReaction::getIconIdentifier(),
    ]
);
