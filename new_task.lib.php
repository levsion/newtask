<?php

class New_task
{

    public static function get_type()
    {
        return array(
            1  => '订单提醒支付', //下单成功后五分钟
            2  => '订单支付超时', //下单成功后十分钟
            3  => '订单确认完成', //教练确认接单后特定时间或者续单支付完成后
            4  => '服务没时间通知', //编辑时间后特定时间
            5  => '服务下线', //发送没时间通知后72小时
            6  => '优惠券到期', //每天跑的脚本的时候
            7  => '服务开始前两小时通知', //教练确认接单后特定时间
            8  => '通知服务者确认接单', //支付完成后十分钟
            9  => '通知客户续单', //开始前两小时通知后的特定时间
            10 => '即刻约取消订单', //发即刻约后十分钟
            11 => '解锁服务时间', //下订单过程中提前锁定服务时间
            12 => '系统确认接单', //通知服务者接单后20分钟
            13 => '24小时之后打款', //订单完成时候
            14 => '向协商中用户发送服务已删除', //服务删除的时候
            15 => '系统取消接单', //支付完成后30分钟或者服务者订单完成后30分钟
            16 => '提示协商关闭', //打开协商后一小时四十五分
            17 => '协商关闭', //提示协商关闭后15分钟
            18 => '默认好评', //
            19 => '排期改为协商删除时间', //修改后10秒(编辑服务、上下线服务、删除服务)
            20 => '抢单后10分钟内不支付订单取消', //第一个人抢单后十分钟
            21 => '服务者十分钟未确认给客服发短信', //支付后十分钟
            22 => '服务者5分钟未响应发送提醒',
            23 => '即刻约10分钟提醒客户',
            24 => '服务自动开始',
        );
    }

    /**
     * @param int type:	类型，看get_type方法，
     * @param string func_name:调用的方法名，如New_task::add_task
     * @param array argv_arr:一维参数数组，依次从头开始
     * @param datetime/int task_time:执行时间，datetime或者时间戳，如2016-01-20 14:00:00
     * @return bool true/false
     */
    public static function add_task($type, $func_name, $argv_arr, $task_time, $task_key = '')
    {
        $type      = intval($type);
        $func_name = comm_str_to_safe($func_name);
        $argvs     = mysql_escape_string(json_encode($argv_arr));
        $task_time = mysql_escape_string($task_time);
        $task_key  = mysql_escape_string($task_key);

        if (preg_match('/^\d+$/', $task_time))
        {
            $task_time = date('Y-m-d H:i:s', $task_time);
        }

        if($type==19 || $type==4)
        {//del_model_time此功能已移除
            return true;
        }

        $sql = "insert into tbl::market_task set task_key='{$task_key}',type={$type},func_name='{$func_name}',argvs='{$argvs}',task_time='{$task_time}',add_time=now()";
        mydb_connect("pub:market_task.market_task");
        return mydb_query($sql, 'result');
    }

    public static function del_task_key($task_key, $type)
    {
        $sql = "delete from tbl::market_task where task_key='{$task_key}' and type={$type}";
        mydb_connect("pub:market_task.market_task");
        return mydb_query($sql, 'result');
    }

    //通知服务者去确认接单
    public static function notice_coach_confirm($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');

        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 3)
        {
            return false;
        }
        //系统默认接单
        //New_task::add_task(12,'New_task::system_confirm_order',array($order_no),time()+Market::SYSTEM_CONFIRM-Market::NOTICE_CONFIRM);
        if ($order_info['is_locked'] == 1)
        {
            return true;
        }

        load_library('pub:msg');
        msg::send_sys_msg($order_info['coach_uid'], $order_info['uid'], $order_info['order_no'], $order_info['coach_uid'], array('type' => 'unconfirmed_market_order', 'title' => $order_info['service_title'], 'time' => $order_info['duration']), true, 10000, 100, 1);

        load_library('pub:meilian5c');
        $coach_user_info = userdata_get($order_info['coach_uid'], 'phone_number,phone_code,nickname,is_vest,login_version');
        $customer_user_info = userdata_get($order_info['uid'], 'nickname');

        if ($coach_user_info['login_version'] >= 72)
        {
            $content         = $customer_user_info['nickname'].'已支付'.$order_info['price'].'元约您，请于10分钟内打开美丽约确认订单并回复用户，多次超时不确认，您的服务将被系统自动下线。';
            Meilian5c::send_sms($coach_user_info['phone_number'], $coach_user_info['phone_code'], $content);
        }
        else
        {
            $content = '您有尚未确认的订单：“'.$order_info['service_title'].'”，请速打开美丽约处理。';
            Meilian5c::send_sms($coach_user_info['phone_number'],$coach_user_info['phone_code'],$content);
        }


        if ($coach_user_info['is_vest'] == 0 && $coach_user_info['login_version'] < 66)
        {
            //通知客服
            $kf_content = '【美丽约客服提醒】' . $order_info['coach_uid'] . '，' . $coach_user_info['nickname'] . '，未确认订单超过十分钟，快去查看订单提醒约会者及时确认订单';
            //Meilian5c::send_sms('15821653805', '+86', $kf_content);
            //Meilian5c::send_sms('18616773810', '+86', $kf_content);
        }

        return true;
    }

    public static function notice_kf_confirm($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');

        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 3)
        {
            return false;
        }
        if ($order_info['is_locked'] == 1)
        {
            return true;
        }

        load_library('pub:meilian5c');
        $coach_user_info = userdata_get($order_info['coach_uid'], 'phone_number,phone_code,nickname,is_vest,login_version');
        if ($coach_user_info['is_vest'] == 0 && $coach_user_info['login_version'] >= 66)
        {
            //通知客服
            $kf_content = '【美丽约客服提醒】' . $order_info['coach_uid'] . '，' . $coach_user_info['nickname'] . '，未确认订单超过十分钟，快去查看订单提醒约会者及时确认订单';
            //Meilian5c::send_sms('15821653805', '+86', $kf_content);
            //Meilian5c::send_sms('18616773810', '+86', $kf_content);
        }

        return true;
    }

    //系统帮接单
    public static function system_confirm_order($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');

        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 3)
        {
            return false;
        }
        $rs = Market::confirm_order($order_no, $order_info, 1);
        return $rs;
    }

    //服务开始前通知
    public static function notice_user_begin($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        load_helper('pub:market');

        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 8)
        {
            return false;
        }
        if ($order_info['is_locked'] == 1)
        {
            return true;
        }
        $relate_info = $order_info['relate_info'];
        if (abs(time() + 7200 - strtotime($order_info['begin_time'])) > 60 && $relate_info['is_edit'] == 1 && (!isset($relate_info['is_edit_time']) || $relate_info['is_edit_time'] == 1))
        {//超过一分钟表示此订单修改过开始时间，改任务作废
            return true;
        }
        load_library('pub:msg');
        msg::send_sys_msg($order_info['coach_uid'], $order_info['uid'], $order_info['order_no'], $order_info['coach_uid'], array('type' => 'before_market_order', 'title' => $order_info['service_title'], 'time' => 2), true, 10000, 100, 1);
        msg::send_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_info['order_no'], $order_info['coach_uid'], array('type' => 'user_before_market_order', 'title' => $order_info['service_title'], 'time' => 2), true, 10000, 100, 1);

        $customer_user_info = userdata_get($order_info['uid'], 'phone_number,phone_code,login_version');
        $coach_user_info = userdata_get($order_info['coach_uid'], 'phone_number,phone_code,login_version');
        load_library('pub:meilian5c');

        if ($customer_user_info['login_version'] >= 72)
        {
            $total_time      = date("m-d H:i",strtotime($order_info['begin_time'])).'-'.date("H:i",strtotime($order_info['end_time']));
            $content         = '温馨提示：您购买的服务“' . $order_info['service_title'] . '”，还有2小时就要开始啦。见面地点：'.$order_info['gym_addr'].'，服务时间：' . $total_time . '，请准备出发吧~！';
            $rs = Meilian5c::send_sms($customer_user_info['phone_number'], $customer_user_info['phone_code'], $content);
        }
        else
        {
            $content = '您购买的约会'.$order_info['service_title'].'2小时后将开始,约会开始时间:'.$order_info['begin_time'].',约会地点:'.$order_info['gym_addr'].'。建议您提前出发。如有疑问，请联系客服'.Market_help::KF_NUMBER;
            $rs = Meilian5c::send_sms($coach_user_info['phone_number'],$coach_user_info['phone_code'],$content);
        }

        if ($coach_user_info['login_version'] >= 72) {
            $content = '您有1个订单“' . $order_info['service_title'] . '”' . '2小时后将开始，服务时间：' . $total_time . '，' . $order_info['duration'] . '小时，见面地点：' . $order_info['gym_addr'] . '。请您提前出发并提醒客户，不要迟到哦！';
            Meilian5c::send_sms($coach_user_info['phone_number'], $coach_user_info['phone_code'], $content);
        }
        else
        {
            $content = '您有1个订单2小时后将开始约会，约会:'.$order_info['service_title'].','.$order_info['duration'].'小时，约会开始时间:'.$order_info['begin_time'].',约会地点:'.$order_info['gym_addr'].'。建议您提前出发并提醒客户。';
            $rs = Meilian5c::send_sms($coach_user_info['phone_number'],$coach_user_info['phone_code'],$content);
        }
        return $rs;
    }

    //提醒添加服务时间
    public static function notice_coach_time($coach_uid, $service_id)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $coach_uid  = intval($coach_uid);
        $service_id = intval($service_id);

        $service_info = Coach::get_my_service($coach_uid, $service_id);
        if (empty($service_info) || $service_info['status'] != 1)
        {
            return false;
        }
        $sql = "select begin_time from tbl::coach_time where coach_uid={$coach_uid} and service_id={$service_id} and status=0 order by begin_time desc limit 1";
        mydb_connect("pub:coach_time.coach_time");
        $row = mydb_query($sql, 'row');
        if (empty($row) || $row['begin_time'] < date('Y-m-d H:i:s'))
        {
            load_library('pub:meilian5c');
            $user_info  = userdata_get($coach_uid, 'phone_number,phone_code');
            $content    = '由于您的服务' . $service_info['title'] . '，没有可预约的时间，已被系统自动下线，请及时设置以免影响接单。';
            $rs         = Meilian5c::send_sms($user_info['phone_number'], $user_info['phone_code'], $content);
            load_library('pub:message');
            $notify_arr = array('callback_open' => 'market_new_time', 'push_content' => $content);
            Message::notify('market_new_time', 10000, $coach_uid, json_encode($notify_arr));
            //72小时下线
            $task_time  = time() + 2; //+Market::OFFLINE_HOUR*3600;
            New_task::add_task(5, 'New_task::set_service_offline', array($coach_uid, $service_id), $task_time);
            return $rs;
        }
        return false;
    }

    //系统将服务下线
    public static function set_service_offline($coach_uid, $service_id)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $coach_uid  = intval($coach_uid);
        $service_id = intval($service_id);

        $service_info = Coach::get_my_service($coach_uid, $service_id);
        if (empty($service_info) || $service_info['status'] != 1)
        {
            return false;
        }
        $sql = "select begin_time from tbl::coach_time where coach_uid={$coach_uid} and service_id={$service_id} order by begin_time desc limit 1";
        mydb_connect("pub:coach_time.coach_time");
        $row = mydb_query($sql, 'row');
        if (empty($row) || strtotime($row['begin_time']) < time())
        {
            Coach::update_my_service($coach_uid, $service_id, array('is_online' => 0));
            load_library('pub:meilian5c');
            $user_info = userdata_get($coach_uid, 'phone_number,phone_code');
            $content   = '由于您的服务“' . $service_info['title'] . '”没有可预约的时间，已被系统自动下线，请及时设置以免影响接单。';

            $rs         = Meilian5c::send_sms($user_info['phone_number'], $user_info['phone_code'], $content);
            load_library('pub:notification');
            Notification::add(array(
                'uid'         => $coach_uid,
                'type'        => 'coachTimePage',
                'relate_info' => array('title' => $service_info['title'])
            ));
            $coach_info = Coach::get_coach_info($coach_uid);
            if ($coach_info['is_employee'] == 1)
            {
                $coach_user_info = userdata_get($coach_uid, 'nickname');
                $content         = "公司伴伴:" . $coach_user_info['nickname'] . "（UID：" . $coach_uid . "），" . $service_info['title'] . "约会没有服务时间，已被下线，请注意查看。";
                Meilian5c::send_sms('18801733357', '+86', $content);
            }
            $task_time = time() + 10;
            New_task::add_task(19, 'New_task::del_model_time', array($coach_uid, $service_id), $task_time);
            return $rs;
        }
        return false;
    }

    /**
     * 处理当前即刻约,如果时间到就取消  66版本以前的用法
     * @param int speedy_id 即刻约编号 1
     * @return array
     */
    public static function cron_check_speedy_order($speedy_id)
    {
        $return        = false;
        load_library('pub:speedy');
        $speedy_detail = Speedy::get_speedy_detail($speedy_id);
        if (!empty($speedy_detail))
        {
            if (!$speedy_detail['coach_uid'])
            {
                Speedy::update_speedy_status($speedy_id, 2);
                $return = true;
            }
        }

        return $return;
    }

    //检查取消即刻约
    public static function check_speedy_cancel($speedy_id)
    {
        load_library('pub:speedy');
        $speedy_info = Speedy::get_speedy_info($speedy_id);
        if (in_array($speedy_info['status'],array(0,1,4)))
        {
            $rs = Speedy::system_cancel_speedy($speedy_id);
            return $rs;
        }
        return false;
    }

    //选择时间达人超时取消
    public static function check_speedy_grab($speedy_id)
    {
        load_library('pub:speedy');
        $speedy_info = Speedy::get_speedy_info($speedy_id);
        if ($speedy_info['status'] == 1 || $speedy_info['status'] == 4)
        {
            $rs = Speedy::system_cancel_grab($speedy_id);
            return $rs;
        }
        return false;
    }

    //订单完成
    public static function finish_order($uid, $order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $order_info = Market::get_order($order_no);
        $coach_user_info = userdata_get($order_info['coach_uid'], 'login_version');
        if(!in_array($order_info['status'],array(8))) //订单已经完成
        {
            return true;
        }
        if(72 <= $coach_user_info['login_version'])
        {
            //V72 如果用戶未點完成訂單,需等24小時后自動完成
            load_library('pub:msg');
            //立刻完成
            Market::finish_order($order_info['uid'], $order_no);
            $_sys_data = array(
                'order_status_title' => '订单已完成',
                'order_status_color' => '#fc6676',
                "button_type"        => 1,
                'type'               => 'user_end_market_order_66',
                'background'         => '#ffffff',
                'content'            => "服务结束时间已过24小时，订单自动完成，她正等着您的评价\n如对服务满意，",
                'show_txt'           => '可点击此处收藏她的服务。',
                'show_url'           => 'mlyaction:collect_service?service_id='.$order_info['service_id'].'&coach_uid='.$order_info['coach_uid']
            );
            $coach_income    = Market::coach_income_advance($order_no);
            Msg::send_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, $order_info['coach_uid'], $_sys_data, true, 10000, 100, 1, array('type' => 7));
            $_sys_data = array(
                'order_status_title' => '订单已完成',
                'order_status_color' => '#fc6676',
                "button_type"        => 1,
                'type'               => 'end_market_order_66',
                'background'         => '#ffffff',
                'content'            => '本次服务已结束，收入¥' . round($coach_income, 2) . '将在24小时后到账。请注意查收。',
            );
            Msg::send_sys_msg($order_info['coach_uid'], $order_info['uid'], $order_no, $order_info['coach_uid'], $_sys_data, true, 10000, 100, 1, array('type' => 7));
            Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['coach_uid']);
            Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['uid']);
            return true;
        }
        else
        {
            load_library('pub:market');
            $rs = Market::finish_order($uid, $order_no);
            return $rs;
        }
        
    }

    //支付超时
    public static function order_timeout($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        load_library('pub:msg');
        load_library('pub:group_msg');

        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 0)
        {
            return false;
        }
        $delete_model_order = false;
        if ($order_info['model'] > 0)
        {
            $tmp_info = Market::get_tmp_info($order_no);
            if ($tmp_info['status'] == 0)
            {
                $delete_model_order = true;
                //$rs = Market::delete_order($order_no);//下面还会读到这个order所以在最后删除
            }
            else
            {
                $rs = Market::update_order($order_no, array('status' => 5));
            }
        }
        else
        {
            $rs = Market::update_order($order_no, array('status' => 5));
            if ($order_info['speedy_id'] > 0)
            {
                $db_data = array(
                    'order_no' => $order_no,
                    'status'   => 2
                );
                Market::save_tmp_info($db_data);
            }
        }

        if ($rs)
        {
            //unlock time
            Market::release_order_time($order_no);
            //变更 服务状态
            Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['coach_uid']);
            Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['uid']);

            if (!empty($order_info['group_order']))
            {
                $order_arr = explode(',', $order_info['group_order']);
                foreach ($order_arr as $order_no_now)
                {
                    $order_info_now = Market::get_order($order_no_now);
                    Market::update_order($order_no_now, array('status' => 5));
                    //unlock time
                    if ($order_info_now['speedy_id'] > 0)
                    {
                        Coach::unlock_speedy_time($order_no_now, $order_info_now);
                    }
                    else
                    {
                        Coach::unlock_coach_time($order_info_now['coach_uid'], $order_info_now['begin_time'], $order_info_now['duration']);
                    }
                    //变更 服务状态
                    Market::send_order_sys_msg($order_info_now['uid'], $order_info_now['coach_uid'], $order_no_now, 100, $order_info_now['coach_uid']);
                    Market::send_order_sys_msg($order_info_now['uid'], $order_info_now['coach_uid'], $order_no_now, 100, $order_info_now['uid']);
                }
            }
        }
        if ($delete_model_order)
        {
            Market::delete_order($order_no);
        }
        return true;
    }

    public static function notice_extend_order($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 8)
        {
            return false;
        }
        $relate_info = $order_info['relate_info'];
        if (abs(time() - strtotime($order_info['end_time'])) > 60 && $relate_info['is_edit'] == 1 && (!isset($relate_info['is_edit_time']) || $relate_info['is_edit_time'] == 1))
        {//超过一分钟表示此订单修改过订单时间，改任务作废
            return true;
        }
        if ($order_info['is_locked'] == 0 && strtotime($order_info['end_time']) <= (time() + 30))
        {
            $coach_user_info = userdata_get($order_info['coach_uid'], 'login_version');
            load_library('pub:msg');
            $coach_income    = Market::coach_income_advance($order_no);
            if ($coach_user_info['login_version'] < 66)
            {
                msg::send_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_info['order_no'], $order_info['coach_uid'], array('type' => 'user_end_market_order', 'title' => $order_info['service_title'], 'time' => $order_info['duration']), true, 10000, 100, 1);
                msg::send_sys_msg($order_info['coach_uid'], $order_info['uid'], $order_info['order_no'], $order_info['coach_uid'], array('type' => 'end_market_order', 'title' => $order_info['service_title'], 'time' => $order_info['duration'], 'money' => $coach_income), true, 10000, 100, 1);
                $task_time = strtotime($order_info['end_time']) + Market::ORDER_FINISH;
                New_task::add_task(3, 'New_task::finish_order', array($order_info['uid'], $order_no), $task_time, $order_no);
            }
            else
            {
                if(72 <= $coach_user_info['login_version'])
                {
                    //V72 如果用戶未點完成訂單,需等24小時后自動完成
                    load_helper('pub:market');
                    $chat_content['type']        = "72_order_filish_notice";
                    $chat_content['kf']          = Market_help::KF_NUMBER;
                    msg::send_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_info['order_no'], $order_info['coach_uid'], $chat_content, true, 10000, 100, 1, array('background' => '#fc6676'));
                    $task_time = strtotime($order_info['end_time']) + Market::ORDER_AUTO_FINISH;
                    load_library('pub:speedy');
                    $test_uid = Speedy::get_test_uid();
                    if(in_array($order_info['coach_uid'], $test_uid))
                    {
                        $task_time = strtotime($order_info['end_time']) + 600; 
                    }
                    New_task::add_task(3, 'New_task::finish_order', array($order_info['uid'], $order_no), $task_time, $order_no);
                }
                else
                {
                    //立刻完成
                    Market::finish_order($order_info['uid'], $order_no);
                    $_sys_data = array(
                        'order_status_title' => '订单已完成',
                        'order_status_color' => '#fc6676',
                        "button_type"        => 1,
                        'type'               => 'user_end_market_order_66',
                        'background'         => '#ffffff',
                        'content'            => '本次约会已结束，TA就要离开，可点击上方“再来一单”按钮挽留',
                    );
                    Msg::send_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, $order_info['coach_uid'], $_sys_data, true, 10000, 100, 1, array('type' => 7));
                    $_sys_data = array(
                        'order_status_title' => '订单已完成',
                        'order_status_color' => '#fc6676',
                        "button_type"        => 1,
                        'type'               => 'end_market_order_66',
                        'background'         => '#ffffff',
                        'content'            => '本次约会已结束，收入¥' . $coach_income . '将在24小时后到账。请注意查收。',
                    );
                    Msg::send_sys_msg($order_info['coach_uid'], $order_info['uid'], $order_no, $order_info['coach_uid'], $_sys_data, true, 10000, 100, 1, array('type' => 7));
                }
                Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['coach_uid']);
                Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['uid']);
            }
        }
        return true;
    }

    public static function notice_coupon_expired($uid)
    {
        $uid      = intval($uid);
        $next_day = date('Y-m-d 24:00:00', time() + 3600 * 24);
        $sql      = "select count(*) as count from tbl::user_coupon where uid={$uid} and status=0 and end_time<'{$next_day}'";
        mydb_connect("pub:market_user_coupon.market_user_coupon", $uid);
        $row      = mydb_query($sql, 'row');
        if ($row['count'] > 0)
        {
            load_library('pub:notification');
            Notification::add(array(
                'uid'         => $uid,
                'type'        => 'couponList',
                'relate_info' => array()
            ));
            return true;
        }
        return false;
    }

    public static function finish_order_pay($order_no)
    {
        if (empty($order_no))
        {
            return false;
        }
        load_library('pub:market');
        Market::finish_order_pay($order_no);
        return true;
    }

    public static function notice_service_del($coach_uid, $service_id)
    {
        $coach_uid  = intval($coach_uid);
        $service_id = intval($service_id);
        load_library('pub:coach');
        Coach::notice_service_del($coach_uid, $service_id);
        return true;
    }

    public static function system_unable_order($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 3)
        {
            return false;
        }
        $rs = Market::unable_order($order_no, $order_info, '');
        return $rs;
    }

    public static function notice_close_chat($order_no)
    {
        if (empty($order_no))
        {
            return false;
        }
        load_library('pub:market');
        load_library('pub:coach');
        load_library('pub:msg');
        $tmp_info = Market::get_tmp_info($order_no);
        if ($tmp_info === false || $tmp_info['status'] > 0)
        {
            return false;
        }
        $uid       = $tmp_info['uid'];
        $coach_uid = $tmp_info['coach_uid'];

        //判断是否已经有聊天过
        $msg_arr = msg::get_detail_ret($coach_uid, $uid, $order_no, $coach_uid, 0, $sync    = 1, 100);
        if (empty($msg_arr[0]))
        {
            //直接关闭聊天
            $db_data = array(
                'order_no' => $order_no,
                'status'   => 2
            );
            Market::save_tmp_info($db_data);
            //变更 服务状态
            Market::send_order_sys_msg($tmp_info['uid'], $tmp_info['coach_uid'], $order_no, 100, $tmp_info['coach_uid']);
            return true;
        }
        load_library('pub:notification');
        Notification::add(array(
            'uid'         => $coach_uid,
            'type'        => 'notice_close_chat',
            'relate_info' => $tmp_info,
        ));
        Notification::add(array(
            'uid'         => $uid,
            'type'        => 'notice_close_chat',
            'relate_info' => $tmp_info,
        ));

        msg::send_sys_msg($tmp_info['uid'], $tmp_info['coach_uid'], $tmp_info['order_no'], $tmp_info['coach_uid'], array('type' => 'notice_close_chat', 'title' => $tmp_info['service_title']), true, 10000, 100, 1);
        msg::send_sys_msg($tmp_info['coach_uid'], $tmp_info['uid'], $tmp_info['order_no'], $tmp_info['coach_uid'], array('type' => 'notice_close_chat', 'title' => $tmp_info['service_title']), true, 10000, 100, 1);
        $task_time = time() + 15 * 60;
        New_task::add_task(17, 'New_task::close_chat', array($order_no), $task_time);
        return true;
    }

    public static function close_chat($order_no)
    {
        if (empty($order_no))
        {
            return false;
        }
        load_library('pub:market');
        load_library('pub:coach');
        load_library('pub:group_msg');
        $tmp_info = Market::get_tmp_info($order_no);
        if ($tmp_info === false || $tmp_info['status'] != 0)
        {
            return false;
        }
        $db_data = array(
            'order_no' => $order_no,
            'status'   => 2
        );
        Market::save_tmp_info($db_data);
        //变更 服务状态
        Market::send_order_sys_msg($tmp_info['uid'], $tmp_info['coach_uid'], $order_no, 100, $tmp_info['coach_uid']);
        Market::send_order_sys_msg($tmp_info['uid'], $tmp_info['coach_uid'], $order_no, 100, $tmp_info['uid']);
        return true;
    }
    //V72 服务器开始时间到了后通知客户端改变状态
    public static function notice_service_start($order_no,$time)
    {
        if(empty($order_no))
        {
            return false;
        }
        load_library('pub:market');
        $order_info = Market::get_order($order_no);
        if ($order_info == false || $order_info['status']!=8)
        {
            return false;
        }
        if (strtotime($order_info['begin_time']) != $time)
        {
            return false;
        }
        //变更 服务状态
        Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['coach_uid']);
        Market::send_order_sys_msg($order_info['uid'], $order_info['coach_uid'], $order_no, 100, $order_info['uid']);
        return true;
    }
    
    public static function default_good_comment($order_no)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $order_no   = comm_str_to_safe($order_no);
        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }
        if ($order_info['status'] != 13 || $order_info['is_comment'] == 1)
        {
            return false;
        }
        Market::default_good_comment($order_no, $order_info);
        return true;
    }

    public static function del_model_time($coach_uid, $service_id)
    {
        load_library('pub:market');
        load_library('pub:coach');
        $coach_uid  = intval($coach_uid);
        $service_id = intval($service_id);

        if (empty($coach_uid) || empty($service_id))
        {
            return false;
        }
        $service_info = Coach::get_my_service($coach_uid, $service_id);
        if ($service_info['model'] > 0 || $service_info['status'] != 1 || $service_info['is_online'] == 0)
        {
            Coach::del_model_time($coach_uid, $service_id, $service_info);
        }
        return true;
    }

    public static function notice_service_aremind($order_no)
    {
        load_library('pub:market');
        load_library('pub:msg');
        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }

        $uid       = $order_info['uid'];
        $coach_uid = $order_info['coach_uid'];
        $coach_user_info = userdata_get($coach_uid, 'login_version');

        $msg_arr = msg::get_detail_ret($coach_uid, $uid, $order_no, $coach_uid, 0, $sync    = 1, 100);
        if (!empty($msg_arr[0]) && in_array($order_info['status'], array(3, 8)))
        {
            $msg_flag = true;
            foreach ($msg_arr[0] as $msg)
            {
                if ($msg['send_uid'] == $coach_uid && $msg['is_system'] == 0 && $msg['type'] == 0)
                {
                    $msg_flag = false;
                    break;
                }
            }

            if ($msg_flag)
            {
                if($coach_user_info['login_version'] >= 72)
                {
                    $contentinfo = array(
                        "type"               => "",
                        "button_type"        => 1,
                        "order_status_title" => "超时未主动联系",
                        "order_status_color" => '#fc6676',
                        'content'            => '您已经超过5分钟没有主动联系对方，若再不联系订单将推送至其他服务者，您可能错过这笔订单。',
                    );
                }
                else
                {
                    $contentinfo = array(
                        "type"               => "",
                        "button_type"        => 1,
                        "order_status_title" => "订单提醒",
                        "order_status_color" => '#fc6676',
                        'content'            => '请尽快和用户联系,别让ta等太久哦',
                    );
                }

                Msg::send_sys_msg($coach_uid, $uid, $order_no, $coach_uid, $contentinfo, true, 10000, 100, 1, array("type" => 7));
            }
        }
    }

    public static function notice_speedy_10m($order_no)
    {
        load_library('pub:market');
        load_library('pub:msg');
        $order_info = Market::get_order($order_no);
        if (empty($order_info))
        {
            return false;
        }

        $uid       = $order_info['uid'];
        $coach_uid = $order_info['coach_uid'];

        $msg_arr = msg::get_detail_ret($coach_uid, $uid, $order_no, $coach_uid, 0, $sync    = 1, 100);
        if (!empty($msg_arr[0]) && in_array($order_info['status'], array(3, 8)))
        {
            $msg_flag = true;
            foreach ($msg_arr[0] as $msg)
            {
                if ($msg['send_uid'] == $coach_uid && $msg['is_system'] == 0 && $msg['type'] == 0)
                {
                    $msg_flag = false;
                    break;
                }
            }

            if ($msg_flag)
            {
                $contentinfo = array(
                    "type"               => "",
                    "button_type"        => 1,
                    "order_status_title" => "对方未及时响应",
                    "order_status_color" => '#fc6676',
                    'content'            => '对方已经超过10分钟未主动联系您，您可以取消订单并重新发布即刻约，或继续等待对方响应。如取消，已支付金额将即刻退回您的美丽约账户。',
                );
                Msg::send_sys_msg($uid, $coach_uid, $order_no, $coach_uid, $contentinfo, true, 10000, 100, 1, array("type" => 7));
            }
        }
    }

}
