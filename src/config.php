<?php
return [
    'worker_nums'=>5,
    'pid_path'=>'/var/run/ctt123_task.pid',
    'stdout_path'=>'/dev/null',
    'master_title'=>'task_master_process',
    'worker_title'=>'task_worker_process',
    'stop_worker_timeout'=>10,//关闭子进程超时时间 超过这个时间 会强制结束
    'hot_update_path'=>[//要监听热更新的目录 会重启worker进程

    ],

    'retry_count'=>10,//任务失败 重试次数
    'queue_type'=>'database',

    'database'=>[
        'host'=>'127.0.0.1',
        'db'=>'task',
        'user'=>'task',
        'pwd'=>'123456',
        'port'=>3306,
        'table'=>'task',
        'charset'=>'utf8'
    ],
    'redis'=>[
        'host'=>'127.0.0.1',
        'port'=>6379,
        'db'=>0,
        'pwd'=>'',
        'queue_key'=>'task'
    ]
];
