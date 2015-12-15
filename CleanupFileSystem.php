<?php

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter;
use Monolog\Formatter\HtmlFormatter;

class Project_Backend_CleanupFileSystem extends Curry_Backend
{
    // file paths to ignore. Offset from www/
    protected $ignorePaths = array();
    protected $ignoreFileExtensions = array();
    protected $wwwPath;
    protected $trashPath;
    protected $queue = array();
    protected $queryMap = null;
    protected $con = null;
    protected $debug = false;
    protected $log = null;
    protected $logFile = null;
    
    public static function getGroup()
    {
        return 'System';
    }
    
    public function __construct()
    {
        $this->wwwPath = Curry_Core::$config->curry->wwwPath;
        Propel::disableInstancePooling();
        Propel::setLogger(null);
        if ($this->debug) {
            $this->con = Propel::getConnection(PagePeer::DATABASE_NAME);
            $this->con->useDebug(true);
        }
        
        $this->setupLogger();
    }
    
    public function showMain()
    {
        $config = $this->getCurryConfigObject();
        $form = $this->getConfigForm($config);
        if (isPost() && $form->isValid($_POST)) {
            if ($form->save->isChecked()) {
                $values = $form->getValues(true);
                $this->saveToCurryConfig($values, $config);
            } else if ($form->cleanup->isChecked()) {
                url('', array('module', 'view' => 'cleanup'))->redirect();
                exit();
            }
        }
        $this->addMainContent($form);
    }
    
    protected function getConfigForm($config)
    {
        $this->ignorePaths = isset($config->project->cleanup_file_system->ignore_paths) ? $config->project->cleanup_file_system->ignore_paths : 'cache,trash';
        $this->ignorePaths = explode(',', $this->ignorePaths);
        $this->ignoreFileExtensions = isset($config->project->cleanup_file_system->ignore_file_extensions) ? $config->project->cleanup_file_system->ignore_file_extensions : '.php,.css,.js';
        $this->ignoreFileExtensions = explode(',', $this->ignoreFileExtensions);
        return new Curry_Form(array(
            'action' => url('', $_GET),
            'method' => 'post',
            'elements' => array(
                'ignore_paths' => array('textarea', array(
                    'label' => 'Ignore paths (offset from and not including www/)',
                    'value' => implode("\r\n", $this->ignorePaths),
                    'rows' => 10,
                    'placeholder' => 'One path per line.',
                )),
                'ignore_file_extensions' => array('textarea', array(
                    'label' => 'Ignore file extensions',
                    'value' => implode("\r\n", $this->ignoreFileExtensions),
                    'rows' => 10,
                    'placeholder' => 'One file extension per line.',
                )),
                'save' => array('submit', array('label' => 'Save')),
                'cleanup' => array('submit', array('label' => 'Start cleanup')),
            ),
        ));
    }
    
    protected function getCurryConfigObject()
    {
        $configFile = Curry_Core::$config->curry->configPath;
        if (!$configFile) {
            throw new Exception('Configuration file not set.');
        } else if (!is_writable($configFile)) {
            throw new Exception("Configuration file doesn't seem to be writable.");
        }
        
        $config = new Zend_Config($configFile ? require($configFile) : array(), true);
        return $config;
    }
    
    protected function saveToCurryConfig($values, &$config)
    {
        $this->ignoredPaths = (array) explode("\r\n", $values['ignore_paths']);
        // cleanup last empty value from array
        $this->ignoredPaths = array_filter($this->ignoredPaths, function($val)
        {
            return !empty($val);
        });
        $this->ignoredFileExtensions = (array) explode("\r\n", $values['ignore_file_extensions']);
        $this->ignoredFileExtensions = array_filter($this->ignoredFileExtensions, function($val)
        {
            return !empty($val);
        });
        
        $config->project->cleanup_file_system->ignore_paths = implode(',', $this->ignoredPaths);
        $config->project->cleanup_file_system->ignore_file_extensions = implode(',', $this->ignoredFileExtensions);
        try {
            $writer = new Zend_Config_Writer_Array();
            $writer->write(Curry_Core::$config->curry->configPath, $config);
            if (extension_loaded('apc')) {
                if (function_exists('apc_delete_file')) {
                    @apc_delete_file(Curry_Core::$config->curry->configPath);
                } else {
                    @apc_clear_cache();
                }
            }
            $this->addMessage("Settings saved.", self::MSG_SUCCESS);
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    public function setDebug($value)
    {
        $this->debug = $value;
    }
    
    public function getDebug()
    {
        return $this->debug;
    }
    
    public function showCleanup()
    {
        $this->addBreadcrumb('Config', url('', array('module')));
        $this->addBreadcrumb('Cleanup', url());
        
        $this->fireCleanupTask();
    }
    
    public function fireCleanupTask()
    {
        set_time_limit(0);
        $this->log(__CLASS__.' started execution at: '.date('Y-m-d H:i:s'), Logger::INFO);
        $this->prepareTrashPath();
        $this->enqueue($this->wwwPath);
        
        // read queue
        while (! empty($this->queue)) {
            $path = $this->dequeue();
            $this->cleanup($path);
        }
        
        $this->log(__CLASS__.' finished execution at: '.date('Y-m-d H:i:s'), Logger::INFO);
        $this->log('logs saved to: '.$this->logFile);
        $this->dumpLogFileToBrowser();
    }
    
    protected function enqueue($item)
    {
        $this->log('Enqueued item: '.$item, Logger::DEBUG, true);
        $this->queue[] = $item;
    }
    
    protected function dequeue()
    {
        $item = array_shift($this->queue);
        $this->log('Dequeued item: '.$item, Logger::DEBUG, true);
        return $item;
    }
    
    protected function prepareTrashPath()
    {
        $this->trashPath = $this->getFullPath('trash/'.date('Y-m-d-H-i-s'));
        if ($this->createFolder($this->trashPath)) {
            $this->log('Created trash path: '.$this->getRelativePath($this->trashPath));
        }
    }
    
    protected function createFolder($fullPath)
    {
        if (! file_exists($fullPath)) {
            if (! mkdir($fullPath, 0777, true)) {
                throw new Exception('Failed to create path: '.$fullPath);
            }
            // folder created.
            return true;
        }
        // folder already exists and is not created.
        return false;
    }
    
    /**
     * Remove files not used in the project from the www/ folder.
     * @param string $fullPath  The real path of the folder to search.
     */
    protected function cleanup($fullPath)
    {
        $relPath = $this->getRelativePath($fullPath);
        if ( ($dir = dir($fullPath)) instanceof Directory) {
            $this->log('Reading dir: www/'.$relPath);
            while (false !== ($entry = $dir->read())) {
                // skip special files.
                if ($entry == '.' || $entry == '..') {
                    continue;
                }
                
                // is this a file or directory?
                $entryFullPath = $this->getFullPath($relPath.($relPath == '' ? '' : '/').$entry);
                // skip symbolic links
                if (is_link($entryFullPath)) {
                    $this->log('Skipped symbolic link: '.$entryFullPath);
                    continue;
                }
                
                if (is_file($entryFullPath)) {
                    $this->handleFile($entryFullPath);
                } elseif (is_dir($entryFullPath)) {
                    $this->handleDir($entryFullPath);
                }
            }
            
            $dir->close();
        }
    }
    
    protected function handleDir($fullPath)
    {
        $relPath = $this->getRelativePath($fullPath);
        if (in_array($relPath, $this->ignorePaths)) {
            $this->log("path [$relPath] is ignored. [skipped]", Logger::WARNING);
            return;
        }
        
        $this->enqueue($fullPath);
    }
    
    protected function handleFile($fullPath)
    {
        $basename = basename($fullPath);
        $relPath = $this->getRelativePath($fullPath);
        // is this a hidden file?
        if ( ($pos = strpos($basename, '.')) !== false && $pos === 0) {
            $this->log($relPath.' is a restricted file. [skipped]', Logger::WARNING);
            return;
        }
        
        // check whether file extension is ignored.
        $ext = substr($basename, strrpos($basename, '.'));
        if (in_array($ext, $this->ignoreFileExtensions)) {
            $this->log("file extension ignored in [$relPath]. [skipped]", Logger::WARNING);
            return;
        }
        
        // scan database.
        $file = 'www/'.$relPath;
        if (! $this->searchFileInDatabase($relPath)) {
            $this->log("File [$file] was not found in database. Moved to trash [$this->trashPath]");
            // TODO: move file to trash.
            //$this->trashFile($relPath);
        }
    }
    
    /**
     * return TRUE if file is found in database, FALSE otherwise.
     * @param string $file  The relative path of the file offset from www/ and not including 'www/'.
     * @return Boolean  Return TRUE if file was found in the database, FALSE otherwise.
     */
    protected function searchFileInDatabase($file)
    {
        foreach ($this->getQueryMap() as $model => $columnMaps) {
            if (empty($columnMaps)) {
                continue;
            }
            
            $q = $this->prepareQuery($model, $columnMaps, $file);
            $res = $q->find();
            if (!$res->isEmpty()) {
                $this->log("$file exists in model: {$model}");
                // found at least one match
                return true;
            } else {
                $this->log("$file not found in model: {$model}", null, true);
            }
        }
        
        return false;
    }
    
    protected function prepareQuery($model, $columnMaps, $file)
    {
        $peerClass = "{$model}Peer";
        $tableMap = $peerClass::getTableMap();
        $q = PropelQuery::from($tableMap->getPhpName());
        $index = 0;
        foreach ($columnMaps as $column) {
            if ($index) {
                $q->_or();
            }
            
            if ($column->getType() == 'VARCHAR') {
                $q->where("{$tableMap->getPhpName()}.{$column->getPhpName()} = ?", $file);
            } else {
                $q->where("{$tableMap->getPhpName()}.{$column->getPhpName()} LIKE ?", '%'.$file.'%');
            }
            
            ++ $index;
        }
        
        return $q;
    }
    
    protected function getQueryMap()
    {
        if (is_null($this->queryMap)) {
            $this->queryMap = array();
            $models = Curry_Propel::getModels(false);
            foreach ($models as $model) {
                $peerClass = "{$model}Peer";
                $tableMap = $peerClass::getTableMap();
                $this->queryMap[$model] = array();
                // introspect columns for text types
                foreach ($tableMap->getColumns() as $columnMap) {
                    if ($columnMap->isText() && $columnMap->getType() != 'CHAR') {
                        $this->queryMap[$model][] = $columnMap;
                    }
                }
            }
        }
        return $this->queryMap;
    }
    
    /**
     * move file to the trash folder.
     * @param string $file  The relative path of the file offset from www/ and not including www/.
     */
    protected function trashFile($file)
    {
        $fullPath = $this->wwwPath.'/'.$file;
        rename($fullPath, $this->trashPath.'/'.basename($file));
    }
    
    /**
     * Log messages to the logger.
     * @param string $text
     * @param unknown $logLevel     @see priority at http://php.net/manual/en/function.syslog.php
     * @param boolean $debug    log messages only when system is in debug mode.
     */
    protected function log($text, $logLevel = null, $debug = false)
    {
        if ($debug && !$this->debug) {
            return;
        }
        
        // default priority level.
        if (is_null($logLevel)) {
            $logLevel = Logger::NOTICE;
        }
        
        switch ($logLevel) {
            case Logger::DEBUG:
                $this->log->addDebug($text);
                break;
            case Logger::INFO:
                $this->log->addInfo($text);
                break;
            case Logger::NOTICE:
                $this->log->addNotice($text);
                break;
            case Logger::WARNING:
                $this->log->addWarning($text);
                break;
            case Logger::ERROR:
                $this->log->addError($text);
                break;
            case Logger::CRITICAL:
                $this->log->addCritical($text);
                break;
            case Logger::ALERT:
                $this->log->addAlert($text);
                break;
            case Logger::EMERGENCY:
                $this->log->addEmergency($text);
                break;
        }
    }
    
    protected function setupLogger()
    {
        $this->log = new Logger(__CLASS__);
        $logPath = $this->wwwPath.'/logs';
        if ($this->createFolder($logPath)) {
            $this->log('Created logs folder: '.$this->getRelativePath($this->logPath));
        }
        $this->logFile = $logPath.'/cleanupfilesystem-'.date('Y-m-d-H-i-s').'.log';
        $streamHandler = new StreamHandler($this->logFile, Logger::DEBUG);
        $streamHandler->setFormatter(new HtmlFormatter());
        $this->log->pushHandler($streamHandler);
    }
    
    protected function dumpLogFileToBrowser()
    {
        $content = file_get_contents($this->logFile);
        $html =<<<HTML
<pre>
$content
</pre>
HTML;
        $this->addMainContent($html);
    }
    
    /**
     * Creates a relative path offset from www/ and excluding "www/".
     * @example
     * getRelativePath('/var/www/myproject/www/my/path')
     * will return 'my/path'
     * @param string $fullPath
     */
    protected function getRelativePath($fullPath)
    {
        return substr($fullPath, strlen($this->wwwPath)+1);
    }
    
    /**
     * Return the full path for a relative path under the www/ folder.
     * This function is not the same as the php built-in realpath().
     * @param string $path
     */
    protected function getFullPath($path)
    {
        return $this->wwwPath.'/'.$path;
    }
    
}