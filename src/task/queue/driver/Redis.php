<?php


namespace task\queue\driver;

use task\queue\Driver;

/**
 * Class Redis
 * redis驱动
 *
 * 任务的状态
 * 1 list       key为 queue_key          待执行的任务列表（及立即执行任务不会进入到2状态）
 * 2 sortedset  key为 queue_key:delay    定时任务集合
 *
 * value为payload
 *
 * 流程
 *
 * ->从delay集合中取出到期的放入list尾部
 * ->list安顺序取出执行
 * ->执行失败在payload中跟新尝试次数并重新放入list尾部
 * ->达到失败上限的抛出异常给外部记录日志
 * ->完
 *
 * @package task\queue
 */

class Redis implements Driver
{


}