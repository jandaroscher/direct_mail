<?php

declare(strict_types=1);

namespace DirectMailTeam\DirectMail\Module;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use DirectMailTeam\DirectMail\Event\ImporterOutputEvent;
use DirectMailTeam\DirectMail\Repository\PagesRepository;
use DirectMailTeam\DirectMail\Repository\SysDmailCategoryRepository;
use DirectMailTeam\DirectMail\Repository\SysDmailTtAddressCategoryMmRepository;
use DirectMailTeam\DirectMail\Repository\TtAddressRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;

/**
 * Recipient list module for tx_directmail extension
 *
 * @author		Ivan-Dharma Kartolo	<ivan.kartolo@dkd.de>
 */
final class ImporterController extends MainController
{
    protected FlashMessageQueue $flashMessageQueue;

    public function __construct(
        protected readonly ModuleTemplateFactory $moduleTemplateFactory,
        protected readonly EventDispatcherInterface $eventDispatcher,

        protected readonly string $moduleName = 'directmail_module_importer',
        protected readonly string $lllFile = 'LLL:EXT:direct_mail/Resources/Private/Language/locallang_mod2-6.xlf',

        protected ?LanguageService $languageService = null,
        protected ?ServerRequestInterface $request = null,
        protected ?BackendUserAuthentication $beUser = null,

        protected array $queryParams = [],

        protected string $httpReferer = '',
        protected string $requestHostOnly = '',
        protected array $importStep = [],
        protected array $csvImport = [],
        protected array $csvFile = [],
        protected array $indata = []
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->languageService = $this->getLanguageService();
        $this->flashMessageQueue = $this->getFlashMessageQueue('RecipientListQueue');

        $this->request = $request;
        $this->queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();

        $this->id = (int)($parsedBody['id'] ?? $this->queryParams['id'] ?? 0);
        $permsClause = $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW);
        $pageAccess = BackendUtility::readPageAccess($this->id, $permsClause);
        $this->pageinfo = is_array($pageAccess) ? $pageAccess : [];
        $this->access = is_array($this->pageinfo) ? true : false;

        $this->beUser = $this->getBackendUser();

        $this->httpReferer = $request->getServerParams()['HTTP_REFERER'];

        $normalizedParams = $request->getAttribute('normalizedParams');
        $this->requestHostOnly = $normalizedParams->getRequestHostOnly();

        // get some importer default from pageTS
        $this->params = BackendUtility::getPagesTSconfig($this->id)['mod.']['web_modules.']['dmail.']['importer.'] ?? [];
        $this->importStep = is_array($parsedBody['importStep'] ?? '') ? $parsedBody['importStep'] : [];
        $this->csvImport = is_array($parsedBody['CSV_IMPORT'] ?? '') ? $parsedBody['CSV_IMPORT'] : [];
        $this->csvFile = is_array($parsedBody['file'] ?? '') ? $parsedBody['file'] : [];

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        return $this->indexAction($moduleTemplate);
    }

    public function indexAction(ModuleTemplate $view): ResponseInterface
    {
        if (($this->id && $this->access) || ($this->isAdmin() && !$this->id)) {

            $module = $this->getModulName();

            if ($module == 'dmail') {
                // Direct mail module
                if (($this->pageinfo['doktype'] ?? 0) == 254) {
                    $data = $this->moduleContent();
                    $view->assignMultiple(
                        [
                            'formcontent' => $data,
                            'show' => true,
                        ]
                    );
                } elseif ($this->id != 0) {
                    $message = $this->createFlashMessage(
                        $this->languageService->sL($this->lllFile . ':dmail_noRegular'),
                        $this->languageService->sL($this->lllFile . ':dmail_newsletters'),
                        ContextualFeedbackSeverity::WARNING,
                        false
                    );
                    $this->flashMessageQueue->addMessage($message);
                }
            } else {
                $message = $this->createFlashMessage(
                    $this->languageService->sL($this->lllFile . ':select_folder'),
                    $this->languageService->sL($this->lllFile . ':header_recip'),
                    ContextualFeedbackSeverity::WARNING,
                    false
                );
                $this->flashMessageQueue->addMessage($message);
                $view->assignMultiple(
                    [
                        'dmLinks' => $this->getDMPages($this->moduleName),
                    ]
                );
            }
        } else {
            $message = $this->createFlashMessage(
                $this->languageService->sL($this->lllFile . ':mod.main.no_access'),
                $this->languageService->sL($this->lllFile . ':mod.main.no_access.title'),
                ContextualFeedbackSeverity::WARNING,
                false
            );
            $this->flashMessageQueue->addMessage($message);
            return $view->renderResponse('NoAccess');
        }

        return $view->renderResponse('Importer');
    }

    /**
     * Import CSV-Data in step-by-step mode
     *
     * @return	array		HTML form
     */
    protected function moduleContent(): array
    {
        $output = [
            'title' => '',
            'subtitle' => '',
            'upload' => [
                'show' => false,
                'current' => false,
                'fileInfo' => [],
                'csv' => '',
                'target' => '',
                'newFile' => '',
                'newFileUid' => 0,
            ],
            'conf' => [
                'show' => false,
                'remove_existing' => false,
                'first_fieldname' => false,
                'delimiter' => '',
                'delimiterSelected' => '',
                'encapsulation' => '',
                'valid_email' => false,
                'remove_dublette' => false,
                'update_unique' => false,
                'record_unique' => '',
                'newFile' => '',
                'newFileUid' => 0,
                'disableInput' => false,
            ],
            'mapping' => [
                'show' => false,
                'charset' => '',
                'charsetSelected' => '',
                'newFile' => '',
                'newFileUid' => 0,
                'storage' => '',
                'remove_existing' => false,
                'first_fieldname' => false,
                'delimiter' => '',
                'encapsulation' => '',
                'valid_email' => false,
                'remove_dublette' => false,
                'update_unique' => false,
                'record_unique' => '',
                'all_html' => false,
                'mapping_cats' => [],
                'show_add_cat' => false,
                'add_cat' => false,
                'error' => [],
                'table' => [],
                'fields' => [],
            ],
            'startImport' => [
                'show' => false,
                'charset' => '',
                'charsetSelected' => '',
                'newFile' => '',
                'newFileUid' => 0,
                'storage' => '',
                'remove_existing' => false,
                'first_fieldname' => false,
                'delimiter' => '',
                'encapsulation' => '',
                'valid_email' => false,
                'remove_dublette' => false,
                'update_unique' => false,
                'record_unique' => '',
                'all_html' => false,
                'hiddenMap' => [],
                'hiddenCat' => [],
                'add_cat' => false,
                'tables' => [],
            ],
        ];

        $step = $this->importStep;
        $defaultConf = [
            'remove_existing' => 0,
            'first_fieldname' => 0,
            'valid_email' => 0,
            'remove_dublette' => 0,
            'update_unique' => 0,
        ];

        if (!empty($this->csvImport)) {
            $this->indata = ($step['next'] === 'mapping') ? ($this->csvImport + $defaultConf) : $this->csvImport;
        }

        if (empty($this->indata)) {
            $this->indata = [];
        }

        if (empty($this->params)) {
            $this->params = [];
        }
        // merge it with inData, but inData has priority.
        $this->indata += $this->params;
        if (empty($this->indata['csv']) && !empty($_FILES['upload_1']['name'])) {
            $tempFile = $this->checkUpload();
            $this->indata['newFile'] = $tempFile['newFile'];
            $this->indata['newFileUid'] = $tempFile['newFileUid'];
        } elseif (!empty($this->indata['csv']) && empty($_FILES['upload_1']['name'])) {
            unset($this->indata['newFile']);
            unset($this->indata['newFileUid']);
        }

        $stepCurrent = '';
        if ($this->indata['back'] ?? false) {
            $stepCurrent = $step['back'];
        } elseif ($this->indata['next'] ?? false) {
            $stepCurrent = $step['next'];
        } elseif ($this->indata['update'] ?? false) {
            $stepCurrent = 'mapping';
        }

        if (strlen($this->indata['csv'] ?? '') > 0) {
            $this->indata['mode'] = 'csv';
            $tempFile = $this->writeTempFile(
                $this->indata['csv'] ?? '',
                $this->indata['newFile'] ?? '',
                (int)($this->indata['newFileUid'] ?? 0)
            );
            $this->indata['newFile'] = $tempFile['newFile'];
            $this->indata['newFileUid'] = $tempFile['newFileUid'];
        } elseif (!empty($this->indata['newFile'])) {
            $this->indata['mode'] = 'file';
        } else {
            $stepCurrent = '';
        }

        // check if "email" is mapped
        $error = [];
        if (isset($stepCurrent) && $stepCurrent === 'startImport') {
            $map = $this->indata['map'];
            // check noMap
            $newMap = ArrayUtility::removeArrayEntryByValue(array_unique($map), 'noMap');
            if (empty($newMap)) {
                $error[] = 'noMap';
            } elseif (!in_array('email', $map)) {
                $error[] = 'email';
            }
            if (count($error)) {
                $stepCurrent = 'mapping';
            }
        }

        switch ($stepCurrent) {
            case 'conf':
                $output['conf']['show'] = true;
                $output['conf']['newFile'] = $this->indata['newFile'];
                $output['conf']['newFileUid'] = $this->indata['newFileUid'];

                $pagePermsClause3 = $this->beUser->getPagePermsClause(3);
                $pagePermsClause1 = $this->beUser->getPagePermsClause(1);
                // get list of sysfolder
                // TODO: maybe only subtree von this->id??

                $optStorage = [];
                $subfolders = GeneralUtility::makeInstance(PagesRepository::class)->selectSubfolders($pagePermsClause3);
                if ($subfolders && count($subfolders)) {
                    foreach ($subfolders as $subfolder) {
                        if (BackendUtility::readPageAccess($subfolder['uid'], $pagePermsClause1)) {
                            $optStorage[] = [
                                'val' => $subfolder['uid'],
                                'text' => $subfolder['title'] . ' [uid:' . $subfolder['uid'] . ']',
                            ];
                        }
                    }
                }

                $optDelimiter = [
                    ['val' => 'comma', 'text' => $this->languageService->sL($this->lllFile . ':mailgroup_import_separator_comma')],
                    ['val' => 'semicolon', 'text' => $this->languageService->sL($this->lllFile . ':mailgroup_import_separator_semicolon')],
                    ['val' => 'colon', 'text' => $this->languageService->sL($this->lllFile . ':mailgroup_import_separator_colon')],
                    ['val' => 'tab', 'text' => $this->languageService->sL($this->lllFile . ':mailgroup_import_separator_tab')],
                ];

                $optEncap = [
                    ['val' => 'doubleQuote', 'text' => ' " '],
                    ['val' => 'singleQuote', 'text' => " ' "],
                ];

                // TODO: make it variable?
                $optUnique = [
                    ['val' => 'email', 'text' => 'email'],
                    ['val' =>'name', 'text' => 'name'],
                ];

                $output['conf']['disableInput'] = ($this->params['inputDisable'] ?? 0) == 1 ? true : false;

                // show configuration
                $output['subtitle'] = $this->languageService->sL($this->lllFile . ':mailgroup_import_header_conf');

                // get the all sysfolder
                $output['conf']['storage'] = $optStorage;
                $output['conf']['storageSelected'] = $this->indata['storage'] ?? '';

                // remove existing option
                $output['conf']['remove_existing'] = !($this->indata['remove_existing'] ?? false) ? false : true;

                // first line in csv is to be ignored
                $output['conf']['first_fieldname'] = !($this->indata['first_fieldname'] ?? false) ? false : true;

                // csv separator
                $output['conf']['delimiter'] = $optDelimiter;
                $output['conf']['delimiterSelected'] = $this->indata['delimiter'] ?? '';

                // csv encapsulation
                $output['conf']['encapsulation'] = $optEncap;
                $output['conf']['encapsulationSelected'] = $this->indata['encapsulation'] ?? '';

                // import only valid email
                $output['conf']['valid_email'] = !($this->indata['valid_email'] ?? false) ? false : true;

                // only import distinct records
                $output['conf']['remove_dublette'] = !($this->indata['remove_dublette'] ?? false) ? false : true;

                // update the record instead renaming the new one
                $output['conf']['update_unique'] = !($this->indata['update_unique'] ?? false) ? false : true;

                // which field should be use to show uniqueness of the records
                $output['conf']['record_unique'] = $optUnique;
                $output['conf']['record_uniqueSelected'] = $this->indata['record_unique'] ?? '';

                break;

            case 'mapping':
                $output['mapping']['show'] = true;
                $output['mapping']['newFile'] = $this->indata['newFile'];
                $output['mapping']['newFileUid'] = $this->indata['newFileUid'];
                $output['mapping']['storage'] = $this->indata['storage'];
                $output['mapping']['remove_existing'] = $this->indata['remove_existing'];
                $output['mapping']['first_fieldname'] = $this->indata['first_fieldname'];
                $output['mapping']['delimiter'] = $this->indata['delimiter'];
                $output['mapping']['encapsulation'] = $this->indata['encapsulation'];
                $output['mapping']['valid_email'] = $this->indata['valid_email'];
                $output['mapping']['remove_dublette'] = $this->indata['remove_dublette'];
                $output['mapping']['update_unique'] = $this->indata['update_unique'];
                $output['mapping']['record_unique'] = $this->indata['record_unique'];
                $output['mapping']['all_html'] = !($this->indata['all_html'] ?? false) ? false : true;
                $output['mapping']['error'] = $error;

                // show charset selector
                $cs = array_unique(array_values(mb_list_encodings()));
                $charSets = [];
                foreach ($cs as $charset) {
                    $charSets[] = ['val' => $charset, 'text' => $charset];
                }

                if (!isset($this->indata['charset'])) {
                    $this->indata['charset'] = 'ISO-8859-1';
                }
                $output['subtitle'] = $this->languageService->sL($this->lllFile . ':mailgroup_import_mapping_charset');

                $output['mapping']['charset'] = $charSets;
                $output['mapping']['charsetSelected'] = $this->indata['charset'];

                $csv_firstRow = [];
                // show mapping form
                if ($this->indata['first_fieldname']) {
                    // read csv
                    $csvData = $this->readExampleCSV(4);
                    $csv_firstRow = $csvData[0];
                    $csvData = array_slice($csvData, 1);
                } else {
                    // read csv
                    $csvData = $this->readExampleCSV(3);
                    $fieldsAmount = count($csvData[0] ?? []);
                    for ($i = 0; $i < $fieldsAmount; $i++) {
                        $csv_firstRow[] = 'field_' . $i;
                    }
                }

                // read tt_address TCA
                $no_map = ['image', 'sys_language_uid', 'l10n_parent', 'l10n_diffsource', 't3_origuid', 'crdate', 'tstamp'];
                $ttAddressFields = array_keys($GLOBALS['TCA']['tt_address']['columns']);
                foreach ($no_map as $v) {
                    $ttAddressFields = ArrayUtility::removeArrayEntryByValue($ttAddressFields, $v);
                }
                $mapFields = [];
                foreach ($ttAddressFields as $map) {
                    $mapFields[] = [
                        $map,
                        str_replace(':', '', $this->languageService->sL($GLOBALS['TCA']['tt_address']['columns'][$map]['label'])),
                    ];
                }
                // add 'no value'
                array_unshift($mapFields, ['noMap', $this->languageService->sL($this->lllFile . ':mailgroup_import_mapping_mapTo')]);
                $mapFields[] = [
                    'cats',
                    $this->languageService->sL($this->lllFile . ':mailgroup_import_mapping_categories')
                ];
                reset($csv_firstRow);
                reset($csvData);

                $output['mapping']['fields'] = $mapFields;
                for ($i = 0; $i < (count($csv_firstRow)); $i++) {
                    // example CSV
                    $exampleLines = [];
                    for ($j = 0; $j < (count($csvData)); $j++) {
                        $exampleLines[] = $csvData[$j][$i];
                    }
                    $output['mapping']['table'][] = [
                        'mapping_description' => $csv_firstRow[$i],
                        'mapping_i' => $i,
                        'mapping_mappingSelected' => $this->indata['map'][$i] ?? '',
                        'mapping_value' => $exampleLines,
                    ];
                }

                // get categories
                $temp['value'] = BackendUtility::getPagesTSconfig($this->getId())['TCEFORM.']['sys_dmail_group.']['select_categories.']['PAGE_TSCONFIG_IDLIST'] ?? null;
                if (is_numeric($temp['value'])) {
                    $rowCat = GeneralUtility::makeInstance(SysDmailCategoryRepository::class)->selectSysDmailCategoryByPid((int)$temp['value']);
                    if (!empty($rowCat)) {
                        // additional options
                        if ($output['mapping']['update_unique']) {
                            $output['mapping']['show_add_cat'] = true;
                            $output['mapping']['add_cat'] = $this->indata['add_cat'] ? true : false;
                        }
                        foreach ($rowCat as $k => $v) {
                            $output['mapping']['mapping_cats'][] = [
                                'cat' => htmlspecialchars($v['category']),
                                'k' => $k,
                                'vUid' => $v['uid'],
                                'checked' => $this->indata['cat'][$k] != $v['uid'] ? false : true,
                            ];
                        }
                    }
                }
                break;
            case 'startImport':
                $output['startImport']['show'] = true;

                $output['startImport']['charsetSelected'] = $this->indata['charset'];
                $output['startImport']['newFile'] = $this->indata['newFile'];
                $output['startImport']['newFileUid'] = $this->indata['newFileUid'];
                $output['startImport']['storage'] = $this->indata['storage'];
                $output['startImport']['remove_existing'] = $this->indata['remove_existing'];
                $output['startImport']['first_fieldname'] = $this->indata['first_fieldname'];
                $output['startImport']['delimiter'] = $this->indata['delimiter'];
                $output['startImport']['encapsulation'] = $this->indata['encapsulation'];
                $output['startImport']['valid_email'] = $this->indata['valid_email'];
                $output['startImport']['remove_dublette'] = $this->indata['remove_dublette'];
                $output['startImport']['update_unique'] = $this->indata['update_unique'];
                $output['startImport']['record_unique'] = $this->indata['record_unique'];
                $output['startImport']['all_html'] = !($this->indata['all_html'] ?? false) ? false : true;
                $output['startImport']['add_cat'] = ($this->indata['add_cat'] ?? false) ? true : false;

                $output['startImport']['error'] = $error;

                // starting import & show errors
                // read csv
                $csvData = $this->readCSV();
                if ($this->indata['first_fieldname']) {
                    $csvData = array_slice($csvData, 1);
                }

                // show not imported record and reasons,
                $result = $this->doImport($csvData);
                $output['subtitle'] = $this->languageService->sL($this->lllFile . ':mailgroup_import_done');

                $resultOrder = [];
                if (!empty($this->params['resultOrder'])) {
                    $resultOrder = GeneralUtility::trimExplode(',', $this->params['resultOrder']);
                }

                $defaultOrder = ['new', 'update', 'invalid_email', 'double'];
                $diffOrder = array_diff($defaultOrder, $resultOrder);
                $endOrder = array_merge($resultOrder, $diffOrder);

                foreach ($endOrder as $order) {
                    $rowsTable = [];
                    if (is_array($result[$order] ?? false)) {
                        foreach ($result[$order] as $v) {
                            $mapKeys = array_keys($v);
                            $rowsTable[] = [
                                'val' => $v[$mapKeys[0]],
                                'email' => $v['email'],
                            ];
                        }
                    }

                    $output['startImport']['tables'][] = [
                        'header' => $this->languageService->sL($this->lllFile . ':mailgroup_import_report_' . $order),
                        'rows' => $rowsTable,
                    ];
                }

                // back button
                if (is_array($this->indata['map'] ?? false)) {
                    foreach ($this->indata['map'] as $fieldNr => $fieldMapped) {
                        $output['startImport']['hiddenMap'][] = [
                            'name' => htmlspecialchars('CSV_IMPORT[map][' . $fieldNr . ']'),
                            'value' => htmlspecialchars($fieldMapped)
                        ];
                    }
                }
                if (is_array($this->indata['cat'] ?? false)) {
                    foreach ($this->indata['cat'] as $k => $catUid) {
                        $output['startImport']['hiddenCat'][] = [
                            'name' => htmlspecialchars('CSV_IMPORT[cat][' . $k . ']'),
                            'value' => htmlspecialchars($catUid)
                        ];
                    }
                }
                break;

            case 'upload':
            default:
                // show upload file form
                $output['subtitle'] = $this->languageService->sL($this->lllFile . ':mailgroup_import_header_upload');

                if (($this->indata['mode'] ?? '') === 'file') {
                    $output['upload']['current'] = true;
                    $file = $this->getFileById((int)$this->indata['newFileUid']);
                    if (is_object($file)) {
                        $output['upload']['fileInfo'] = [
                            'name' => $file->getName(),
                            'extension' => $file->getProperty('extension'),
                            'size' => trim(GeneralUtility::formatSize($file->getProperty('size'))),
                        ];
                    }
                }

                $output['upload']['show'] = true;
                $output['upload']['csv'] = htmlspecialchars($this->indata['csv'] ?? '');
                $output['upload']['target'] = htmlspecialchars($this->userTempFolder());
                $output['upload']['newFile'] = $this->indata['newFile'] ?? '';
                $output['upload']['newFileUid'] = $this->indata['newFileUid'] ?? 0;
        }

        $output['title'] = $this->languageService->sL($this->lllFile . ':mailgroup_import');

         /** @var ImporterOutputEvent $event */
         $event = $this->eventDispatcher->dispatch(
            new ImporterOutputEvent($output)
        );
        $output = $event->getOutput();

        return ['output' => $output];
    }

    /*****
     * function for importing tt_address
     *****/

    /**
     * Filter doublette from input csv data
     *
     * @param array $mappedCsv Mapped csv
     *
     * @return array Filtered csv and double csv
     */
    public function filterCSV(array $mappedCsv): array
    {
        $cmpCsv = $mappedCsv;
        $remove = [];
        $filtered = [];
        $double = [];

        foreach ($mappedCsv as $k => $csvData) {
            if (!in_array($k, $remove)) {
                $found = 0;
                foreach ($cmpCsv as $kk =>$cmpData) {
                    if ($k != $kk) {
                        if ($csvData[$this->indata['record_unique']] == $cmpData[$this->indata['record_unique']]) {
                            $double[] = $mappedCsv[$kk];
                            if (!$found) {
                                $filtered[] = $csvData;
                            }
                            $remove[] = $kk;
                            $found = 1;
                        }
                    }
                }
                if (!$found) {
                    $filtered[] = $csvData;
                }
            }
        }
        $csv['clean'] = $filtered;
        $csv['double'] = $double;

        return $csv;
    }

    /**
     * Start importing users
     *
     * @param array $csvData The csv raw data
     *
     * @return array Array containing doublette, updated and invalid-email records
     */
    public function doImport(array $csvData): array
    {
        $resultImport = [];
        $filteredCSV = [];

        //empty table if flag is set
        if ($this->indata['remove_existing']) {
            GeneralUtility::makeInstance(TtAddressRepository::class)->deleteRowsByPid((int)$this->indata['storage']);
        }

        $mappedCSV = [];
        $invalidEmailCSV = [];
        foreach ($csvData as $dataArray) {
            $tempData = [];
            $invalidEmail = 0;
            foreach ($dataArray as $kk => $fieldData) {
                if ($this->indata['map'][$kk] !== 'noMap') {
                    if (($this->indata['valid_email']) && ($this->indata['map'][$kk] === 'email')) {
                        $invalidEmail = GeneralUtility::validEmail(trim($fieldData)) ? 0 : 1;
                        $tempData[$this->indata['map'][$kk]] = trim($fieldData);
                    } else {
                        if ($this->indata['map'][$kk] !== 'cats') {
                            $tempData[$this->indata['map'][$kk]] = $fieldData;
                        } else {
                            $tempCats = explode(',', $fieldData);
                            foreach ($tempCats as $catC => $tempCat) {
                                $tempData['module_sys_dmail_category'][$catC] = $tempCat;
                            }
                        }
                    }
                }
            }
            if ($invalidEmail) {
                $invalidEmailCSV[] = $tempData;
            } else {
                $mappedCSV[] = $tempData;
            }
        }

        // remove doublette from csv data
        if ($this->indata['remove_dublette']) {
            $filteredCSV = $this->filterCSV($mappedCSV);
            unset($mappedCSV);
            $mappedCSV = $filteredCSV['clean'];
        }

        // array for the process_datamap();
        $data = [];
        if ($this->indata['update_unique']) {
            $user = [];
            $userID = [];

            $rows = GeneralUtility::makeInstance(TtAddressRepository::class)->selectTtAddressByPid(
                (int)$this->indata['storage'],
                $this->indata['record_unique']
            );

            if (is_array($rows)) {
                foreach ($rows as $row) {
                    $user[] = $row['email'];
                    $userID[] = $row['uid'];
                }
            }

            // check user one by one, new or update
            $c = 1;
            foreach ($mappedCSV as $dataArray) {
                $foundUser = array_keys($user, $dataArray[$this->indata['record_unique']]);
                if (is_array($foundUser) && !empty($foundUser)) {
                    if (count($foundUser) == 1) {
                        $data['tt_address'][$userID[$foundUser[0]]] =  $dataArray;
                        $data['tt_address'][$userID[$foundUser[0]]]['pid'] = $this->indata['storage'];
                        if ($this->indata['all_html']) {
                            $data['tt_address'][$userID[$foundUser[0]]]['module_sys_dmail_html'] = $this->indata['all_html'];
                        }
                        if (is_array($this->indata['cat'] ?? false) && !in_array('cats', $this->indata['map'])) {
                            if ($this->indata['add_cat']) {
                                $rows = GeneralUtility::makeInstance(SysDmailTtAddressCategoryMmRepository::class)->selectUidsByUidLocal((int)$userID[$foundUser[0]]);
                                if (is_array($rows)) {
                                    foreach ($rows as $row) {
                                        $data['tt_address'][$userID[$foundUser[0]]]['module_sys_dmail_category'][] = $row['uid_foreign'];
                                    }
                                }
                            }
                            // Add categories
                            foreach ($this->indata['cat'] as $v) {
                                $data['tt_address'][$userID[$foundUser[0]]]['module_sys_dmail_category'][] = $v;
                            }
                        }
                        $resultImport['update'][] = $dataArray;
                    } else {
                        // which one to update? all?
                        foreach ($foundUser as $kk => $_) {
                            $data['tt_address'][$userID[$foundUser[$kk]]] = $dataArray;
                            $data['tt_address'][$userID[$foundUser[$kk]]]['pid'] = $this->indata['storage'];
                        }
                        $resultImport['update'][] = $dataArray;
                    }
                } else {
                    // write new user
                    $this->addDataArray($data, 'NEW' . $c, $dataArray);
                    $resultImport['new'][] = $dataArray;
                    // counter
                    $c++;
                }
            }
        } else {
            // no update, import all
            $c = 1;
            foreach ($mappedCSV as $dataArray) {
                $this->addDataArray($data, 'NEW' . $c, $dataArray);
                $resultImport['new'][] = $dataArray;
                $c++;
            }
        }

        $resultImport['invalid_email'] = $invalidEmailCSV;
        $resultImport['double'] = is_array($filteredCSV['double'] ?? false) ? $filteredCSV['double'] : [];

        // start importing
        /* @var $dataHandler DataHandler */
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        //$dataHandler->enableLogging = 0;
        $dataHandler->start($data, []);
        $dataHandler->process_datamap();

        if ($dataHandler->errorLog !== []) {
            $logsStr = '';
            foreach ($dataHandler->errorLog as $log) {
                $logsStr .= $log . PHP_EOL;
            }
            $message = $this->createFlashMessage($logsStr, 'Import errors', ContextualFeedbackSeverity::ERROR, false);
            $this->flashMessageQueue->addMessage($message);
        }
        /**
         * Hook for doImport Mail
         * will be called every time a record is inserted
         */
        if (is_array($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail/mod3/class.tx_directmail_recipient_list.php']['doImport'] ?? false)) {
            $hookObjectsArr = [];
            foreach ($GLOBALS['TYPO3_CONF_VARS']['EXTCONF']['direct_mail/mod3/class.tx_directmail_recipient_list.php']['doImport'] as $classRef) {
                $hookObjectsArr[] = GeneralUtility::makeInstance($classRef);
            }

            foreach ($hookObjectsArr as $hookObj) {
                if (method_exists($hookObj, 'doImport')) {
                    $hookObj->doImport($this, $data, $c);
                }
            }
        }

        return $resultImport;
    }

    /**
     * Prepare insert array for the TCE
     *
     * @param array $data Array for the TCE
     * @param string $id Record ID
     * @param array $dataArray The data to be imported
     */
    public function addDataArray(array &$data, string $id, array $dataArray): void
    {
        $data['tt_address'][$id] = $dataArray;
        $data['tt_address'][$id]['pid'] = $this->indata['storage'];
        if ($this->indata['all_html']) {
            $data['tt_address'][$id]['module_sys_dmail_html'] = $this->indata['all_html'];
        }
        if (is_array($this->indata['cat'] ?? false) && !in_array('cats', $this->indata['map'])) {
            foreach ($this->indata['cat'] as $k => $v) {
                $data['tt_address'][$id]['module_sys_dmail_category'][$k] = $v;
            }
        }
    }

    /**
     * Read in the given CSV file. The function is used during the final file import.
     * Removes first the first data row if the CSV has fieldnames.
     *
     * @return	array		file content in array
     */
    public function readCSV(): array
    {
        $mydata = [];

        if ((int)$this->indata['newFileUid'] < 1) {
            return $mydata;
        }

        $fileAbsolutePath = $this->getFileAbsolutePath((int)$this->indata['newFileUid']);

        $delimiter = $this->indata['delimiter'] ?: 'comma';
        $encaps = $this->indata['encapsulation'] ?: 'doubleQuote';
        $delimiter = ($delimiter === 'comma') ? ',' : $delimiter;
        $delimiter = ($delimiter === 'semicolon') ? ';' : $delimiter;
        $delimiter = ($delimiter === 'colon') ? ':' : $delimiter;
        $delimiter = ($delimiter === 'tab') ? "\t" : $delimiter;
        $encaps = ($encaps === 'singleQuote') ? "'" : $encaps;
        $encaps = ($encaps === 'doubleQuote') ? '"' : $encaps;

        $handle = fopen($fileAbsolutePath, 'r');
        if ($handle === false) {
            return $mydata;
        }

        while (($data = fgetcsv($handle, 10000, $delimiter, $encaps)) !== false) {
            // remove empty line in csv
            if ((count($data) >= 1)) {
                $mydata[] = $data;
            }
        }
        fclose($handle);
        reset($mydata);
        $mydata = $this->convCharset($mydata);
        return $mydata;
    }

    /**
     * Read in the given CSV file. Only showed a couple of the CSV values as example
     * Removes first the first data row if the CSV has fieldnames.
     *
     * @param	int $records Number of example values
     *
     * @return	array File content in array
     */
    public function readExampleCSV(int $records = 3): array
    {
        $mydata = [];

        if ((int)$this->indata['newFileUid'] < 1) {
            return $mydata;
        }

        $fileAbsolutePath = $this->getFileAbsolutePath((int)$this->indata['newFileUid']);

        $i = 0;
        $delimiter = $this->indata['delimiter'] ?: 'comma';
        $encaps = $this->indata['encapsulation'] ?: 'doubleQuote';
        $delimiter = ($delimiter === 'comma') ? ',' : $delimiter;
        $delimiter = ($delimiter === 'semicolon') ? ';' : $delimiter;
        $delimiter = ($delimiter === 'colon') ? ':' : $delimiter;
        $delimiter = ($delimiter === 'tab') ? "\t" : $delimiter;
        $encaps = ($encaps === 'singleQuote') ? "'" : $encaps;
        $encaps = ($encaps === 'doubleQuote') ? '"' : $encaps;

        $handle = fopen($fileAbsolutePath, 'r');
        if ($handle === false) {
            return $mydata;
        }

        while ((($data = fgetcsv($handle, 10000, $delimiter, $encaps)) !== false)) {
            // remove empty line in csv
            if ((count($data) >= 1)) {
                $mydata[] = $data;
                $i++;
                if ($i >= $records) {
                    break;
                }
            }
        }
        fclose($handle);
        reset($mydata);
        $mydata = $this->convCharset($mydata);
        return $mydata;
    }

    /**
     * Convert charset if necessary
     *
     * @param array $data Contains values to convert
     *
     * @return	array	array of charset-converted values
     * @see \TYPO3\CMS\Core\Charset\CharsetConverter::conv[]
     */
    public function convCharset(array $data): array
    {
        $dbCharset = 'utf-8';
        if ($dbCharset != $this->indata['charset']) {
            $converter = GeneralUtility::makeInstance(CharsetConverter::class);
            foreach ($data as $k => $v) {
                if(is_array($v)) {
                    foreach($v as $k2 => $val) {
                        $data[$k][$k2] = $converter->conv($val, strtolower($this->indata['charset']), $dbCharset);
                    }
                }
                else {
                    $data[$k] = $converter->conv($v, strtolower($this->indata['charset']), $dbCharset);
                }
            }
        }
        return $data;
    }

    /**
     * Write CSV Data to a temporary file and will be used for the import
     *
     * @return	array		path and uid of the temp file
     */
    public function writeTempFile(string $csv, string $newFile, int $newFileUid): array
    {
        $newfile = ['newFile' => '', 'newFileUid' => 0];

        $userPermissions = $this->beUser->getFilePermissions();
        // Initializing:
        /* @var $extendedFileUtility ExtendedFileUtility */
        $extendedFileUtility = GeneralUtility::makeInstance(ExtendedFileUtility::class);
        $extendedFileUtility->setActionPermissions($userPermissions);
        // https://docs.typo3.org/c/typo3/cms-core/12.4/en-us/Changelog/7.4/Deprecation-63603-ExtendedFileUtilitydontCheckForUniqueIsDeprecated.html
        $extendedFileUtility->setExistingFilesConflictMode(DuplicationBehavior::REPLACE);

        if (empty($this->indata['newFile'])) {
            // Checking referer / executing:
            $refInfo = parse_url($this->getHttpReferer());
            $httpHost = $this->getRequestHostOnly();

            if ($httpHost != $refInfo['host'] && !$GLOBALS['TYPO3_CONF_VARS']['SYS']['doNotCheckReferer']) {
                $extendedFileUtility->writeLog(0, 2, 1, 'Referer host "%s" and server host "%s" did not match!', [$refInfo['host'], $httpHost]);
            } else {
                // new file
                $file['newfile']['target'] = $this->userTempFolder();
                $file['newfile']['data'] = 'import_' . $this->getTimestampFromAspect() . '.txt';
                $extendedFileUtility->start($file);
                $newfileObj = $extendedFileUtility->func_newfile($file['newfile']);
                if (is_object($newfileObj)) {
                    $storageConfig = $newfileObj->getStorage()->getConfiguration();
                    $newfile['newFile'] = $storageConfig['basePath'] . ltrim($newfileObj->getIdentifier(), '/');
                    $newfile['newFileUid'] = $newfileObj->getUid();
                }
            }
        } else {
            $newfile = ['newFile' => $newFile, 'newFileUid' => $newFileUid];
        }

        if ($newfile['newFile']) {
            $csvFile = [
                'data' => $csv,
                'target' => $newfile['newFile'],
            ];
            $write = $extendedFileUtility->func_edit($csvFile);
        }
        return $newfile;
    }

    /**
     * Checks if a file has been uploaded and returns the complete physical fileinfo if so.
     *
     * @return	array	\TYPO3\CMS\Core\Resource\File	the complete physical file name, including path info.
     * @throws \Exception
     */
    public function checkUpload(): array
    {
        $newfile = ['newFile' => '', 'newFileUid' => 0];

        // Initializing:
        /* @var $extendedFileUtility ExtendedFileUtility */
        $extendedFileUtility = GeneralUtility::makeInstance(ExtendedFileUtility::class);
        $extendedFileUtility->setActionPermissions();
        $extendedFileUtility->setExistingFilesConflictMode(DuplicationBehavior::REPLACE);

        // Checking referer / executing:
        $refInfo = parse_url($this->getHttpReferer());
        $httpHost = $this->getRequestHostOnly();

        if ($httpHost != $refInfo['host'] && !$GLOBALS['TYPO3_CONF_VARS']['SYS']['doNotCheckReferer']) {
            $extendedFileUtility->writeLog(0, 2, 1, 'Referer host "%s" and server host "%s" did not match!', [$refInfo['host'], $httpHost]);
        } else {
            $extendedFileUtility->start($this->csvFile);
            $extendedFileUtility->setExistingFilesConflictMode(DuplicationBehavior::cast(DuplicationBehavior::REPLACE));
            $tempFile = $extendedFileUtility->func_upload($this->csvFile['upload']['1']);

            if (is_object($tempFile[0])) {
                $storageConfig = $tempFile[0]->getStorage()->getConfiguration();
                $newfile = [
                    'newFile' => rtrim($storageConfig['basePath'], '/') . '/' . ltrim($tempFile[0]->getIdentifier(), '/'),
                    'newFileUid' => $tempFile[0]->getUid(),
                ];
            }
        }

        return $newfile;
    }

    /**
     * @param int $fileUid
     * @return \TYPO3\CMS\Core\Resource\File|bool
     */
    private function getFileById(int $fileUid): File|bool
    {
        $resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);
        try {
            return $resourceFactory->getFileObject($fileUid);
        } catch(\TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException $e) {
        }
        return false;
    }

    /**
     * @param int $fileUid
     * @return string
     */
    private function getFileAbsolutePath(int $fileUid): string
    {
        $file = $this->getFileById($fileUid);
        if (!is_object($file)) {
            return '';
        }
        return Environment::getPublicPath() . '/' . str_replace('//', '/', $file->getStorage()->getConfiguration()['basePath'] . $file->getProperty('identifier'));
    }

    /**
     * Returns first temporary folder of the user account
     *
     * @return	string Absolute path to first "_temp_" folder of the current user, otherwise blank.
     */
    public function userTempFolder(): string
    {
        /** @var \TYPO3\CMS\Core\Resource\Folder $folder */
        $folder = $this->beUser->getDefaultUploadTemporaryFolder();
        return $folder->getPublicUrl();
    }

    /**
     * @return int
     */
    private function getTimestampFromAspect(): int
    {
        $context = GeneralUtility::makeInstance(Context::class);
        return $context->getPropertyFromAspect('date', 'timestamp');
    }

    public function getRequestHostOnly(): string
    {
        return $this->requestHostOnly;
    }

    private function getHttpReferer(): string
    {
        return $this->httpReferer;
    }
}
