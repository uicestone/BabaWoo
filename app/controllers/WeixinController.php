<?php

class WeixinController extends BaseController {
	
	/*
	 * 微信API响应页面，用来处理来自微信的请求
	 */
	public function serve()
	{
		$weixin = new Weixin();
		
		if(Request::get('echostr')){
			return $weixin->verify();
		}
		
		// 获得附近站点的收藏线路的车辆实时状态
		$weixin->onMessage(array('event', 'location'), function($message, $user)
		{
			$latlng = BaiduMap::geoConv(array($message->latitude, $message->longitude));
			$nearByStops = Stop::nearBy($latlng[0], $latlng[1])->distanceAscending($latlng[0], $latlng[1])->get();
			
			$reply_text = '';
			
			if(!$user->favorite)
			{
				return;
			}
			
			foreach($nearByStops as $stop)
			{
				foreach($stop->lines as $line)
				{
					if(in_array($line->pivot->id, $user->favorite->line_stop))
					{
						$reply_text .= Shjtmap::vehicleMonitor($line, $stop);
					}
				}
			}
			
			if($reply_text)
			{
				replyMessage($reply_text);
			}
			
		});
		
		// 获得附近所有车站和线路，创造回复列表并等候回复
		$weixin->onMessage(array('event', 'click'), function($message, $user)
		{
			if($message->meta->EventKey !== 'GET_NEAR_BY_LINES')
			{
				return;
			}
			
			// 查找用户周围车站
			$latlng = BaiduMap::geoConv(array($user->latitude, $user->longitude));
			$nearByStops = Stop::nearBy($latlng[0], $latlng[1])->distanceAscending($latlng[0], $latlng[1])->get();
			
			$reply_text = ''; $line_no = 0;
			$reply_text_tail = '请回复序号获得实时信息。今后你在本站附近时，将自动获得该线路实时信息。';

			// 存储发送给用户的序号-线路
			$session = array();

			foreach($nearByStops as $stop)
			{

				if(strlen($reply_text . '=== ' . $stop->name . ' ===' . "\r\n") > 2046 - strlen($reply_text_tail)){
					break;
				}

				$reply_text .= '=== ' . $stop->name . ' ===' . "\r\n";

				foreach($stop->lines as $line)
				{
					$item = ($line_no + 1) . '. ' . $line->name . '->' . $line->terminalStop->name;

					if(strlen($reply_text . $item . "\r\n") > 2046 - strlen($reply_text_tail))
					{
						break;
					}

					$session[$line_no] = $line->pivot->id;
					$reply_text .= $item . "\r\n";
					$line_no ++;
				}
				
				$reply_text .= "\r\n";
				
			}
			
			$user->session = $session;
			$user->save();
			
			$reply_text .= $reply_text_tail;
			
			replyMessage($reply_text);
		});
		
		$weixin->onMessage('text', function($message, $user)
		{
			$session = $user->session;
			
			$index = $message->content - 1;
			
			if(!$session || !array_key_exists($index, $session))
			{
				// 回复的是线路名称，查找这些线路的附近站点
				$latlng = BaiduMap::geoConv(array($user->latitude, $user->longitude));
				$stops = Stop::whereHas('lines', function($q) use($message)
				{
					$q->where('name', 'like', $message->content . '%');
					
				})->distanceAscending($latlng[0], $latlng[1])->get();
				
				$reply_text = ''; $line_no = 0;
				$reply_text_tail = '请回复序号获得实时信息。今后你在本站附近时，将自动获得该线路实时信息。';
				
				// 存储发送给用户的序号-线路
				$session = array();
				
				foreach($stops as $stop)
				{
					
					if(strlen($reply_text . '=== ' . $stop->name . ' ===' . "\r\n") > 2046 - strlen($reply_text_tail)){
						break;
					}
					
					$reply_text .= '=== ' . $stop->name . ' ===' . "\r\n";
					
					foreach($stop->lines()->where('name', 'like', $message->content . '%')->get() as $line)
					{
						$item = ($line_no + 1) . '. ' . $line->name . '->' . $line->terminalStop->name;
						
						if(strlen($reply_text . $item . "\r\n") > 2046 - strlen($reply_text_tail))
						{
							break;
						}
						
						$session[$line_no] = $line->pivot->id;
						$reply_text .= $item . "\r\n";
						$line_no ++;
					}
					
					$reply_text .= "\r\n";
					
				}
				
				$user->session = $session;
				$user->save();
				
				$reply_text .= $reply_text_tail;
				
				replyMessage($reply_text);
				return;
			}
			
			$line_stop_id = $session[$index];
			$line_stop = DB::table('line_stop')->where('id', $line_stop_id)->first();
			$line = Line::find($line_stop->line_id);
			$stop = Stop::find($line_stop->stop_id);
			
			$favorite = $user->favorite;
			!$favorite && $favorite = (object) array('lines'=>array(), 'line_stop'=>array());
			
			!in_array($line->id, $favorite->lines) && array_push($favorite->lines, $line->id);
			!in_array($line_stop->id, $favorite->line_stop) && $favorite->line_stop[] = $line_stop->id;

			$user->favorite = $favorite;
			$user->save();
			
			replyMessage(Shjtmap::vehicleMonitor($line, $stop));
			
			// 挂起一个任务，在预估时间少于1分钟时给用户发送一条客服消息
		});
		
	}
	
	public function updateMenu()
	{
		$weixin = new Weixin();
		$menu_config = ConfigModel::firstOrCreate(array('key' => 'wx_menu'));
		
		if(!$menu_config->value){
			$menu = $weixin->getMenu();
			$menu_config->value = json_encode($menu->menu, JSON_UNESCAPED_UNICODE);
			$menu_config->save();
			return $menu_config->value;
		}
		
		$menu = json_decode($menu_config->value);
		$weixin->removeMenu();
		$result = $weixin->createMenu($menu);
		return json_encode($result) . "\n" . json_encode($weixin->getMenu(), JSON_UNESCAPED_UNICODE);
	}
	
	public function removeMenu()
	{
		$weixin = new Weixin();
		return json_encode($weixin->removeMenu()) . "\n" . json_encode($weixin->getMenu());
	}
	
}
