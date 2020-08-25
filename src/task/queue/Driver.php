<?php


namespace task\queue;


interface Driver
{
    public function fire();
}