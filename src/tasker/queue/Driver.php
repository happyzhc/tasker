<?php


namespace tasker\queue;


interface Driver
{
    public function fire();
}