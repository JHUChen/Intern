<?php 

interface Proto {
	
    function conn($url);
    function get();
    function close();
}

class Package {
	var $length = 0;
	var $type = '';
	var $content = '';
}

/**********Beacon封包**********/
class Information_1 {
	var	$company = '';
	var $uuid = '';
	var $major = 0;
	var $minor = 0;
	var $txpower = 0;
}

class Information_2 {
	var	$name = '';
	var $android_battery = 0;
	var $ios_major = 0;
	var $ios_minor = 0;
	var $ios_battery = 0;
}
/******************************/

/**********Relay封包**********/
class Information_3 
{
	var $major = 0;
	var $minor = 0;
	var $rssi = 0;
}
/******************************/

class Http implements Proto {

    const CRLF  = "\r\n";

    protected $errno = -1;
    protected $errstr = '';
    protected $response = '';

    protected $url = null;
    protected $version = 'HTTP/1.1';
    protected $fh = null;
    
    protected $line = array();
    protected $header = array();
    protected $body = array();
	protected $hudmac = '';
	
	protected $major = 6004;
	protected $minor = 10;
	protected $node_list = array('D9:C6:06:FE:C7:1F', 'D8:61:7C:F2:E1:EA');
    
    public function __construct($url,$hub_mac) {
        $this->conn($url);
		$this->hubmac = $hub_mac;
        $this->setHeader('Host: ' . $this->url['host']);
    }

    protected function setLine($method) {
        $this->line[0] = $method . ' ' . $this->url['path'] . '?' .$this->url['query'] . ' '. $this->version;
    }

    public function setHeader($headerline) {
        $this->header[] = $headerline; 
    }

    protected function setBody($body) {
         $this->body[] = http_build_query($body);
    }

    public function conn($url) {
        $this->url = parse_url($url);
		
        if(!isset($this->url['port'])) {
            $this->url['port'] = 80;
        }

        if(!isset($this->url['query'])) {
            $this->url['query'] = '';
        }

        $this->fh = fsockopen($this->url['host'],$this->url['port'],$this->errno,$this->errstr,3);
    }

    public function get() {
        $this->setLine('GET');
        if ($this->fh)
			$this->request();
		else
			$this->response .= "$this->errstr ($this->errno)<br />\n";
		return $this->response;
    }

    public function request() {
		$req = array_merge($this->line,$this->header,array(''),$this->body,array(''));
        $req = implode(self::CRLF,$req);	
        fwrite($this->fh,$req);
		
		//Header: 378
		fread($this->fh,9);
		$header_type = fread($this->fh,3);
		if($header_type == "404")
			return $this->response = "fail";
		else
			fread($this->fh,366);
		
		//與資料庫連線
		$dbhost = 'intern.here-apps.com';
		$dbuser = 'test';
		$dbpass = 'test';
		$dbname = 'socket';
		$conn = mysql_connect($dbhost, $dbuser, $dbpass) or die('Error with MySQL connection');
		mysql_query("SET NAMES 'utf8'");
		mysql_select_db($dbname);
		
		//初始
		$found_node = array(); //紀錄各node是否被找到
		for($m=0;$m<count($this->node_list);$m++)
			$found_node[$m] = false;
		$node = true; //是否找到指定node的廣播
		$found = true; //是否全部的node都已找到

		//Scan指定的Device直到找到
		$n = date("U");
		$s = $n + 10;
		while($n <= $s)
		{
			//echo $n."*<br>";
			//更新$found變數
			for($m=0;$m<count($this->node_list);$m++)
				$found = $found && $found_node[$m];
			
			if(!$found)
			{
				$mactmp = '';
				$now = '';
				$readtmp = '';
				$cart = '';
				$check = false;
				
				while($node && $n <= $s) 
				{
					//echo $n."/<br>";
					$len = base_convert(fread($this->fh,2), 16, 10); //讀取長度
					fread($this->fh,8);	//讀取不必要的內容
					$readtmp = fread($this->fh, $len-8); //讀取封包所需的內容
					fread($this->fh,4); //讀取不必要的內容
					
					$cart = json_decode($readtmp);	//decode此封包內容
					$mactmp = $cart->bdaddrs[0]->bdaddr; //此封包的mac_address(用判別while接下來是否繼續進行)
					
					//若是找到目標Device則跳出迴圈
					for($m=0;$m<count($this->node_list);$m++)
					{
						if($mactmp == $this->node_list[$m])
						{
							if($found_node[$m] == true)
							{
								$mactmp = '';
								$node = true;
							}
							else
							{
								$now = $mactmp;
								$found_node[$m] = true;
								$node = false;
							}
						}
					}
					$n = date("U");
				}
				
				//找到目標Device後讀取指定Device的封包
				$count = 0;
				$get = array('',''); //宣告接收目標Device的封包的陣列(最多兩個)
				while($mactmp == $now) 
				{
					$get[$count] = $readtmp; //接收目標Device的封包(因為上一個迴圈已經先讀取了第一個封包，所以要先接收)
					$count++;
					
					//讀取下一個封包內容
					$len = base_convert(fread($this->fh,2), 16, 10);
					fread($this->fh,8);
					$readtmp = fread($this->fh, $len-8);
					fread($this->fh, 4);
					
					//此封包的mac_address(用判別while接下來是否繼續進行)
					$cart = json_decode($readtmp);
					$mactmp = $cart->bdaddrs[0]->bdaddr;
					//若是接收完畢則跳出迴圈
				}
				//print_r($get);
				//echo "<br>";
				
				if(strlen($get[1]) == 0)
				{	
					for($m=0;$m<count($this->node_list);$m++)
					{
						if($now == $this->node_list[$m])
							$found_node[$m] = false;
					}
				}
				else
					$check = true;
				
				/*var_dump($get);
				echo "<br>";*/
				
				$node = true;
			}
			else
				break;
			
			//-------------------------------------------------------------------------------------------------------------------
			
			if($check == true)
			{
				$info_1 = new Information_1();
				$info_2 = new Information_2();
				$item = array(); //接收Relay封包處裡過後的資訊
				
				$tag = 0; //Relay封包用來記錄資料數目
				$makeup = 0; //Relay封包用來記錄補齊所需長度的變數
				$icp = "";	//Relay封包用來記錄不完整資料的字串
				$sql = "";
				//處理封包
				for($i=0;$i<$count;$i++)
				{
					$pktmp = json_decode($get[$i]);	//decode封包內容
					//echo $pktmp->adData." *<br>";
					//echo $pktmp->scanData." /<br><br><br>";
					$start = 0;
					$package = array(); //先用array紀錄有幾筆資料需要分析
					$c = 0;
					
					//處理adData有資料的封包
					if($pktmp->adData != null)
					{
						$adtmp = '';
						//將封包分成長度、類型和內容
						while($adtmp != $pktmp->adData)
						{
							$package[$c] = new package();
							
							$len = substr($pktmp->adData, $start, 2);
							$package[$c]->length = (base_convert($len, 16, 10))*2;
							$start += 2;
							$package[$c]->type = substr($pktmp->adData, $start, 2);
							$start += 2;
							$package[$c]->content = substr($pktmp->adData, $start, $package[$c]->length-2);
							$start += ($package[$c]->length-2);
							$adtmp = $adtmp.$len.$package[$c]->type.$package[$c]->content; //用來判別是否處理完
							
							$c++;
							//echo $len."<br>".$package->type."<br>".$package->content."<br>";
							//echo $adtmp;
							
							//echo "<br><br><br>------------------------------<br><br><br>";
						}
					}
					//處理scanData有資料的封包
					else if($pktmp->scanData != null)
					{
						$scantmp = '';
						//將封包分成長度、類型和內容
						while($scantmp != $pktmp->scanData)
						{
							$package[$c] = new package();
							
							$len = substr($pktmp->scanData, $start, 2);
							$package[$c]->length = (base_convert($len, 16, 10))*2;
							$start += 2;
							$package[$c]->type = substr($pktmp->scanData, $start, 2);
							$start += 2;
							$package[$c]->content = substr($pktmp->scanData, $start, $package[$c]->length-2);
							$start += ($package[$c]->length-2);
							$scantmp = $scantmp.$len.$package[$c]->type.$package[$c]->content;
							
							$c++;
							//echo $len."<br>".$package->type."<br>".$package->content."<br>";
							//echo $scantmp;
							
							//echo "<br><br><br>------------------------------<br><br><br>";
						}
					}
					
					//分析封包
					for($o=0;$o<count($package);$o++)
					{
						//分析自定義內容(Type = FF)
						if($package[$o]->type == "FF")
						{
							if($package[$o]->length == 52) //Beacon封包(長度 = 52)
							{
								$r = -2;
								$info_1->txpower = -(256 - base_convert(substr($package[$o]->content, $r), 16, 10));
								$info_1->minor = base_convert(substr($package[$o]->content, $r-4, $r), 16, 10);
								$r -= 4;
								$info_1->major = base_convert(substr($package[$o]->content, $r-4, $r), 16, 10);
								$r -= 4;
								$info_1->uuid = substr($package[$o]->content, $r-32, $r);
								$r -= 32;
								$info_1->company = substr($package[$o]->content, 0, $r);
							}
							else if($package[$o]->length == 32) //Relay封包(長度 = 32)
							{
								$s_1 = 4; //起始位置(跳過4845)
								$t_1 = 0; //切割次數
								$c_1 = 0; //第幾筆資料(判斷不完整資料用)
								$final = -1; //最後一筆資料(判斷不完整資料用)
								$item[$tag] = new Information_3();
								
								//若是第一個封包的資訊不完整，讓紀錄最後的資訊長度
								if(((strlen($package[$o]->content)/2)-2) % 5 != 0)
								{
									$makeup = ((strlen($package[$o]->content)/2)-2) % 5;
									$final = floor(((strlen($package[$o]->content)/2)-2) / 5);
								}
								
								//開始分析Relay封包的每筆內容
								while($s_1 < strlen($package[$o]->content))
								{
									if($t_1 % 3 == 2)
									{
										$char = substr($package[$o]->content, $s_1, 2);
										$s_1 += 2;
										if($c_1 != $final)
											$item[$tag]->rssi = -(256 - base_convert($char, 16, 10));
										else
											$icp .= $char;
										$tag++;
										$c_1++;
										$item[$tag] = new Information_3();
									}
									else if($t_1 % 3 == 0)
									{
										$char = substr($package[$o]->content, $s_1, 4);
										$s_1 += 4;
										if($c_1 != $final)
											$item[$tag]->major = base_convert($char, 16, 10);
										else
											$icp .= $char;
									}
									else if($t_1 % 3 == 1)
									{
										$char = substr($package[$o]->content, $s_1, 4);
										$s_1 += 4;
										if($c_1 != $final)
											$item[$tag]->minor = base_convert($char, 16, 10);
										else
											$icp .= $char;
									}
											$t_1++;
											//echo $char."<br>";
								}
							}
							else
							{
								$s_2 = 4; //起始位置(跳過4845)
								$t_2 = 0; //切割次數
								
								//若是第一個封包資料不完整，則會進入補齊資料(紀錄補齊資料所需長度)
								if($makeup != 0)
								{
									$remind = (5 - $makeup) * 2;
									$cat = substr($package[$o]->content, $s_2, $remind);
									$cat = $icp.$cat;
									$s_2 += $remind;
									
									$item[$tag]->major = base_convert(substr($cat, 0, 4), 16, 10);
									$item[$tag]->minor = base_convert(substr($cat, 4, 4), 16, 10);
									$item[$tag]->rssi = -(256 - base_convert(substr($cat, 8, 2), 16, 10));
								}
								//新增下一筆紀錄
								$tag++;
								$item[$tag] = new Information_3();
								//開始分析Relay封包的每筆內容
								while($s_2 < strlen($package[$o]->content))
								{
									if($t_2 % 3 == 2)
									{
										$char = substr($package[$o]->content, $s_2, 2);
										$item[$tag]->rssi = -(256 - base_convert($char, 16, 10));
										$s_2 += 2;
										if($s_2 < strlen($package[$o]->content))
										{
											$tag++;
											$item[$tag] = new Information_3();
										}
									}
									else if($t_2 % 3 == 0)
									{
										$char = substr($package[$o]->content, $s_2, 4);
										$item[$tag]->major = base_convert($char, 16, 10);
										$s_2 += 4;
									}
									else if($t_2 % 3 == 1)
									{
										$char = substr($package[$o]->content, $s_2, 4);
										$item[$tag]->minor = base_convert($char, 16, 10);
										$s_2 += 4;
									}
									$t_2++;
									//echo $char."<br>";
								}
							}
							//print_r($item);
							//echo "<br>";
						}
								
						//分析名字(Type = 09)
						if($package[$o]->type == "09")
						{
							$s_3 = 0;
							for($j=0;$j<strlen($package[$o]->content);$j+=2)
							{
								$char = base_convert(substr($package[$o]->content, $s_3, 2), 16, 10);
								$s_3 += 2;
								$info_2->name .= chr($char);
							}
							//echo $info_2->name."<br>";
						}
						
						if($package[$o]->length == 8 && $package[$o]->type == "16") //給android看的Battery
						{
							$info_2->android_battery = base_convert(substr($package[$o]->content, -2), 16, 10);
							//echo $info_2->android_battery."<br>";
						}
						else if($package[$o]->length == 12 && $package[$o]->type == "16") //給iOS看的Major,Minor,Battery
						{
							$r = -2;
							$info_2->ios_battery = base_convert(substr($package[$o]->content, $r), 16, 10);
							$info_2->ios_minor = base_convert(substr($package[$o]->content, $r-4, $r), 16, 10);
							$r -= 4;
							$info_2->ios_major = base_convert(substr($package[$o]->content, 0, $r), 16, 10);
							//echo $info_2->ios_battery."<br>".$info_2->ios_minor."<br>".$info_2->ios_major;
						}
					}
				}
				
				//依company的紀錄來判別是Beacon封包(有資料)還是Relay封包(無資料)
				if(strlen($info_1->company) != 0) //Beacon封包
				{
					//因為不是Relay封包，所以Relay的欄位填"-"
					//有些Beacon封包只有一個，所以取第一個封包的Major和Minor存入資料庫(有兩個封包的Major和Minor都一樣)
					//Beacon封包內容中沒有紀錄rssi，所以直接取Scan得到的rssi存入資料庫
					$sql = "insert into test (id, hub, relay, major, minor, rssi, count, time) values (0, '$this->hubmac', '-', '$info_1->major', '$info_1->minor', '$cart->rssi', 0, CURRENT_TIMESTAMP)";
					$this->response = mysql_query($sql) or die('false');
					//echo "*now is " . date("h:i:sa")."<br><br>";
				}
				else //Relay封包
				{
					//若是第一個封包資料不完整又沒有第二個封包補齊，最後一個封包(以新增但沒有資料)不存入資料庫
					if($item[$tag]->major == 0)
						$tag--;
					
					for($k=0;$k<=$tag;$k++)
					{
						$a = (string)$item[$k]->major;
						$b = (string)$item[$k]->minor;
						$c = (string)$item[$k]->rssi;
						
						if($a == $this->major && $b == $this->minor) {
							$sql = "insert into test (id, hub, relay, major, minor, rssi, count, time) values (0, '$this->hubmac', '$now', '$a', '$b', '$c', 0, CURRENT_TIMESTAMP)";
							$this->response = mysql_query($sql) or die('false');
						}
						//echo "*now is " . date("h:i:sa")."<br><br>";
					}
				}
				/*print_r($info_1);
				echo "<br>";
				print_r($info_2);
				echo "<br>";*/
			}
			
			$found = true;
			$n = date("U");
		}
		
		if($n > $s)
		{
			for($m=0;$m<count($this->node_list);$m++)
			{
				if($found_node[$m] == false)
				{
					//$z = (string)$this->minor_list[$m];
					$sql = "insert into test (id, hub, relay, major, minor, rssi, count, time) values (0, '$this->hubmac', '-', '$this->major', '$this->minor', '0', 0, CURRENT_TIMESTAMP)";
					$this->response = mysql_query($sql) or die('false');
					//echo "now is " . date("h:i:sa")."<br>";
				}
			}
		}
		
        $this->close();
    }

    public function close() {
        fclose($this->fh);
    }
}

?>