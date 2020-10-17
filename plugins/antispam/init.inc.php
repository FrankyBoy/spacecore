<?php
/*
 * Copyright (C) 2019, Daniel Haslinger <creo+oss@mesanova.com>
 * This program is free software licensed under the terms of the GNU General Public License v3 (GPLv3).
 */

include_once __DIR__."/../../object_broker.inc.php";

class PLUGIN_ANTISPAM
{

    private OBJECT_BROKER $object_broker;
    private string $classname;
    const ACL_MODE = 'none';    // white, black, none

    public function __construct($object_broker)
    {
        global $config;

        $this->classname = strtolower(static::class);

        $this->object_broker = $object_broker;
        $object_broker->plugins[] = $this->classname;
        $this->object_broker->logger->debug($this->classname . ": starting up");

    }

    public function get_acl_mode()
    {
        return self::ACL_MODE;
    }

    public function router_preprocess_text()
    {
        $this->object_broker->logger->debug($this->classname . ": preprocessing message");

        $chatid = $GLOBALS['layer7_stanza']['message']['chat']['id'];
        $msgid = $GLOBALS['layer7_stanza']['message']['message_id'];
        $text = $GLOBALS['layer7_stanza']['message']['text'];

        $spamarray = array("dating","teiegram.pw");
        $hitcount=0;

        foreach($spamarray as $spamvalue)
        {
            if(substr_count(strtolower($text), $spamvalue) > 0)
            {
                $hitcount++;
            }
        }

        if($hitcount == count($spamarray))
        {
            $this->object_broker->logger->error($this->classname . ": spam detected");
            $this->object_broker->instance['api_telegram']->delete_message($chatid, $msgid);
            $this->object_broker->instance['api_telegram']->send_message($chatid, "SPAM REMOVED. Bad dog! BAD!");
            exit;
        }
    }
}

?>
