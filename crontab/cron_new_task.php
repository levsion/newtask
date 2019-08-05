<?php
/**
 * Created by PhpStorm.
 * User: levsion
 * Date: 2016/8/5
 * Time: 2:56 PM
 */

define('NEW_LOG_PORT','5152');
define('NEW_LOG_CHANNEL','mapi');
define('NEW_LOG_EXECUTE_TIME',0);
define('NEW_LOG_REQUEST',true);
define('NEW_LOG_REQUEST_PARAM',true);
require('script_header.php');

load_library('pub:stat');//避免报错
load_library('sys:new_log');

load_library('pub:new_task');

set_time_limit(0);
echo "\n".date('Y-m-d H:i:s')."开始---\n";

function mysql_rows($sql,$link)
{
	$result = mysql_query($sql,$link);
	$rows = array();
	while($row = mysql_fetch_assoc($result))
	{
		$rows[]=$row;
	}
	return $rows;
}
function mysql_row($sql,$link)
{
	$result = mysql_query($sql,$link);
	if(!$result)
	{
		new_log::log($link.'--'.$sql,'new_task_error');
	}
	return mysql_fetch_assoc($result);
}
$task_db='meiliyue_db_market_task';
$task_link = _connect_meiliyue('10.1.1.14', $task_db);
while(true)
{
	$t = time();
	if(!is_resource($task_link) || $t%10==0 || empty($task_link))
	{
		$task_link = _connect_meiliyue('10.1.1.14', $task_db);
	}

	$now = date('Y-m-d H:i:s');
	$delay_second = 30;
	$now_end = date('Y-m-d H:i:s',time()-$delay_second);
	$sql = "select * from t_market_task where status=0 and task_time>='{$now_end}' and task_time<='{$now}' order by task_time asc limit 1";
	$row = mysql_row($sql,$task_link);
	if($row && strtotime($row['task_time'])>=time()-$delay_second)
	{
		$func_name = $row['func_name'];
		$argvs = json_decode($row['argvs'],true);
		$task_id = $row['id'];
		$rs = call_user_func_array($func_name,$argvs);
		if($rs)
		{
			$task_msg = '';
		}else{
			$task_msg = 'false';
		}
		$task_link = _connect_meiliyue('10.1.1.14', $task_db);
		$run_time = date('Y-m-d H:i:s');
		$update_sql = "update t_market_task set status=1,task_msg='{$task_msg}',run_time='{$run_time}' where id={$task_id}";
		mysql_query($update_sql,$task_link);
		new_log::log($func_name.'--'.$task_id.'--'.$task_msg,'new_task');
		unset($row);
		usleep(100000);
		continue;
	}
	unset($row);
	usleep(500000);
}

