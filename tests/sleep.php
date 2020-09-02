<?php

require_once dirname(__FILE__).'/../vendor/autoload.php';
$stime=time();
\tasker\Op::sleep(3);
echo time()-$stime;