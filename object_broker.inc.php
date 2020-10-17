<?php

include_once __DIR__ . "/core/DataStore.php";
include_once __DIR__ . "/core/Logger.php";


class OBJECT_BROKER
{
    public Logger $logger;
    public DataStore $datastore;

    public $instance;             // Holds instanced objects
    public $plugins;              // Holds names of loaded plugins
    public $apis;                 // Holds names of loaded APIs

    // ---------------------------------------------------------------------------------
    // ::DESCRIPT: -
    // ::RETURNS: -
    // ---------------------------------------------------------------------------------

    public function __construct()
    {
        $this->instance = [];
        $this->plugins = [];
        $this->apis = [];
    }


    // ---------------------------------------------------------------------------------
    // ::DESCRIPT: -
    // ---------------------------------------------------------------------------------

    public function __destruct()
    {

    }

    public function register_object_to_broker($object_name, $object)
    {
        $this->instance[$object_name] = $object;
    }

    public function register_plug_to_broker($plugin_name, $plugin)
    {
        $this->plugins[$plugin_name] = $plugin;
    }

    public function register_api_to_broker($api_name, $api)
    {
        $this->apis[$api_name] = $api;
    }

}

?>