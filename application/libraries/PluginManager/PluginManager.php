<?php

namespace LimeSurvey\PluginManager;

use \Yii;
use Plugin;

/**
 * Factory for limesurvey plugin objects.
 * @method mixed dispatchEvent
 */
class PluginManager extends \CApplicationComponent
{
    /**
     * Object containing any API that the plugins can use.
     * @var mixed $api The class name of the API class to load, or
     */
    public $api;

    /**
     * Array mapping guids to question object class names.
     * @var array
     */
    protected $guidToQuestion = [];

    /**
     * @var array
     */
    protected $plugins = [];

    /**
     * @var array
     */
    public $pluginDirs = [
        // User plugins installed through command line.
        'user' => 'webroot.plugins',
        // Core plugins.
        'core' => 'application.core.plugins',
        // Uploaded plugins installed through ZIP file.
        'upload' => 'webroot.upload.plugins'
    ];

    /**
     * @var array
     */
    protected $stores = [];

    /**
     * @var array<string, array> Array with string key to tuple value like 'eventName' => array($plugin, $method)
     */
    protected $subscriptions = [];

    /**
     * Created at init.
     * Used to deal with syntax errors etc in plugins during load.
     * @var PluginManagerShutdownFunction
     */
    public $shutdownObject;

    /**
     * Creates the plugin manager.
     *
     *
     * a reference to an already constructed reference.
     */
    public function init()
    {
        // NB: The shutdown object is disabled by default. Must be enabled
        // before attempting to load plugins (and disabled after).
        $this->shutdownObject = new PluginManagerShutdownFunction();
        register_shutdown_function($this->shutdownObject);

        parent::init();
        if (!is_object($this->api)) {
            $class = $this->api;
            $this->api = new $class;
        }
        $this->loadPlugins();
    }
    /**
     * Return a list of installed plugins, but only if the files are still there
     *
     * This prevents errors when a plugin was installed but the files were removed
     * from the server.
     *
     * @return array
     */
    public function getInstalledPlugins()
    {
        $pluginModel = Plugin::model();
        $records = $pluginModel->findAll();

        $plugins = array();

        foreach ($records as $record) {
            // Only add plugins we can find
            if ($this->loadPlugin($record->name) !== false) {
                $plugins[$record->id] = $record;
            }
        }
        return $plugins;
    }

    /**
     * @param string $destdir
     * @return array [boolean $result, string $errorMessage]
     */
    public function installUploadedPlugin($destdir)
    {
        $configFile = $destdir . '/config.xml';
        if (file_exists($configFile)) {
            libxml_disable_entity_loader(false);
            $xml = simplexml_load_file(realpath($configFile));
            libxml_disable_entity_loader(true);
            $pluginConfig = new \PluginConfiguration($xml);
            if (empty($pluginConfig)) {
                return [false, gT('Could not parse the plugin congig.xml into a configuration object')];
            } else {
                return $this->installPlugin($pluginConfig, 'user');
            }
        } else {
            return [false, gT('Could not find the plugin config.xml file')];
        }
    }

    /**
     * Install a plugin given a plugin configuration and plugin type (core or user).
     * @param string $pluginName Unique plugin class name/folder name.
     * @param string $pluginType 'user' or 'core', depending on location of folder.
     * @return array [boolean $result, string $errorMessage]
     */
    public function installPlugin(\PluginConfiguration $pluginConfig, $pluginType)
    {
        if (!$pluginConfig->validate()) {
            return [false, gT('Plugin configuration file is not valid.')];
        }

        if (!$pluginConfig->isCompatible()) {
            return [false, gT('Plugin is not compatible with your LimeSurvey version.')];
        }

        $plugin = new Plugin();
        $plugin->name        = (string) $pluginConfig->xml->metadata->name;
        $plugin->version     = (string) $pluginConfig->xml->metadata->version;
        $plugin->active      = 0;
        $plugin->plugin_type = $pluginType;
        $plugin->save();
        return [true, null];
    }

    /**
     * Return the status of plugin (true/active or false/desactive)
     *
     * @param string sPluginName Plugin name
     * @return boolean
     */
    public function isPluginActive($sPluginName)
    {
        $pluginModel = Plugin::model();
        $record = $pluginModel->findByAttributes(array('name' => $sPluginName, 'active' => '1'));
        if ($record == false) {
            return false;
        } else {
            return true;
        }
    }

    /**
     * Returns the storage instance of type $storageClass.
     * If needed initializes the storage object.
     * @param string $storageClass
     * @return mixed
     */
    public function getStore($storageClass)
    {
        if (!class_exists($storageClass)
                && class_exists('LimeSurvey\\PluginManager\\'.$storageClass)) {
            $storageClass = 'LimeSurvey\\PluginManager\\'.$storageClass;
        }
        if (!isset($this->stores[$storageClass])) {
            $this->stores[$storageClass] = new $storageClass();
        }
        return $this->stores[$storageClass];
    }


    /**
     * This function returns an API object, exposing an API to each plugin.
     * In the current case this is the LimeSurvey API.
     * @return LimesurveyApi
     */
    public function getAPI()
    {
        return $this->api;
    }
    /**
     * Registers a plugin to be notified on some event.
     * @param iPlugin $plugin Reference to the plugin.
     * @param string $event Name of the event.
     * @param string $function Optional function of the plugin to be called.
     */
    public function subscribe(iPlugin $plugin, $event, $function = null)
    {
        if (!isset($this->subscriptions[$event])) {
            $this->subscriptions[$event] = array();
        }
        if (!$function) {
            $function = $event;
        }
        $subscription = array($plugin, $function);
        // Subscribe only if not yet subscribed.
        if (!in_array($subscription, $this->subscriptions[$event])) {
            $this->subscriptions[$event][] = $subscription;
        }


    }

    /**
     * Unsubscribes a plugin from an event.
     * @param iPlugin $plugin Reference to the plugin being unsubscribed.
     * @param string $event Name of the event. Use '*', to unsubscribe all events for the plugin.
     */
    public function unsubscribe(iPlugin $plugin, $event)
    {
        // Unsubscribe recursively.
        if ($event == '*') {
            foreach ($this->subscriptions as $event) {
                $this->unsubscribe($plugin, $event);
            }
        } elseif (isset($this->subscriptions[$event])) {
            foreach ($this->subscriptions[$event] as $index => $subscription) {
                if ($subscription[0] == $plugin) {
                    unset($this->subscriptions[$event][$index]);
                }
            }
        }
    }

    /**
     * This function dispatches an event to all registered plugins.
     * @param PluginEvent $event Object holding all event properties
     * @param string|array $target Optional name of plugin to fire the event on
     *
     * @return PluginEvent
     */
    public function dispatchEvent(PluginEvent $event, $target = array())
    {
        $eventName = $event->getEventName();
        if (is_string($target)) {
            $target = array($target);
        }
        if (isset($this->subscriptions[$eventName])) {
            foreach ($this->subscriptions[$eventName] as $subscription) {
                if (!$event->isStopped()
                 && (empty($target) || in_array(get_class($subscription[0]), $target))) {
                    $subscription[0]->setEvent($event);
                    call_user_func($subscription);
                }
            }
        }

        return $event;
    }

    /**
     * Scans the plugin directory for plugins.
     * This function is not efficient so should only be used in the admin interface
     * that specifically deals with enabling / disabling plugins.
     * @param boolean $includeInstalledPlugins If set, also return plugins even if already installed in database.
     * @return array
     * @todo Factor out
     */
    public function scanPlugins($includeInstalledPlugins = false)
    {
        $this->shutdownObject->enable();

        $result = array();
        foreach ($this->pluginDirs as $pluginDir) {
            $currentDir = Yii::getPathOfAlias($pluginDir);
            if (is_dir($currentDir)) {
                foreach (new \DirectoryIterator($currentDir) as $fileInfo) {
                    if (!$fileInfo->isDot() && $fileInfo->isDir()) {
                        // Check if the base plugin file exists.
                        // Directory name Example must contain file ExamplePlugin.php.
                        $pluginName = $fileInfo->getFilename();
                        $this->shutdownObject->setPluginName($pluginName);
                        $file = Yii::getPathOfAlias($pluginDir.".$pluginName.{$pluginName}").".php";
                        $plugin = Plugin::model()->find('name = :name', [':name' => $pluginName]);
                        if (empty($plugin)
                            || ($includeInstalledPlugins && $plugin->load_error == 0)) {
                            if (file_exists($file)) {
                                try {
                                    $result[$pluginName] = $this->getPluginInfo($pluginName, $pluginDir);
                                } catch (\Throwable $ex) {
                                    // Load error.
                                    $error = [
                                        'message' => $ex->getMessage(),
                                        'file'  => $ex->getFile()
                                    ];
                                    $saveResult = Plugin::setPluginLoadError($plugin, $pluginName, $error);
                                    if (!$saveResult) {
                                        // This only happens if database save fails.
                                        $this->shutdownObject->disable();
                                        throw new \Exception(
                                            'Internal error: Could not save load error for plugin ' . $pluginName
                                        );
                                    }
                                }
                            }
                        } elseif ($plugin->load_error == 1) {
                            // List faulty plugins in scan files view.
                            $result[$pluginName] = [
                                'pluginName' => $pluginName,
                                'load_error' => 1,
                                'isCompatible' => false
                            ];
                        } else {
                        }
                    }

                }
            }
        }

        $this->shutdownObject->disable();

        return $result;
    }

    /**
     * Gets the description of a plugin. The description is accessed via a
     * static function inside the plugin file.
     *
     * @todo Read config.xml instead.
     * @param string $pluginClass The classname of the plugin
     * @return array|null
     */
    public function getPluginInfo($pluginClass, $pluginDir = null)
    {
        $result       = [];
        $class        = "{$pluginClass}";
        $pluginConfig = null;
        $pluginType   = null;

        if (!class_exists($class, false)) {
            $found = false;
            if (!is_null($pluginDir)) {
                $dirs = array($pluginDir);
            } else {
                $dirs = $this->pluginDirs;
            }

            foreach ($this->pluginDirs as $type => $pluginDir) {
                $file = Yii::getPathOfAlias($pluginDir.".$pluginClass.{$pluginClass}").".php";
                if (file_exists($file)) {
                    Yii::import($pluginDir.".$pluginClass.*");

                    $configFile = Yii::getPathOfAlias($pluginDir)
                        . DIRECTORY_SEPARATOR . $pluginClass
                        . DIRECTORY_SEPARATOR .'config.xml';
                    if (file_exists($configFile)) {
                        libxml_disable_entity_loader(false);
                        $xml = simplexml_load_file(realpath($configFile));
                        libxml_disable_entity_loader(true);
                        $pluginConfig = new \PluginConfiguration($xml);
                        $pluginType = $type;
                    } else {
                        $pluginConfig = null;
                    }

                    $found = true;
                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        if (!class_exists($class)) {
            return null;
        } else {
            $result['description']  = call_user_func(array($class, 'getDescription'));
            $result['pluginName']   = call_user_func(array($class, 'getName'));
            $result['pluginClass']  = $class;
            $result['pluginConfig'] = $pluginConfig;
            $result['isCompatible'] = $pluginConfig == null ? false : $pluginConfig->isCompatible();
            $result['load_error']   = 0;
            $result['pluginType']   = $pluginType;
            return $result;
        }
    }

    /**
     * Returns the instantiated plugin
     *
     * @param string $pluginName
     * @param int $id Identifier used for identifying a specific plugin instance.
     * If ommitted will return the first instantiated plugin with the given name.
     * @return iPlugin|null The plugin or null when missing
     */
    public function loadPlugin($pluginName, $id = null)
    {
        $return = null;
        $this->shutdownObject->enable();
        $this->shutdownObject->setPluginName($pluginName);
        try {
            // If the id is not set we search for the plugin.
            if (!isset($id)) {
                foreach ($this->plugins as $plugin) {
                    if (get_class($plugin) == $pluginName) {
                        $return = $plugin;
                    }
                }
            } else {
                if ((!isset($this->plugins[$id]) || get_class($this->plugins[$id]) !== $pluginName)) {
                    if ($this->getPluginInfo($pluginName) !== false) {
                        if (class_exists($pluginName)) {
                            $this->plugins[$id] = new $pluginName($this, $id);
                            if (method_exists($this->plugins[$id], 'init')) {
                                $this->plugins[$id]->init();
                            }
                        } else {
                            $this->plugins[$id] = null;
                        }
                    } else {
                        $this->plugins[$id] = null;
                    }
                }
                $return = $this->plugins[$id];
            }
        } catch (\Throwable $ex) {
            // Load error.
            $error = [
                'message' => $ex->getMessage(),
                'file'  => $ex->getFile()
            ];
            $plugin = Plugin::model()->find('name = :name', [':name' => $pluginName]);
            $saveResult = Plugin::setPluginLoadError($plugin, $pluginName, $error);
            if (!$saveResult) {
                // This only happens if database save fails.
                $this->shutdownObject->disable();
                throw new \Exception(
                    'Internal error: Could not save load error for plugin ' . $pluginName
                );
            }
        }
        $this->shutdownObject->disable();
        return $return;
    }

    /**
     * Handles loading all active plugins
     *
     * Possible improvement would be to load them for a specific context.
     * For instance 'survey' for runtime or 'admin' for backend. This needs
     * some thinking before implementing.
     */
    public function loadPlugins()
    {
        // If DB version is less than 165 : plugins table don't exist. 175 update it (boolean to integer for active).
        $dbVersion = \SettingGlobal::model()->find("stg_name=:name", array(':name'=>'DBVersion')); // Need table SettingGlobal, but settings from DB is set only in controller, not in App, see #11294
        if ($dbVersion && $dbVersion->stg_value >= 165) {
            $pluginModel = Plugin::model();
            $records = $pluginModel->findAllByAttributes(array('active'=>1));

            foreach ($records as $record) {
                if (!isset($record->load_error) || $record->load_error == 0) {
                    $this->loadPlugin($record->name, $record->id);
                }
            }
        } else {
            // Log it?
        }
        $this->dispatchEvent(new PluginEvent('afterPluginLoad', $this)); // Alow plugins to do stuff after all plugins are loaded
    }

    /**
     * Load ALL plugins, active and non-active
     * @return void
     */
    public function loadAllPlugins()
    {
        $records = Plugin::model()->findAll();
        foreach ($records as $record) {
            if ($record->load_error == 0) {
                $this->loadPlugin($record->name, $record->id);
            }
        }
    }

    /**
     * Get a list of question objects and load some information about them.
     * This registers the question object classes with Yii.
     */
    public function loadQuestionObjects($forceReload = false)
    {
        if (empty($this->guidToQuestion) || $forceReload) {
            $event = new PluginEvent('listQuestionPlugins');
            $this->dispatchEvent($event);


            foreach ($event->get('questionplugins', array()) as $pluginClass => $paths) {
                foreach ($paths as $path) {

                    Yii::import("webroot.plugins.$pluginClass.$path");
                    $parts = explode('.', $path);

                    // Get the class name.
                    $className = array_pop($parts);

                    // Get the GUID for the question object.
                    $guid = forward_static_call(array($className, 'getGUID'));

                    // Save the GUID-class mapping.
                    $this->guidToQuestion[$guid] = array(
                        'class' => $className,
                        'guid' => $guid,
                        'plugin' => $pluginClass,
                        'name' => $className::$info['name']
                    );
                }
            }
        }

        return $this->guidToQuestion;
    }

    /**
     * Construct a question object from a GUID.
     * @param string $guid
     * @param int $questionId,
     * @param int $responseId
     * @return iQuestion
     */
    public function constructQuestionFromGUID($guid, $questionId = null, $responseId = null)
    {
        $this->loadQuestionObjects();
        if (isset($this->guidToQuestion[$guid])) {
            $questionClass = $this->guidToQuestion[$guid]['class'];
            $questionObject = new $questionClass($this->loadPlugin($this->guidToQuestion[$guid]['plugin']), $this->api, $questionId, $responseId);
            return $questionObject;
        }
    }

    /**
     * Read all plugin config files and updates information
     * in database if plugin version differs.
     * @return void
     */
    public function readConfigFiles()
    {
        $this->loadAllPlugins();
        foreach ($this->plugins as $plugin) {
            if (is_object($plugin)) {
                $plugin->readConfigFile();
            } else {
                // Do nothing, plugin is deleted next time plugin manager is visited and loadPlugin validate if class exist
            }
        }
        $this->plugins = array();
        $this->subscriptions = array();
        $this->loadPlugins();
    }
}
