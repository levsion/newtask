<?php
/**
 * Created by PhpStorm.
 * User: levsion
 * Date: 2016/8/5
 * Time: 2:56 PM
 */

load_library('pub:new_task');
$task_time = time() + 7200;
New_task::add_task(3, 'New_task::finish_order', array($order_info['uid'], $order_no), $task_time, $order_no);