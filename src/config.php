<?php
return [
    'worker_nums'=>2,
    'pid_path'=>'/var/run/fxf_task.pid',
    'stdout_path'=>null,
    'master_title'=>'task_master_process',
    'worker_title'=>'task_worker_process',
    'tasker_user'=>'www',
    'stop_worker_timeout'=>5,//关闭子进程超时时间 超过这个时间 会强制结束
    'hot_update_path'=>[//要监听热更新的目录 会重启worker进程

    ],
    'hot_update_interval'=>5,//热更新目录检查间隔 秒

    'retry_count'=>10,//任务失败 重试次数

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
