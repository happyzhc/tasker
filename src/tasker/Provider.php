<?php


namespace tasker;


class Provider
{
    /**
     *
     * @param $cfg
     */
    public static function moveToList($cfg){
        return;
        //从database移到mysql
        /**@var $db \tasker\Database*/
        $db=Database::getInstance($cfg['database']);
        $redis=Redis::getInstance($cfg['redis']);
        $result=$db->query('select id,payload,dotimes from ' . $cfg['database']['table'] .
            ' where doat<' . time() . ' and dotimes<' . $cfg['retry_count'] .
            ' and startat=0 limit 1000');
        if($result)
        {
            $ids=array_column($result,'id');
            $db->beginTransaction();
            $nums=$db->exce('update ' .
                $cfg['database']['table'] . ' set startat=' . time() .
                ',dotimes=dotimes+1 where startat=0 and id in (' .join(',',$ids). ')');
            if($nums!==count($ids))
            {
                //不一样 还原
                $db->rollBack();
                return;
            }
            foreach ($result as $task)
            {
                $tasker=[
                    'payload'=>$task['payload'],
                    'id'=>$task['id'],
                ];
                $redis->lpush($cfg['redis']['queue_key'],json_encode($tasker));
            }
            $db->commit();
        }
        else{
            //gc
        }
    }

}