<?php
/*
 * Copyright (C) 2019, Daniel Haslinger <creo+oss@mesanova.com>
 * This program is free software licensed under the terms of the GNU General Public License v3 (GPLv3).
 */

include_once __DIR__."/../../object_broker.inc.php";

class API_ROUTING
{
    private OBJECT_BROKER $object_broker;
    private array $hooks;
    private array $helptexts;
    private string $classname;

    public function __construct($object_broker)
    {
        $this->classname = strtolower(static::class);

        $this->object_broker = $object_broker;
        $object_broker->apis[] = $this->classname;
        $this->object_broker->logger->debug($this->classname .  ": starting up");

        $this->hooks = [];
        $this->helptexts = [];
    }


    public function __destruct()
    {

    }


    public function route_text()
    {
        $message = null;
        $senderid = null;
        if(isset($GLOBALS['layer7_stanza']['message']['text']))
        {
            $message = $GLOBALS['layer7_stanza']['message']['text'];
            $senderid = $GLOBALS['layer7_stanza']['message']['from']['id'];
        }else if(isset($GLOBALS['layer7_stanza']['callback_query']['data'])){
            $this->object_broker->logger->debug($this->classname . ": convert from inline");
            $message = $GLOBALS['layer7_stanza']['callback_query']['data'];
            $senderid = $GLOBALS['layer7_stanza']['callback_query']['from']['id'];
            $GLOBALS['layer7_stanza']['message']['text'] = $message;
            $GLOBALS['layer7_stanza']['message']['chat']['id'] = $senderid;
            $GLOBALS['layer7_stanza']['message']['from'] = $GLOBALS['layer7_stanza']['callback_query']['from'];
        }else{
            $this->object_broker->logger->error($this->classname . ": MESSAGE COULD NOT BE PARSED");
            return;
        }

        if($message[0] == '/')
        {
            $this->object_broker->logger->debug($this->classname . ": command prefix detected");

            // this might be a direct message OR a message sent as a mention,
            // so we deliberately remove the generic mention sequence in order to
            // support both methods of conversation. It ain't much, but it's honest work.
            $message = $GLOBALS['layer7_stanza']['message']['text'] = str_replace('@segvault_spacecorebot', '', $message);
		
            $trigger = ltrim(explode(' ', trim($message))[0], '/');
            $this->object_broker->logger->debug($this->classname . ": command trigger detected: $trigger");

            $classname = $this->resolve($trigger);

            if ($classname)
            {
                $access_permitted = false;

                // check if the class supports ACLs and actually uses them
                if(!method_exists($this->object_broker->instance[$classname], 'get_acl_mode') || $this->object_broker->instance[$classname]->get_acl_mode() != 'none')
                {
                    $list = $this->object_broker->instance[$classname]->get_acl_mode();

                    if($this->acl_check_list($senderid, $classname, $list))
                    {
                        // The user was found on the list. Let's see how this translates to permissions...
                        if ($list == 'black')
                        {
                            // The user is blacklisted
                            $this->object_broker->logger->error($this->classname . ": routing request to $classname for user $senderid refused (blacklisted!)");
                            $access_permitted = false;
                        }
                        elseif ($list == 'white')
                        {
                            // The user is whitelisted
                            $this->object_broker->logger->error($this->classname . ": routing request to $classname for user $senderid accepted (whitelisted!)");
                            $access_permitted = true;
                        }
                    }
                    else
                    {
                        // The user was NOT found on the list. Let's see what how THAT translates to permissions...
                        if ($list == 'black')
                        {
                            $this->object_broker->logger->error($this->classname . ": routing request to $classname for user $senderid accepted (not blacklisted!)");
                            $access_permitted = true;
                        }
                        elseif ($list == 'white')
                        {
                            $this->object_broker->logger->error($this->classname . ": routing request to $classname for user $senderid accepted (not whitelisted!)");
                            $access_permitted = false;
                        }
                    }
                }
                else
                {
                        // the class either does not support ACLs or it does not make use of them -> Free For All.
                        $access_permitted = true;
                }

                if($access_permitted === true)
                {
                    if (method_exists($this->object_broker->instance[$classname], 'process'))
                    {
                        $this->object_broker->logger->debug($this->classname . ": routing request to $classname");
                        $this->object_broker->instance[$classname]->process($trigger);
                    }
                    else
                    {
                        $this->object_broker->logger->error($this->classname . ": the class registered to trigger $trigger is unsupported");
                    }
                }
                else
                {
                    // Feedback to the user
                    $message = "<b>Denied!</b>\n You are not authorized to access this command.\n Ask a moderator for permissions to use $classname and/or use \"/system permissions\" to check your privileges!";
                    $this->object_broker->instance['api_telegram']->send_message($senderid, $message);
                }
            }
            else
            {
                $payload = "Unknown command. Use '/system help' to get more information.";
                $this->object_broker->instance['api_telegram']->send_message($senderid, $payload);
                $this->object_broker->logger->error($this->classname . ": no class registered to trigger: $trigger --> sending help text");
            }
        }
        else
        {
            // that's no command. This branch could facilitate some chatbot feature.
            $this->object_broker->logger->debug($this->classname . ": no chatmode available yet --> ignoring");
        }
    }

    private function to_lower($string)
    {
        if(function_exists('mb_strtolower')){
            return mb_strtolower($string);
        }else{
            return strtolower($string);
        }
    }

    public function register($trigger, $classname, $description = NULL)
    {
        $this->hooks[$trigger] = $classname;
				$trigger_lower = $this->to_lower($trigger);
				if($trigger_lower !== $trigger){
					$this->hooks[$trigger_lower] = $classname;
				}
        $this->hooks[$trigger] = $classname;
        $this->object_broker->logger->debug($this->classname . ": registered class $classname on trigger $trigger");
    }

    public function helptext($trigger, $modifier, $description = NULL)
    {
        $this->helptexts[$trigger][$modifier] = $description;
        $this->object_broker->logger->debug($this->classname . ": registered helptext for trigger $trigger");
    }

    public function resolve($trigger)
    {
        if(isset($this->hooks[$trigger]))
        {
            $classname = $this->hooks[$trigger];
            $this->object_broker->logger->debug($this->classname . ": resolved class $classname from trigger $trigger");
            return $classname;
        }
        else
        {
						$trigger_lower = $this->to_lower($trigger);
						if($trigger_lower !== $trigger){
							$res = $this->resolve($trigger_lower);
							if(! $res ){
								$this->object_broker->logger->error($this->classname . ": could not resolve trigger $trigger");
							}
							return $res;
						}
						$this->object_broker->logger->error($this->classname . ": could not resolve trigger $trigger");
            return false;
        }
    }


    public function get_hooks_map()
    {
        // create a map of all hooks organized by classname as associative array
        $map = [];
        foreach($this->hooks as $trigger => $classname)
        {
            $map[$classname][] = $trigger;
        }

        return $map;
    }

    public function get_help_map()
    {
        // return a map of all help texts organized by classname as associative array
        return $this->helptexts;
    }


    public function acl_check_list($userid, $classname, $list)
    {
        // retrieve the desired list for this class
        $acl = $this->object_broker->datastore->retrieve('acl_' . $list . "_$classname");
        if(!$acl)
        {
            $this->object_broker->logger->error($this->classname . ": no list $list for class $classname");
            return false;
        }
        else
        {
            // decode the list and check if the user is registered to it
            $acl_assoc = json_decode($acl, true);
            if(isset($acl_assoc[$userid]))
            {
                $this->object_broker->logger->debug($this->classname . ": user $userid is listed on $list for class $classname since EPOCH:" . $acl_assoc[$userid]);
                return $acl_assoc[$userid];
            }
            else
            {
                $this->object_broker->logger->debug($this->classname . ": user $userid is not listed on $list for class $classname");
                return false;
            }
        }
    }


    public function acl_modify_list($userid, $classname, $list, $action)
    {
        if(!ctype_digit($userid))
        {
            $this->object_broker->logger->error($this->classname . ": userid $userid is invalid and can not be registered to $classname:$list");
            return false;
        }

        // retrieve the desired list for this class
        $acl = $this->object_broker->datastore->retrieve('acl_' . $list . "_$classname");

        if(!$acl)
        {
            $this->object_broker->logger->error($this->classname . ": no list $list for class $classname");
            $acl_assoc = [];
        }
        else
        {
            // decode the list and check if the user is registered to it
            $acl_assoc = json_decode($acl, true);
        }

        if($action == 'register')
        {
            if(isset($acl_assoc[$userid]))
            {
                $this->object_broker->logger->error($this->classname . ": registration failed - user $userid was already registered to $list for class $classname since EPOCH:" . $acl_assoc[$userid]);
                return false;
            }
            else
            {
                $acl_assoc[$userid] = time();
                $acl = json_encode($acl_assoc);
                $this->object_broker->datastore->store('acl_' . $list . "_$classname", $acl);
                $this->object_broker->logger->error($this->classname . ": registration successful - user $userid is now registered to $list for class $classname");
                return true;
            }
        }
        elseif($action == 'unregister')
        {
            if(isset($acl_assoc[$userid]))
            {
                unset($acl_assoc[$userid]);
                $acl = json_encode($acl_assoc);
                $this->object_broker->datastore->store('acl_' . $list . "_$classname", $acl);
                $this->object_broker->logger->error($this->classname . ": unregister successful - user $userid successfully removed from $list for class $classname");
                return false;
            }
            else
            {
                $this->object_broker->logger->error($this->classname . ": unregister failed - user $userid not found on list $list for class $classname");
            }
        }
    }

}

?>
