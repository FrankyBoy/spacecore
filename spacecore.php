<?php

require_once __DIR__ . "/core/DataStore.php";
require_once __DIR__ . "/core/Logger.php";

require_once("config.inc.php");
global $config;

$logger = new Logger();

$logger->debug("");
$logger->debug("WAKEUP");

include_once('object_broker.inc.php');
$object_broker = new OBJECT_BROKER();

$object_broker->logger = $logger;
$object_broker->datastore = new DataStore($object_broker->logger);

$load_order = ['apis' => 'api', 'plugins' => 'plugin']; // dir => prefix
foreach(array_keys($load_order) as $load_item)
{
    $sub_dirs = array_filter(glob($load_item.'/*'), 'is_dir');
    foreach($sub_dirs as $dir)
    {
       $dir_base = basename($dir);
       $path = $load_item.'/'.$dir_base.'/init.inc.php';
       if(file_exists($path))
       {
           if( include_once($path) )
           {
               $prefix = $load_order[$load_item];
               $classname = strtoupper($prefix).'_'.strtoupper($dir_base);
               $index = $prefix.'_' . $dir_base;
               $object_broker->instance[$index] = new $classname($object_broker);
               $logger->debug("$classname loaded as $index");
           }
       }else{
           $logger->error("Directory for $load_item exists, but no 'init.inc.php' file was found");
       }
    }
}

// determine invocation method: CLI or Serverbased?
if(php_sapi_name() == 'cli')
{
    // fired up via command line. It's cron time.
    $logger->debug("CLI MODE: assuming scheduled invocation");

    // Run through all plugins and execute any housekeeping steps
    foreach ($object_broker->plugins as $registered_plugin) {
        if (method_exists($object_broker->instance[$registered_plugin], 'router_housekeeping')) {
            // if available, do housekeeping (scheduled tasks, etc.)
            $object_broker->instance[$registered_plugin]->router_housekeeping();
        }
    }
}
else
{
    // read incoming layer6 stanza (L6 = HTTP(s) in this particular case)
    $layer6_stanza = file_get_contents("php://input");

    // decode the layer 6 stanza and extract the layer 7 information (the actual Telegram protocol) into an assoc. array)
    $GLOBALS['layer7_stanza'] = json_decode($layer6_stanza, true);

    // right now we are not sure if the stuff we received was valid JSON..
    if (json_last_error() === JSON_ERROR_NONE && $layer6_stanza != NULL) {
        // Valid JSON encountered. Treat this as a telegram message
        $logger->debug("telegram:receiveMessage: VALID JSON DECODED: '$layer6_stanza'");

        // Is the sender legit?
        if (!isset($_GET['token']) || (isset($_GET['token']) && $_GET['token'] != $config['bot_token'])) {
            $logger->error("telegram:SenderAuthentication: Invalid token: " . ( isset($_GET['token']) ? $_GET['token'] : 'NONE' ) );
            exit;
        }

        // Run through all plugins and execute any preprocessing steps
        foreach ($object_broker->plugins as $registered_plugin) {
            if (isset($GLOBALS['layer7_stanza']['message']['text'])) {
                // if available, use text preprocessor
                if (method_exists($object_broker->instance[$registered_plugin], 'router_preprocess_text')) {
                    $object_broker->instance[$registered_plugin]->router_preprocess_text();
                }
            }

            if (isset($GLOBALS['layer7_stanza']['message']['photo'])) {
                // if available, use photo preprocessor
                if (method_exists($object_broker->instance[$registered_plugin], 'router_preprocess_photo')) {
                    $object_broker->instance[$registered_plugin]->router_preprocess_photo();
                }
            }

            if (method_exists($object_broker->instance[$registered_plugin], 'router_preprocess')) {
                // if available, use generic preprocessor
                $object_broker->instance[$registered_plugin]->router_preprocess();
            }
        }

        if (isset($GLOBALS['layer7_stanza']['message']['text'])) {
            // Interpret text commands and route them to their registered classes
            $object_broker->instance['api_routing']->route_text();
        }
        if (isset($GLOBALS['layer7_stanza']['callback_query']['data'])) {
            // Interpret text commands and route them to their registered classes
            $object_broker->instance['api_routing']->route_text();
        }

    } else {
        $object_broker->instance['api_spaceapi']->process_requests();

        // Invalid JSON encountered (for whatever reason, we don't care).
        // Treat this as plain GET/POST requests

        $logger->debug("getpost:receivePostBody: '$layer6_stanza'");
    }
}

?>
