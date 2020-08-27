<?php


namespace tasker\process;


abstract class Process
{
    protected $_process_id;
    protected function setProcessTitle($title){
        if (extension_loaded('proctitle') && function_exists('setproctitle')) {
            @setproctitle($title);
        } elseif (version_compare(phpversion(), "5.5", "ge") && function_exists('cli_set_process_title')) {
            @cli_set_process_title($title);
        }
    }
}