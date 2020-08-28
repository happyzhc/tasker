<?php
$start=memory_get_usage();

echo 'start',memory_get_usage()-$start,PHP_EOL;
class a{
    public function func(&$a){
        $a=2;
    }
}
$a=new a;
$b=1;
$a->func($b);

echo 'before unset a',memory_get_usage()-$start,PHP_EOL;
unset($a);

echo 'before unset b',memory_get_usage()-$start,PHP_EOL;
unset($b);
echo memory_get_usage()-$start,PHP_EOL;