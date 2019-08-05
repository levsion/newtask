<?php
/**
 * Created by PhpStorm.
 * User: levsion
 * Date: 2016/8/5
 * Time: 2:56 PM
 */

require('script_header.php');


// 凌晨4点到4点05分 脚本关闭
list($hour, $min, $second) = explode(':', date('H:i:s'));
$hour = intval($hour);
$min = intval($min);
if($hour==5 && $min==1 && $second<10)
{
    die;
}
if(!check_task_run())
{
    load_library('pub:meilian5c');
    Meilian5c::send_sms(18621662051,'+86','new_task文件已重启');
    $pid = start_task_queue(); 
    if($pid != "")
    {
        echo date('Y-m-d H:i:s')." Start new_task.php success! pid:{$pid} \n";
    }
    else
    {
        echo date('Y-m-d H:i:s'). " Start new_task.php failed! \n";
    }
}

exit;

function start_task_queue()
{
	$command = '/usr/local/php5/bin/php /app/miliyo.com/crontab/cron_new_task.php > /tmp/new_task.log 2>/tmp/new_task_error.log & echo $!'; 
    exec($command ,$op); 
    $pid = (int)$op[0];

	return (!empty($pid)) ? $pid : false;
}

function check_task_run()
{
	exec('ps aux | grep new_task.php', $res, $rc);
	if(!empty($res))
	{
		foreach($res as $v)
		{
			if(false !== strpos($v, '/app/miliyo.com/crontab/cron_new_task.php')) return true;
		}
	}

	return false;
}

