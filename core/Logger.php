<?php


require_once __DIR__ . "/../config.inc.php";

class Logger
{
    private bool $_print_debugs;

    public function __construct()
    {
        global $config;

        error_reporting(E_ALL);
        ini_set('display_errors', 'on');
        ini_set("error_log", $config['error_log']);

        $this->_print_debugs = false;
        if(array_key_exists('suppress_debug', $config))
        {
            $this->_print_debugs = !( $config['suppress_debug'] );
        }else{
            error_log("'suppress_debug' is not defined - defaulting to 'true', consider defining it explicit in the config file!");
            $$this->_print_debugs = false;
        }
    }

    public function debug($msg)
    {
        global $_print_debugs;
        if($_print_debugs)
        {
            error_log($msg);
        }
    }

    // for now just pass through
    public function error($msg)
    {
        error_log($msg);
    }
}