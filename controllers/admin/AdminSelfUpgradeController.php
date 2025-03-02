<?php

/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License 3.0 (AFL-3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to https://devdocs.prestashop.com/ for more information.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */
use PrestaShop\Module\AutoUpgrade\AjaxResponse;
use PrestaShop\Module\AutoUpgrade\BackupFinder;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeConfiguration;
use PrestaShop\Module\AutoUpgrade\Parameters\UpgradeFileNames;
use PrestaShop\Module\AutoUpgrade\Tools14;
use PrestaShop\Module\AutoUpgrade\UpgradeContainer;
use PrestaShop\Module\AutoUpgrade\UpgradePage;
use PrestaShop\Module\AutoUpgrade\UpgradeSelfCheck;
use PrestaShop\Module\AutoUpgrade\UpgradeTools\FilesystemAdapter;

$autoloadPath = __DIR__ . '/../../vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

class AdminSelfUpgradeController extends AdminController
{
    public $multishop_context;
    public $multishop_context_group = false;
    public $_html = '';
    // used for translations
    public static $l_cache;
    // retrocompatibility
    public $noTabLink = [];
    public $id = -1;

    public $ajax = false;

    public $standalone = true;

    /**
     * Initialized in initPath().
     */
    public $autoupgradePath;
    public $downloadPath;
    public $backupPath;
    public $latestPath;
    public $tmpPath;

    /**
     * autoupgradeDir.
     *
     * @var string directory relative to admin dir
     */
    public $autoupgradeDir = 'autoupgrade';
    public $latestRootDir = '';
    public $prodRootDir = '';
    public $adminDir = '';

    public $keepImages;
    public $updateDefaultTheme;
    public $changeToDefaultTheme;
    public $updateRTLFiles;
    public $keepMails;
    public $manualMode;
    public $deactivateCustomModule;
    public $disableOverride;

    public static $classes14 = ['Cache', 'CacheFS', 'CarrierModule', 'Db', 'FrontController', 'Helper', 'ImportModule',
        'MCached', 'Module', 'ModuleGraph', 'ModuleGraphEngine', 'ModuleGrid', 'ModuleGridEngine',
        'MySQL', 'Order', 'OrderDetail', 'OrderDiscount', 'OrderHistory', 'OrderMessage', 'OrderReturn',
        'OrderReturnState', 'OrderSlip', 'OrderState', 'PDF', 'RangePrice', 'RangeWeight', 'StockMvt',
        'StockMvtReason', 'SubDomain', 'Shop', 'Tax', 'TaxRule', 'TaxRulesGroup', 'WebserviceKey', 'WebserviceRequest', '', ];

    public static $maxBackupFileSize = 15728640; // 15 Mo

    public $_fieldsUpgradeOptions = [];
    public $_fieldsBackupOptions = [];

    /**
     * @var UpgradeContainer
     */
    private $upgradeContainer;

    /**
     * @var Db
     */
    public $db;

    /**
     * @var array
     */
    public $_errors = [];

    public function viewAccess($disable = false)
    {
        if ($this->ajax) {
            return true;
        } else {
            // simple access : we'll allow only 46admin
            global $cookie;
            if ($cookie->profile == 1) {
                return true;
            }
        }

        return false;
    }

    public function __construct()
    {
        parent::__construct();
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('magic_quotes_runtime', '0');
        @ini_set('magic_quotes_sybase', '0');

        $this->init();

        $this->db = Db::getInstance();
        $this->bootstrap = true;

        self::$currentIndex = $_SERVER['SCRIPT_NAME'] . (($controller = Tools14::getValue('controller')) ? '?controller=' . $controller : '');

        if (defined('_PS_ADMIN_DIR_')) {
            // Check that the 1-click upgrade working directory is existing or create it
            if (!file_exists($this->autoupgradePath) && !@mkdir($this->autoupgradePath)) {
                $this->_errors[] = $this->trans('Unable to create the directory "%s"', [$this->autoupgradePath], 'Modules.Autoupgrade.Admin');

                return;
            }

            // Make sure that the 1-click upgrade working directory is writeable
            if (!is_writable($this->autoupgradePath)) {
                $this->_errors[] = $this->trans('Unable to write in the directory "%s"', [$this->autoupgradePath], 'Modules.Autoupgrade.Admin');

                return;
            }

            // If a previous version of ajax-upgradetab.php exists, delete it
            if (file_exists($this->autoupgradePath . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php')) {
                @unlink($this->autoupgradePath . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php');
            }

            $file_tab = @filemtime($this->autoupgradePath . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php');
            $file = @filemtime(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $this->autoupgradeDir . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php');

            if ($file_tab < $file) {
                @copy(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'modules' . DIRECTORY_SEPARATOR . $this->autoupgradeDir . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php',
                    $this->autoupgradePath . DIRECTORY_SEPARATOR . 'ajax-upgradetab.php');
            }

            // Make sure that the XML config directory exists
            if (!file_exists(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml') &&
                !@mkdir(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml', 0775)) {
                $this->_errors[] = $this->trans('Unable to create the directory "%s"', [_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml'], 'Modules.Autoupgrade.Admin');

                return;
            } else {
                @chmod(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml', 0775);
            }

            // Create a dummy index.php file in the XML config directory to avoid directory listing
            if (!file_exists(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'index.php') &&
                (file_exists(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'index.php') &&
                    !@copy(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'index.php', _PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml' . DIRECTORY_SEPARATOR . 'index.php'))) {
                $this->_errors[] = $this->trans('Unable to create the directory "%s"', [_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'xml'], 'Modules.Autoupgrade.Admin');

                return;
            }
        }

        if (!$this->ajax) {
            Context::getContext()->smarty->assign('display_header_javascript', true);
        }
    }

    /**
     * function to set configuration fields display.
     */
    private function _setFields()
    {
        $this->_fieldsBackupOptions = [
            'PS_AUTOUP_BACKUP' => [
                'title' => $this->trans('Back up my files and database', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'defaultValue' => '1',
                'type' => 'bool',
                'desc' => $this->trans('Automatically back up your database and files in order to restore your shop if needed. This is experimental: you should still perform your own manual backup for safety.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_AUTOUP_KEEP_IMAGES' => [
                'title' => $this->trans('Back up my images', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'defaultValue' => '1',
                'type' => 'bool',
                'desc' => $this->trans('To save time, you can decide not to back your images up. In any case, always make sure you did back them up manually.', [], 'Modules.Autoupgrade.Admin'),
            ],
        ];
        $this->_fieldsUpgradeOptions = [
            'PS_AUTOUP_PERFORMANCE' => [
                'title' => $this->trans('Server performance', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isInt',
                'defaultValue' => '1',
                'type' => 'select',
                'desc' => $this->trans('Unless you are using a dedicated server, select "Low".', [], 'Modules.Autoupgrade.Admin') . '<br />' .
                    $this->trans('A high value can cause the upgrade to fail if your server is not powerful enough to process the upgrade tasks in a short amount of time.', [], 'Modules.Autoupgrade.Admin'),
                'choices' => [1 => $this->trans('Low (recommended)', [], 'Modules.Autoupgrade.Admin'), 2 => $this->trans('Medium', [], 'Modules.Autoupgrade.Admin'), 3 => $this->trans('High', [], 'Modules.Autoupgrade.Admin')],
            ],
            'PS_AUTOUP_CUSTOM_MOD_DESACT' => [
                'title' => $this->trans('Disable non-native modules', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'type' => 'bool',
                'desc' => $this->trans('As non-native modules can experience some compatibility issues, we recommend to disable them by default.', [], 'Modules.Autoupgrade.Admin') . '<br />' .
                    $this->trans('Keeping them enabled might prevent you from loading the "Modules" page properly after the upgrade.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_DISABLE_OVERRIDES' => [
                'title' => $this->trans('Disable all overrides', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'type' => 'bool',
                'desc' => $this->trans('Enable or disable all classes and controllers overrides.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_AUTOUP_UPDATE_DEFAULT_THEME' => [
                'title' => $this->trans('Upgrade the default theme', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'defaultValue' => '1',
                'type' => 'bool',
                'desc' => $this->trans('If you customized the default PrestaShop theme in its folder (folder name "classic" in 1.7), enabling this option will lose your modifications.', [], 'Modules.Autoupgrade.Admin') . '<br />'
                    . $this->trans('If you are using your own theme, enabling this option will simply update the default theme files, and your own theme will be safe.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_AUTOUP_UPDATE_RTL_FILES' => [
                'title' => $this->trans('Regenerate RTL stylesheet', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'defaultValue' => '1',
                'type' => 'bool',
                'desc' => $this->trans('If enabled, any RTL-specific files that you might have added to all your themes might be deleted by the created stylesheet.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_AUTOUP_CHANGE_DEFAULT_THEME' => [
                'title' => $this->trans('Switch to the default theme', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'defaultValue' => '0',
                'type' => 'bool',
                'desc' => $this->trans('This will change your theme: your shop will then use the default theme of the version of PrestaShop you are upgrading to.', [], 'Modules.Autoupgrade.Admin'),
            ],
            'PS_AUTOUP_KEEP_MAILS' => [
                'title' => $this->trans('Keep the customized email templates', [], 'Modules.Autoupgrade.Admin'),
                'cast' => 'intval',
                'validation' => 'isBool',
                'type' => 'bool',
                'desc' => $this->trans('This will not upgrade the default PrestaShop e-mails.', [], 'Modules.Autoupgrade.Admin') . '<br />'
                    . $this->trans('If you customized the default PrestaShop e-mail templates, enabling this option will keep your modifications.', [], 'Modules.Autoupgrade.Admin'),
            ],
        ];
    }

    /**
     * init to build informations we need.
     */
    public function init()
    {
        if (!$this->ajax) {
            parent::init();
        }

        // For later use, let's set up prodRootDir and adminDir
        // This way it will be easier to upgrade a different path if needed
        $this->prodRootDir = _PS_ROOT_DIR_;
        $this->adminDir = realpath(_PS_ADMIN_DIR_);
        $this->upgradeContainer = new UpgradeContainer($this->prodRootDir, $this->adminDir);
        if (!defined('__PS_BASE_URI__')) {
            // _PS_DIRECTORY_ replaces __PS_BASE_URI__ in 1.5
            if (defined('_PS_DIRECTORY_')) {
                define('__PS_BASE_URI__', _PS_DIRECTORY_);
            } else {
                define('__PS_BASE_URI__', realpath(dirname($_SERVER['SCRIPT_NAME'])) . '/../../');
            }
        }
        // from $_POST or $_GET
        $this->action = empty($_REQUEST['action']) ? null : $_REQUEST['action'];
        $this->initPath();
        $this->upgradeContainer->getState()->importFromArray(
            empty($_REQUEST['params']) ? [] : $_REQUEST['params']
        );

        // If you have defined this somewhere, you know what you do
        // load options from configuration if we're not in ajax mode
        if (!$this->ajax) {
            $upgrader = $this->upgradeContainer->getUpgrader();
            $this->upgradeContainer->getCookie()->create(
                $this->context->employee->id,
                $this->context->language->iso_code
            );

            $this->upgradeContainer->getState()->initDefault(
                $upgrader,
                $this->upgradeContainer->getProperty(UpgradeContainer::PS_ROOT_PATH),
                $this->upgradeContainer->getProperty(UpgradeContainer::PS_VERSION));

            if (isset($_GET['refreshCurrentVersion'])) {
                $upgradeConfiguration = $this->upgradeContainer->getUpgradeConfiguration();
                // delete the potential xml files we saved in config/xml (from last release and from current)
                $upgrader->clearXmlMd5File($this->upgradeContainer->getProperty(UpgradeContainer::PS_VERSION));
                $upgrader->clearXmlMd5File($upgrader->version_num);
                if ($upgradeConfiguration->get('channel') == 'private' && !$upgradeConfiguration->get('private_allow_major')) {
                    $upgrader->checkPSVersion(true, ['private', 'minor']);
                } else {
                    $upgrader->checkPSVersion(true, ['minor']);
                }
                Tools14::redirectAdmin(self::$currentIndex . '&conf=5&token=' . Tools14::getValue('token'));
            }
            // removing temporary files
            $this->upgradeContainer->getFileConfigurationStorage()->cleanAll();
        }

        $this->keepImages = $this->upgradeContainer->getUpgradeConfiguration()->shouldBackupImages();
        $this->updateDefaultTheme = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_UPDATE_DEFAULT_THEME');
        $this->changeToDefaultTheme = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_CHANGE_DEFAULT_THEME');
        $this->updateRTLFiles = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_UPDATE_RTL_FILES');
        $this->keepMails = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_KEEP_MAILS');
        $this->deactivateCustomModule = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_CUSTOM_MOD_DESACT');
        $this->disableOverride = $this->upgradeContainer->getUpgradeConfiguration()->get('PS_DISABLE_OVERRIDES');
    }

    /**
     * create some required directories if they does not exists.
     */
    public function initPath()
    {
        $this->upgradeContainer->getWorkspace()->createFolders();

        // set autoupgradePath, to be used in backupFiles and backupDb config values
        $this->autoupgradePath = $this->adminDir . DIRECTORY_SEPARATOR . $this->autoupgradeDir;
        $this->backupPath = $this->autoupgradePath . DIRECTORY_SEPARATOR . 'backup';
        $this->downloadPath = $this->autoupgradePath . DIRECTORY_SEPARATOR . 'download';
        $this->latestPath = $this->autoupgradePath . DIRECTORY_SEPARATOR . 'latest';
        $this->tmpPath = $this->autoupgradePath . DIRECTORY_SEPARATOR . 'tmp';
        $this->latestRootDir = $this->latestPath . DIRECTORY_SEPARATOR;

        if (!file_exists($this->backupPath . DIRECTORY_SEPARATOR . 'index.php')) {
            if (!copy(_PS_ROOT_DIR_ . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'index.php', $this->backupPath . DIRECTORY_SEPARATOR . 'index.php')) {
                $this->_errors[] = $this->trans('Unable to create file %s', [$this->backupPath . DIRECTORY_SEPARATOR . 'index.php'], 'Modules.Autoupgrade.Admin');
            }
        }

        $tmp = "order deny,allow\ndeny from all";
        if (!file_exists($this->backupPath . DIRECTORY_SEPARATOR . '.htaccess')) {
            if (!file_put_contents($this->backupPath . DIRECTORY_SEPARATOR . '.htaccess', $tmp)) {
                $this->_errors[] = $this->trans('Unable to create file %s', [$this->backupPath . DIRECTORY_SEPARATOR . '.htaccess'], 'Modules.Autoupgrade.Admin');
            }
        }
    }

    public function postProcess()
    {
        $this->_setFields();

        if (Tools14::isSubmit('putUnderMaintenance')) {
            foreach (Shop::getCompleteListOfShopsID() as $id_shop) {
                Configuration::updateValue('PS_SHOP_ENABLE', 0, false, null, (int) $id_shop);
            }
            Configuration::updateGlobalValue('PS_SHOP_ENABLE', 0);
        }

        if (Tools14::isSubmit('customSubmitAutoUpgrade')) {
            $config_keys = array_keys(array_merge($this->_fieldsUpgradeOptions, $this->_fieldsBackupOptions));
            $config = [];
            foreach ($config_keys as $key) {
                if (isset($_POST[$key])) {
                    $config[$key] = $_POST[$key];
                }
            }
            $UpConfig = $this->upgradeContainer->getUpgradeConfiguration();
            $UpConfig->merge($config);

            $upConfigValues = $this->extractFieldsToBeSavedInDB($UpConfig);
            $this->processDatabaseConfigurationFields($upConfigValues['dbConfig']);

            if ($this->upgradeContainer->getUpgradeConfigurationStorage()->save(
                $upConfigValues['fileConfig'],
                UpgradeFileNames::CONFIG_FILENAME)
            ) {
                Tools14::redirectAdmin(self::$currentIndex . '&conf=6&token=' . Tools14::getValue('token'));
            }
        }

        if (Tools14::isSubmit('deletebackup')) {
            $res = false;
            $name = Tools14::getValue('name');
            $filelist = scandir($this->backupPath);
            foreach ($filelist as $filename) {
                // the following will match file or dir related to the selected backup
                if (!empty($filename) && $filename[0] != '.' && $filename != 'index.php' && $filename != '.htaccess'
                    && preg_match('#^(auto-backupfiles_|)' . preg_quote($name) . '(\.zip|)$#', $filename, $matches)) {
                    if (is_file($this->backupPath . DIRECTORY_SEPARATOR . $filename)) {
                        $res &= unlink($this->backupPath . DIRECTORY_SEPARATOR . $filename);
                    } elseif (!empty($name) && is_dir($this->backupPath . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR)) {
                        $res = FilesystemAdapter::deleteDirectory($this->backupPath . DIRECTORY_SEPARATOR . $name . DIRECTORY_SEPARATOR);
                    }
                }
            }
            if ($res) {
                Tools14::redirectAdmin(self::$currentIndex . '&conf=1&token=' . Tools14::getValue('token'));
            } else {
                $this->_errors[] = $this->trans('Error when trying to delete backups %s', [$name], 'Modules.Autoupgrade.Admin');
            }
        }
        parent::postProcess();

        return true;
    }

    private function extractFieldsToBeSavedInDB(UpgradeConfiguration $fileConfig)
    {
        $DBConfig = [];

        foreach ($fileConfig as $key => $value) {
            if (in_array($key, UpgradeContainer::DB_CONFIG_KEYS)) {
                $DBConfig[$key] = $value;
                unset($fileConfig[$key]);
            }
        }

        return [
            'fileConfig' => $fileConfig,
            'dbConfig' => $DBConfig,
        ];
    }

    /**
     * Process configuration values to be stored in database
     */
    private function processDatabaseConfigurationFields(array $config)
    {
        if (isset($config['PS_DISABLE_OVERRIDES'])) {
            foreach (Shop::getCompleteListOfShopsID() as $id_shop) {
                Configuration::updateValue('PS_DISABLE_OVERRIDES', $config['PS_DISABLE_OVERRIDES'], false, null, (int) $id_shop);
            }
            Configuration::updateGlobalValue('PS_DISABLE_OVERRIDES', $config['PS_DISABLE_OVERRIDES']);
        }
    }

    public function initContent()
    {
        // Make sure the user has configured the upgrade options, or set default values
        $configuration_keys = [
            'PS_AUTOUP_UPDATE_DEFAULT_THEME' => 1,
            'PS_AUTOUP_CHANGE_DEFAULT_THEME' => 0,
            'PS_AUTOUP_UPDATE_RTL_FILES' => 1,
            'PS_AUTOUP_KEEP_MAILS' => 0,
            'PS_AUTOUP_CUSTOM_MOD_DESACT' => 1,
            'PS_DISABLE_OVERRIDES' => Configuration::get('PS_DISABLE_OVERRIDES'),
            'PS_AUTOUP_PERFORMANCE' => 1,
        ];

        foreach ($configuration_keys as $k => $default_value) {
            if (Configuration::get($k) == '') {
                Configuration::updateValue($k, $default_value);
            }
        }

        // update backup name
        $backupFinder = new BackupFinder($this->backupPath);
        $availableBackups = $backupFinder->getAvailableBackups();
        if (!$this->upgradeContainer->getUpgradeConfiguration()->get('PS_AUTOUP_BACKUP')
            && !empty($availableBackups)
            && !in_array($this->upgradeContainer->getState()->getBackupName(), $availableBackups)
        ) {
            $this->upgradeContainer->getState()->setBackupName(end($availableBackups));
        }

        $upgrader = $this->upgradeContainer->getUpgrader();
        $upgradeSelfCheck = new UpgradeSelfCheck(
            $upgrader,
            $this->upgradeContainer->getPrestaShopConfiguration(),
            $this->prodRootDir,
            $this->adminDir,
            $this->autoupgradePath
        );
        $response = new AjaxResponse($this->upgradeContainer->getState(), $this->upgradeContainer->getLogger());
        $this->_html = (new UpgradePage(
            $this->upgradeContainer->getUpgradeConfiguration(),
            $this->upgradeContainer->getTwig(),
            $this->upgradeContainer->getTranslator(),
            $upgradeSelfCheck,
            $upgrader,
            $backupFinder,
            $this->autoupgradePath,
            $this->prodRootDir,
            $this->adminDir,
            self::$currentIndex,
            $this->token,
            $this->upgradeContainer->getState()->getInstallVersion(),
            $this->manualMode,
            $this->upgradeContainer->getState()->getBackupName(),
            $this->downloadPath
        ))->display(
            $response
                ->setUpgradeConfiguration($this->upgradeContainer->getUpgradeConfiguration())
                ->getJson()
        );

        $this->content = $this->_html;

        return parent::initContent();
    }

    /**
     * Adapter for trans calls, existing only on PS 1.7.
     * Making them available for PS 1.6 as well.
     *
     * @param string $id
     * @param string $domain
     * @param string $locale
     */
    public function trans($id, array $parameters = [], $domain = null, $locale = null)
    {
        return (new \PrestaShop\Module\AutoUpgrade\UpgradeTools\Translator(__CLASS__))->trans($id, $parameters, $domain, $locale);
    }
}
