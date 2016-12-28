<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.com/license
 */

use craft\helpers\FileHelper;

if (version_compare(PHP_VERSION, '4.3', '<')) {
    echo 'At least PHP 4.3 is required to run this script!';
    exit(1);
}

/**
 * The Craft Requirement Checker allows checking if the current system meets the minimum requirements for running a
 * Craft 3 application.
 *
 * This class allows rendering of the requirement report through a web browser or command line interface.
 *
 * Example:
 *
 * ~~~php
 * require_once('path/to/RequirementsChecker.php');
 * $requirementsChecker = new RequirementsChecker();
 * $requirements = array(
 *     array(
 *         'name' => 'PHP Some Extension',
 *         'mandatory' => true,
 *         'condition' => extension_loaded('some_extension'),
 *         'memo' => 'PHP extension "some_extension" required',
 *     ),
 * );
 *
 * $requirementsChecker->checkCraft()->check($requirements)->render();
 * ~~~
 *
 * If you wish to render the report with your own representation, use [[getResult()]] instead of [[render()]]
 *
 * Note: this class definition does not match ordinary Craft style, because it should match PHP 4.3
 * and should not use features from newer PHP versions!
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class RequirementsChecker
{
    var /** @noinspection AccessModifierPresentedInspection */
        $dbCreds;
    var /** @noinspection AccessModifierPresentedInspection */
        $iconvMessage;
    var /** @noinspection AccessModifierPresentedInspection */
        $dbConnectionError;
    var /** @noinspection AccessModifierPresentedInspection */
        $iniSetMessage;
    var /** @noinspection AccessModifierPresentedInspection */
        $memoryMessage;
    var /** @noinspection AccessModifierPresentedInspection */
        $webRootFolderMessage;
    var /** @noinspection AccessModifierPresentedInspection */
        $result;

    var /** @noinspection AccessModifierPresentedInspection */
        $requiredMySqlVersion = '5.5.0';
    var /** @noinspection AccessModifierPresentedInspection */
        $requiredPgSqlVersion = '9.5';

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Check the given requirements, collecting results into internal field.
     * This method can be invoked several times checking different requirement sets.
     * Use [[getResult()]] or [[render()]] to get the results.
     *
     * @param array|string $requirements The requirements to be checked. If an array, it is treated as the set of
     *                                   requirements. If a string, it is treated as the path of the file, which
     *                                   contains the requirements;
     *
     * @return static The instance of the class.
     */
    function check($requirements)
    {
        if (is_string($requirements)) {
            $requirements = require $requirements;
        }

        if (!is_array($requirements)) {
            $this->usageError('Requirements must be an array, "'.gettype($requirements).'" has been given!');
        }

        if (!is_array($this->result)) {
            $this->result = array(
                'summary' => array(
                    'total' => 0,
                    'errors' => 0,
                    'warnings' => 0,
                ),
                'requirements' => array(),
            );
        }

        foreach ($requirements as $key => $rawRequirement) {
            $requirement = $this->normalizeRequirement($rawRequirement, $key);
            $this->result['summary']['total']++;

            if (!$requirement['condition']) {
                if ($requirement['mandatory']) {
                    $requirement['error'] = true;
                    $requirement['warning'] = true;
                    $this->result['summary']['errors']++;
                } else {
                    $requirement['error'] = false;
                    $requirement['warning'] = true;
                    $this->result['summary']['warnings']++;
                }
            } else {
                $requirement['error'] = false;
                $requirement['warning'] = false;
            }

            $this->result['requirements'][] = $requirement;
        }

        return $this;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Performs the check for the Craft core requirements.
     *
     * @return RequirementsChecker The instance of the class.
     */
    function checkCraft()
    {
        /** @noinspection dirnameCallOnFileConstantInspection */
        return $this->check(dirname(__FILE__).DIRECTORY_SEPARATOR.'requirements.php');
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean Returns if we're running in the context of Craft or as a standalone PHP script.
     */
    function isCraftRunning()
    {
        return class_exists('Craft');
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Return the check results.
     *
     * @return array|null check results in format:
     *
     * ```php
     * array(
     *     'summary' => array(
     *         'total' => total number of checks,
     *         'errors' => number of errors,
     *         'warnings' => number of warnings,
     *     ),
     *     'requirements' => array(
     *         array(
     *             ...
     *             'error' => is there an error,
     *             'warning' => is there a warning,
     *         ),
     *         // ...
     *     ),
     * )
     * ```
     */
    function getResult()
    {
        return $this->result;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Renders the requirements check result. The output will vary depending is a script running from web or from console.
     */
    function render()
    {
        if ($this->result === null) {
            $this->usageError('Nothing to render!');
        }

        /** @noinspection dirnameCallOnFileConstantInspection */
        $baseViewFilePath = dirname(__FILE__).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'views';

        if (!empty($_SERVER['argv'])) {
            $viewFilename = $baseViewFilePath.DIRECTORY_SEPARATOR.'console'.DIRECTORY_SEPARATOR.'index.php';
        } else {
            $viewFilename = $baseViewFilePath.DIRECTORY_SEPARATOR.'web'.DIRECTORY_SEPARATOR.'index.php';
        }

        $this->renderViewFile($viewFilename, $this->result);
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Checks if the given PHP extension is available and its version matches the given one.
     *
     * @param string $extensionName The PHP extension name.
     * @param string $version       The required PHP extension version.
     * @param string $compare       The comparison operator, by default '>='.
     *
     * @return boolean If the PHP extension version matches or not.
     */
    function checkPhpExtensionVersion($extensionName, $version, $compare = '>=')
    {
        if (!extension_loaded($extensionName)) {
            return false;
        }

        $extensionVersion = phpversion($extensionName);

        if (empty($extensionVersion)) {
            return false;
        }

        if (stripos($extensionVersion, 'PECL-') === 0) {
            $extensionVersion = substr($extensionVersion, 5);
        }

        return version_compare($extensionVersion, $version, $compare);
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Checks if the given PHP configuration option (from php.ini) is on.
     *
     * @param string $name The configuration option name.
     *
     * @return boolean If the option is on or not.
     */
    function checkPhpIniOn($name)
    {
        $value = ini_get($name);

        if (empty($value)) {
            return false;
        }

        return ((int)$value == 1 || strtolower($value) == 'on');
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Checks if the given PHP configuration option (from php.ini) is off.
     *
     * @param string $name The configuration option name.
     *
     * @return boolean If the option is off or not.
     */
    function checkPhpIniOff($name)
    {
        $value = ini_get($name);

        if (empty($value)) {
            return true;
        }

        return (strtolower($value) == 'off');
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Gets the size in bytes from verbose size representation. For example: '5K' => 5 * 1024
     *
     * @param string $verboseSize The verbose size representation.
     *
     * @return integer The actual size in bytes.
     */
    function getByteSize($verboseSize)
    {
        if (empty($verboseSize)) {
            return 0;
        }

        if (is_numeric($verboseSize)) {
            return (int)$verboseSize;
        }

        $sizeUnit = trim($verboseSize, '0123456789');
        $size = str_replace($sizeUnit, '', $verboseSize);
        $size = trim($size);

        if (!is_numeric($size)) {
            return 0;
        }

        switch (strtolower($sizeUnit)) {
            case 'kb':
            case 'k':
                return $size * 1024;
            case 'mb':
            case 'm':
                return $size * 1024 * 1024;
            case 'gb':
            case 'g':
                return $size * 1024 * 1024 * 1024;
            default:
                return 0;
        }
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Renders a view file.
     * This method includes the view file as a PHP script and captures the display result if required.
     *
     * @param string  $_viewFile_ The view file.
     * @param array   $_data_     The data to be extracted and made available to the view file.
     * @param boolean $_return_   Whether the rendering result should be returned as a string.
     *
     * @return string The rendering result. Null if the rendering result is not required.
     */
    function renderViewFile($_viewFile_, $_data_ = null, $_return_ = false)
    {
        // we use special variable names here to avoid conflict when extracting data
        if (is_array($_data_)) {
            extract($_data_, EXTR_PREFIX_SAME, 'data');
        }

        if ($_return_) {
            ob_start();
            ob_implicit_flush(false);

            require $_viewFile_;

            return ob_get_clean();
        }

        require $_viewFile_;

        return null;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Normalizes requirement ensuring it has correct format.
     *
     * @param array   $requirement    The raw requirement.
     * @param integer $requirementKey The requirement key in the list.
     *
     * @return array normalized requirement.
     */
    function normalizeRequirement($requirement, $requirementKey = 0)
    {
        if (!is_array($requirement)) {
            $this->usageError('Requirement must be an array!');
        }

        if (!array_key_exists('condition', $requirement)) {
            $this->usageError("Requirement '{$requirementKey}' has no condition!");
        }

        if (!array_key_exists('name', $requirement)) {
            $requirement['name'] = is_numeric($requirementKey) ? 'Requirement #'.$requirementKey : $requirementKey;
        }

        if (!array_key_exists('mandatory', $requirement)) {
            if (array_key_exists('required', $requirement)) {
                $requirement['mandatory'] = $requirement['required'];
            } else {
                $requirement['mandatory'] = false;
            }
        }

        if (!array_key_exists('memo', $requirement)) {
            $requirement['memo'] = '';
        }

        return $requirement;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Displays a usage error. This method will then terminate the execution of the current application.
     *
     * @param string $message the error message
     */
    function usageError($message)
    {
        echo "Error: $message\n\n";
        exit(1);
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Returns the server information.
     *
     * @return string The server information.
     */
    function getServerInfo()
    {
        return isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : '';
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Returns the current date if possible in string representation.
     *
     * @return string The current date.
     */
    function getCurrentDate()
    {
        return @strftime('%Y-%m-%d %H:%M', time());
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean
     */
    function checkDatabaseCreds()
    {
        // Check if we're running as a standalone script.
        /** @noinspection dirnameCallOnFileConstantInspection */
        $dbConfigPath = dirname(__FILE__).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'db.php';

        if (is_file($dbConfigPath)) {
            /** @noinspection dirnameCallOnFileConstantInspection */
            $dbCreds = @require dirname(__FILE__).DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'db.php';

            if (is_array($dbCreds) && $dbCreds['server'] && $dbCreds['user'] && $dbCreds['password'] && $dbCreds['database'] && $dbCreds['driver']) {
                $this->dbCreds = $dbCreds;

                return true;
            }
        } else if ($this->isCraftRunning()) {
            $configService = Craft::$app->getConfig();

            // Check if we're running in the context of Craft.
            $this->dbCreds['server'] = $configService->get('server', 'db');
            $this->dbCreds['user'] = $configService->get('user', 'db');
            $this->dbCreds['password'] = $configService->get('password', 'db');
            $this->dbCreds['database'] = $configService->get('database', 'db');
            $this->dbCreds['driver'] = $configService->get('driver', 'db');
            $this->dbCreds['port'] = $configService->getDbPort();

            return true;
        }

        return false;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Error-handler that mutes errors.
     */
    function muteErrorHandler()
    {
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean
     */
    function testIconvTruncateBug()
    {
        $warningMessage = 'You have a buggy version of iconv installed. (See <a href="https://bugs.php.net/bug.php?id=48147">PHP bug #48147</a> and <a href="http://sourceware.org/bugzilla/show_bug.cgi?id=13541">iconv bug #13541</a>.)';
        $ignoreMessage = 'The version of iconv you have installed does not support //IGNORE, making it unusable for transcoding purposes.';
        $recommendedMessage = '<a href="http://php.net/manual/en/book.iconv.php">iconv</a> is recommended for more robust character set conversion support.';

        if (function_exists('iconv')) {
            // Let's see what happens.
            set_error_handler([$this, 'muteErrorHandler']);
            $r = iconv('utf-8', 'ascii//IGNORE', "\xCE\xB1".str_repeat('a', 9000));
            restore_error_handler();

            if ($r === false) {
                $this->iconvMessage = $ignoreMessage;

                return false;
            }

            if (($c = strlen($r)) < 9000) {
                $this->iconvMessage = $warningMessage;

                return false;
            }

            if ($c > 9000) {
                $this->iconvMessage = $warningMessage;

                return false;
            }

            $this->iconvMessage = $recommendedMessage;

            return true;
        }

        $this->iconvMessage = $recommendedMessage;

        return false;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * Checks to see if the MySQL InnoDB storage engine is installed and enabled.
     *
     * @return boolean
     */
    function isInnoDbSupported()
    {
        if (($conn = $this->getDbConnection()) !== false) {
            $results = $conn->query('SHOW ENGINES');

            foreach ($results as $result) {
                if (strtolower($result['Engine']) == 'innodb' && strtolower($result['Support']) != 'no') {
                    return true;
                }
            }
        }

        return false;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean|mixed
     *
     * @throws Exception in case of failure
     */
    function checkDatabaseServerVersion()
    {
        if (($conn = $this->getDbConnection()) !== false) {
            switch ($this->dbCreds['driver']) {
                case 'mysql':
                    $requiredVersion = $this->requiredMySqlVersion;
                    break;
                case 'pgsql':
                    $requiredVersion = $this->requiredPgSqlVersion;
                    break;
                default:
                    /** @noinspection ThrowRawExceptionInspection */
                    throw new Exception('Unsupported connection type: '.$this->dbCreds['driver']);
            }

            return version_compare($conn->getAttribute(PDO::ATTR_SERVER_VERSION), $requiredVersion, '>=');
        }

        return false;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean|PDO
     */
    function getDbConnection()
    {
        static $conn;

        if (!$conn) {
            try {
                $dsn = "{$this->dbCreds['driver']}:host={$this->dbCreds['server']};dbname={$this->dbCreds['database']};";

                if (array_key_exists('port', $this->dbCreds)) {
                    $dsn .= "port={$this->dbCreds['port']};";
                }

                $conn = new PDO($dsn, $this->dbCreds['user'], $this->dbCreds['password']);
                $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                $this->dbConnectionError = "Can't connect to the database with the credentials supplied in db.php. Please double check them and try again.";

                return false;
            }
        }

        return $conn;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean
     */
    function checkIniSet()
    {
        $oldValue = ini_get('memory_limit');

        set_error_handler([$this, 'muteErrorHandler']);
        $result = ini_set('memory_limit', '442M');
        restore_error_handler();

        // ini_set can return false or an empty string depending on your php version / FastCGI.
        // If ini_set has been disabled in php.ini, the value will be null because of our muted error handler
        if ($result === null) {
            $this->iniSetMessage = 'It looks like <a href="http://php.net/manual/en/function.ini-set.php">ini_set</a> has been disabled in your php.ini file. Craft requires that to operate.';

            return false;
        }

        // ini_set can return false or an empty string or the current value of memory_limit depending on your php
        // version and FastCGI. Regard, calling it didn't work, but there was no error.
        if ($result === false || $result === '' || $result === $oldValue) {
            $this->iniSetMessage = 'It appears calls to <a href="http://php.net/manual/en/function.ini-set.php">ini_set</a> are not working for Craft. You may need to increase some settings in your php.ini file such as <a href="http://php.net/manual/en/ini.core.php#ini.memory-limit">memory_limit</a> and <a href="http://php.net/manual/en/info.configuration.php#ini.max-execution-time">max_execution_time</a> for long running operations like updating and asset transformations.';

            // Return true here so it's not a "fatal" error, but will be treated as a warning.
            return true;
        }

        // Resetting should work, but might as well be extra careful.
        set_error_handler([$this, 'muteErrorHandler']);
        ini_set('memory_limit', $oldValue);
        restore_error_handler();

        $this->iniSetMessage = 'Calls to <a href="http://php.net/manual/en/function.ini-set.php">ini_set</a> are working correctly.';

        return true;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean
     */
    function checkMemory()
    {
        $memoryLimit = ini_get('memory_limit');
        $memoryLimitInBytes = $this->getByteSize($memoryLimit);
        $this->memoryMessage = '';

        // 32M check.
        if ($memoryLimitInBytes <= 33554432) {
            $this->memoryMessage = 'Craft CMS requires at least 32M of memory allocated to PHP to operate smoothly.';

            return false;
            // 128M check
        } else if ($memoryLimitInBytes <= 134217728) {
            $this->memoryMessage = 'You have 128M allocated to PHP which should be fine for most sites. If you will be processing very large images or having Craft CMS automatically backup a large database, you might need to increase this to 256M or higher.';

            return false;
        }

        $this->memoryMessage = 'There is '.$memoryLimit.' of memory allocated to PHP.';

        return true;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @return boolean
     */
    function checkWebRoot()
    {
        $pathService = Craft::$app->getPath();
        $publicFolders = [];

        // The paths to check.
        $folders = [
            'storage' => $pathService->getStoragePath(),
            'plugins' => $pathService->getPluginsPath(),
            'config' => $pathService->getConfigPath(),
            'app' => Craft::$app->getBasePath(),
            'templates' => $pathService->getSiteTemplatesPath(),
            'translations' => $pathService->getSiteTranslationsPath(),
        ];

        foreach ($folders as $key => $path) {
            if ($realPath = realpath($path)) {
                $folders[$key] = $this->isPathInsideWebroot($realPath);
            }
        }

        foreach ($folders as $key => $result) {
            // We were able to connect to one of our exposed folder checks.
            if ($result === true) {
                $publicFolders[] = $key;
            }
        }

        $totalPublicFolders = count($publicFolders);

        if ($totalPublicFolders !== 0) {
            $folderString = '';

            foreach ($publicFolders as $counter => &$publicFolder) {
                $folderString .= '“craft/'.$publicFolder.'”';

                if (isset($publicFolders[$counter + 1]) && $totalPublicFolders > 2) {
                    $folderString .= ', ';
                }

                if (isset($publicFolders[$counter + 1]) && $counter + 2 === $totalPublicFolders) {
                    if ($totalPublicFolders === 2) {
                        $folderString .= ' and ';
                    } else {
                        $folderString .= 'and ';
                    }
                }
            }
            unset($publicFolder);

            if ($totalPublicFolders > 1) {
                $folderString .= ' folders';
            } else {
                $folderString .= ' folder';
            }

            $this->webRootFolderMessage = 'Your Craft CMS '.$folderString.' appear to be publicly accessible which is a security risk. You should strongly consider moving them above your web root or blocking access to them via .htaccess or web.config files.';

            return false;
        }

        return true;
    }

    /** @noinspection AccessModifierPresentedInspection */
    /**
     * @param string $pathToTest
     *
     * @return boolean
     */
    function isPathInsideWebroot($pathToTest)
    {
        $pathToTest = FileHelper::normalizePath($pathToTest);

        // Get the base path without the script name.
        $request = Craft::$app->getRequest();
        $scriptFile = $request->getScriptFile();
        $scriptUrl = $request->getScriptUrl();
        $subBasePath = FileHelper::normalizePath(mb_substr($scriptFile, 0, -mb_strlen($scriptUrl)));

        return mb_strpos($pathToTest.DIRECTORY_SEPARATOR, $subBasePath) !== false;
    }
}