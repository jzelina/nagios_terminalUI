<?php 
error_reporting(E_ALL & ~E_NOTICE);

### set environment

$title = 'Mobile Nagios PP GUI';
$version = '0.2';
$statusfile = '/usr/local/nagios/var/status.dat';
$statusfilelocal = 'status.dat';
$adminhandset = '';

if (file_exists($statusfilelocal)) { $statusfile = $statusfilelocal;}

### define functions

	### output($xml)
	# prepare XML output by remove and convert strings
	# echo output($xml,"UTF-8");
	function output($xml,$encode)
	{
		if ($encode == "UTF-8")
		{
		header("Content-Type: text/xml; charset=UTF-8");
		$xml = '<?xml version="1.0" encoding="UTF-8"?>\n'.$xml;
		}	
		$xml = str_replace("\r\n", "\n", $xml); #replace CRLF
		$xml = str_replace("\\n", "\n", $xml); #replace "\n"
		$xml = str_replace("\\r", "\r", $xml); #replace "\r"
		$xml = str_replace("\t", "", $xml); #replace tab
		$xml = str_replace("&", "&amp;", $xml); #replace &amp;
		return $xml;
	}

	### NewTextScreen
	# return a new AastraIPPhoneTextScreen.
	# echo output(NewTextScreen($text, $timeout, $cancel, $title, $done, $wrap),"UTF-8");
	function NewTextScreen($text, $timeout, $cancel, $title, $done, $wrap)
	{
				$xml .= '<AastraIPPhoneTextScreen destroyOnExit="yes" Timeout="'.$timeout.'"';
				if ($cancel != "") { $xml .= ' cancelAction= "'.$cancel.'"'; }
				if ($done != "") { $xml .= ' doneAction="'.$done.'"'; }
				$xml .= ' >';
				$xml .= '<Title wrap="no">'.$title.'</Title>';
				if ($wrap == "1") { $xml .= '<Text>'.wordwrap($text, 20, "\n" ).'</Text>'; }
				else { $xml .= '<Text>'.$text.'</Text>'; }
				$xml .= '</AastraIPPhoneTextScreen>';
				return $xml;
	}

### end functions

### init config

	# User Agent
	$user_agent=$_SERVER['HTTP_USER_AGENT'];
	if(stristr($user_agent,'Aastra'))
		{
		$value=preg_split('/ MAC:/',$user_agent);
		$fin=preg_split('/ /',$value[1]);
		$value[1]=preg_replace('/\-/','',$fin[0]);
		$value[2]=preg_replace('/V:/','',$fin[1]);
		$userinfo['IP']=$_SERVER['REMOTE_ADDR']; 			# OMM IP-Address
		$userinfo['LANG']=$_SERVER['HTTP_ACCEPT_LANGUAGE']; # Handset Language
		$userinfo['AGENT']=$value[0]; 						# User Agent
		$userinfo['NUMBER']=$value[1]; 						# Extension Number
		$userinfo['FIRMWARE']=$value[2]; 					# OMM Firmware
		}

	# XML_SERVER own URL
	if (isset($_SERVER['HTTPS'])) { $XML_SERVER = "https://".$_SERVER['SERVER_ADDR'].":".$_SERVER['SERVER_PORT'].$_SERVER['SCRIPT_NAME']; }
	else { $XML_SERVER = "http://".$_SERVER['SERVER_ADDR'].":".$_SERVER['SERVER_PORT'].$_SERVER['SCRIPT_NAME']; }

	$val_alarms["hostup"] = 0;
	$val_alarms["hostdown"] = 0;
	$val_alarms["servicedown"] = 0;
	$val_alarms["servicewarn"] = 0;
	$val_alarms["serviceup"] = 0;

	## language

	$lang["state"] = 'Status';
	$lang["host"] = 'Host';
	$lang["service"] = 'Service';
	$lang["up"] = 'Up';
	$lang["down"] = 'Down';
	$lang["warn"] = 'Warn';
	$lang["hostlist"] = 'Host List';
	$lang["about"] = 'About';
	$lang["listend"] = 'End of List';

### main

	### Parse file
	if ($statusfile != "" AND file_exists($statusfile))
	{
	
	### check if the client is admin
	if ($adminhandset != "" AND $adminhandset != $userinfo['NUMBER']) { echo output(NewTextScreen('Access restricted!', '0', $XML_SERVER, 'Access denied!', '', '1'),"UTF-8"); exit;}
		
		$handle = fopen ($statusfile,"r");
		$nag_statustxt = fread($handle, filesize($statusfile));
		
		#split into modules
		$value=preg_split('/}/',$nag_statustxt);
		foreach ($value as $module)
			{
			#parse modules: info, programstatus, hoststatus, servicestatus
			$name=preg_split('/ {/',$module);
			
				#info
				if(strpos($name[0], "info"))
					{
					foreach(preg_split("/(\r?\n)/", $name[1]) as $line)
						{
						if(strpos($line, "="))
							{
							$tempval=preg_split('/=/',$line);
							$val_info[strval(trim(preg_replace('/\s+/', '',$tempval[0])))] = strval($tempval[1]);
							}
						}
					}
				
				#programstatus
				if(strpos($name[0], "programstatus"))
					{
					foreach(preg_split("/(\r?\n)/", $name[1]) as $line)
						{
						if(strpos($line, "="))
							{
							$tempval=preg_split('/=/',$line);
							$val_programstatus[strval(trim(preg_replace('/\s+/', '',$tempval[0])))] = strval($tempval[1]);
							}
						}
					}
				
				#hoststatus
				if(strpos($name[0], "hoststatus"))
					{
					foreach(preg_split("/(\r?\n)/", $name[1]) as $line)
						{
						if(strpos($line, "="))
							{
							$tempval=preg_split('/=/',$line);
							if (strval(trim(preg_replace('/\s+/', '',$tempval[0]))) == "host_name") {$index = strval(preg_replace('/ /', '_',$tempval[1]));}
							$val_hoststatus[$index][strval(trim(preg_replace('/\s+/', '',$tempval[0])))] = strval($tempval[1]);
							
							}
						}
					
					#report state, Hosts Status: UP	0, DOWN	1 (current_state & last_hard_state)
					switch ($val_hoststatus[$index]["current_state"]) 
						{
							case 0: $val_alarms["hostup"]++; break;
							case 1: $val_alarms["hostdown"]++; $val_alarms["hostdownlst"] = $val_alarms["hostdownlst"].";".$index; break;
						}	
					}
				
				#servicestatus
				if(strpos($name[0], "servicestatus"))
					{
					foreach(preg_split("/(\r?\n)/", $name[1]) as $line)
						{
						if(strpos($line, "="))
							{
							$tempval=preg_split('/=/',$line);
							if (strval(trim(preg_replace('/\s+/', '',$tempval[0]))) == "host_name") {$index = strval(preg_replace('/ /', '_',$tempval[1]));}
							else {
									if (strval(trim(preg_replace('/\s+/', '',$tempval[0]))) == "service_description") { $tmp_hostname = $index; $index = $index."_".strval($tempval[1]); $val_services[strval($tempval[1])] = strval($tempval[1]); }
									$val_servicestatus[$index][strval(trim(preg_replace('/\s+/', '',$tempval[0])))] = strval($tempval[1]);
								}
							}
						}
					#Services Status OK	0, WARNING	1, CRITICAL	2, UNKNOWN	3
					# if current_state != 0 add host to val_alarms
					if ($val_servicestatus[$index]["current_state"] != "0")
						{
						switch ($val_servicestatus[$index]["current_state"]) 
							{
								case 1: $val_alarms["servicewarn"]++; $val_alarms["servicewarnlst"] = $val_alarms["servicewarnlst"].";".$tmp_hostname; break;
								case 2: $val_alarms["servicedown"]++; $val_alarms["servicedownlst"] = $val_alarms["servicedownlst"].";".$tmp_hostname; break;
							}
						}
					else { $val_alarms["serviceup"]++; }
					}
			}	
		unset($value);
		fclose ($handle);
	
	### display output
	
	#print_r($val_info);
	#print_r($val_programstatus);
	#print_r($val_hoststatus);
	#print_r($val_servicestatus);
	#print_r($val_services);
	#print_r($val_alarms);

	# main menu
	switch($_GET['type'])
		{
			case 'state':
				### Status
				$xml = 'Hosts: '.sizeof($val_hoststatus);
				$xml .= '\n'.$lang["up"].': '.$val_alarms["hostup"].' '.$lang["down"].': '.$val_alarms["hostdown"];
				$xml .= '\nServices: '.sizeof($val_servicestatus);
				$xml .= '\n'.$lang["up"].':'.$val_alarms["serviceup"].' '.$lang["warn"].':'.$val_alarms["servicewarn"].' '.$lang["down"].':'.$val_alarms["servicedown"];
				$xml .= '\nServicestypes: '.sizeof($val_services);
				echo output(NewTextScreen($xml, '0', $XML_SERVER, $lang["state"], $XML_SERVER, '0'),"UTF-8");
				exit;
				
			case 'host':
			### Show Single Host details
			
				#$xml = 'Name: '.$val_hoststatus[strval($_GET['id'])]["host_name"];
				#$xml .= '\nHas been Checked: '.$val_hoststatus[strval($_GET['id'])]["has_been_checked"];
				$xml .= 'State: '.$val_hoststatus[strval($_GET['id'])]["current_state"];
				$xml .= ' Last: '.$val_hoststatus[strval($_GET['id'])]["last_hard_state"];
				
				#$xml .= '\nLast Check:\n'.date("d.m.Y H:i",$val_hoststatus[strval($_GET['id'])]["last_check"]);
				#$xml .= '\nNext Check:\n'.date("d.m.Y H:i",$val_hoststatus[strval($_GET['id'])]["next_check"]);
				#$xml .= '\nLast Up:\n'.date("d.m.Y H:i",$val_hoststatus[strval($_GET['id'])]["last_time_up"]);
				#$xml .= '\nLast Down:\n'.date("d.m.Y H:i",$val_hoststatus[strval($_GET['id'])]["last_time_down"]);
				$xml .= '\nFlapping: '.$val_hoststatus[strval($_GET['id'])]["is_flapping"];
				$xml .= ' Services: ';

				foreach($val_services as $service)
					{
					if ($val_servicestatus[strval($_GET['id']).'_'.$service]["service_description"] != "") 
						{
							$xml .= '\n'.$val_servicestatus[strval($_GET['id']).'_'.$service]["service_description"].':';
							if ($val_servicestatus[strval($_GET['id']).'_'.$service]["plugin_output"] != "")
								{ 
								$tempval=preg_split('/ - /',$val_servicestatus[strval($_GET['id']).'_'.$service]["plugin_output"]);
								$xml .= $tempval[0];
								#$xml .= '\n'.wordwrap($tempval[0], 20, "\n");
								}
						}
					}
					
				echo output(NewTextScreen($xml, '0', $XML_SERVER, $_GET['id'], $XML_SERVER, '0'),"UTF-8");
				exit;
		
			case 'hostlist':
				### List all Hosts
				$xml = '<AastraIPPhoneTextMenu defaultIndex="1" destroyOnExit="yes" Timeout="0" cancelAction="'.$XML_SERVER.'">';
				$xml .= '<Title wrap="no">'.$lang["hostlist"].'</Title>';
		
				# Filter / Navigation
				$limit = 7; $index = 1; $first = 1; $nodecount = 0; $nodeadd = 0;
				if ($_GET['index'] != "") { $index = strval($_GET['index']); } if ($_GET['key'] == "down") { $first = $index + 6; $limit = $first + 6; }
				if ($_GET['key'] == "up") { $first = $index - 6; $limit = $first + 6; } if ($first < 1) { $first = 1; $limit = 7; }
					
				foreach($val_hoststatus as $host)
					{ $nodecount++;
					if ($nodecount >= $first AND $nodecount < $limit)
						{ $nodeadd++;
						if ($host != "") {$xml .= '<MenuItem base=""><Prompt>'.$host["host_name"].'</Prompt><URI>'.$XML_SERVER.'?type=host&id='.strval(preg_replace('/ /', '_',$host["host_name"])).'</URI></MenuItem>';}
						}
					}
				
				if ($nodeadd == 0) { $xml .= '<MenuItem base=""><Prompt>'.$lang["listend"].'</Prompt><URI>'.$XML_SERVER.'?type=hostlist</URI></MenuItem>'; }	
				if ($first != 1) { $xml .= '<SoftKey index="16"><Label>Up</Label><URI>'.$XML_SERVER.'?type=hostlist&key=up&index='.$first.'</URI></SoftKey>'; }
				$xml .= '<SoftKey index="17"><Label>Down</Label><URI>'.$XML_SERVER.'?type=hostlist&key=down&index='.$first.'</URI></SoftKey>';
				
				$xml .= '</AastraIPPhoneTextMenu>';
				echo output($xml,"UTF-8");
				
				exit;
				
			case 'hoststate':
				### Hosts with Alarm Menu
				$xml = '<AastraIPPhoneTextMenu defaultIndex="1" destroyOnExit="yes" Timeout="0" cancelAction="'.$XML_SERVER.'">';
				$xml .= '<Title wrap="no">'.$lang["host"].' '.$lang["state"].' '.$lang["down"].'</Title>';
				
				foreach(preg_split("/;/", $val_alarms["hostdownlst"]) as $host)
					{
					if ($host != "") {$xml .= '<MenuItem base=""><Prompt>'.$host.'</Prompt><URI>'.$XML_SERVER.'?type=host&id='.$host.'</URI></MenuItem>';}
					}
					
				$xml .= '</AastraIPPhoneTextMenu>';
				echo output($xml,"UTF-8");
				exit;
				
			case 'servicestate':
				### Hosts with Service Alarm Menu
				$xml = '<AastraIPPhoneTextMenu defaultIndex="1" destroyOnExit="yes" Timeout="0" cancelAction="'.$XML_SERVER.'">';
				$xml .= '<Title wrap="no">'.$lang["service"].' '.$lang["down"].'/'.$lang["warn"].'</Title>';
				
				foreach(preg_split("/;/", $val_alarms["servicedownlst"]) as $host)
					{
					if ($host != "") {$xml .= '<MenuItem base=""><Prompt>'.$host.'</Prompt><URI>'.$XML_SERVER.'?type=host&id='.$host.'</URI></MenuItem>';}
					}
				
				foreach(preg_split("/;/", $val_alarms["servicewarnlst"]) as $host)
					{
					if ($host != "") {$xml .= '<MenuItem base=""><Prompt>'.$host.'</Prompt><URI>'.$XML_SERVER.'?type=host&id='.$host.'</URI></MenuItem>';}
					}
					
				$xml .= '</AastraIPPhoneTextMenu>';
				echo output($xml,"UTF-8");
				exit;
			
			case 'about':
				### About / Info Dialog
				$xml = 'Nagios Version:\n'.$val_info["version"].'\nLast Check:\n'. date("d.m.Y H:i:s",$val_info["created"]);
				if ($val_info["update_available"] == "1") { $xml .= '\nUpdate to Version:\n'.$val_info["new_version"];}
				echo output(NewTextScreen($xml, '0', $XML_SERVER, $lang["about"], $XML_SERVER, '0'),"UTF-8");
				exit;
			
			default:		
				### main menu
				$xml = '<AastraIPPhoneTextMenu defaultIndex="1" destroyOnExit="yes" Timeout="0">';
				$xml .= '<Title wrap="no">'.$title.'</Title>';
				
				$xml .= '<MenuItem base=""><Prompt>'.$lang["state"].'</Prompt><URI>'.$XML_SERVER.'?type=state</URI></MenuItem>';
				
				#host's with alarm
				if ($val_alarms["hostdown"] == "0") { $xml .= '<MenuItem base=""><Prompt>'.$lang["host"].' OK! '.$val_alarms["hostup"].' </Prompt><URI>'.$XML_SERVER.'</URI></MenuItem>'; }
				else { $xml .= '<MenuItem base=""><Prompt>'.$lang["host"].': '.$val_alarms["hostup"].' , '.$val_alarms["hostdown"].' </Prompt><URI>'.$XML_SERVER.'?type=hoststate</URI></MenuItem>'; }
				
				#service with alarm
				if ($val_alarms["servicedown"] == "0" AND $val_alarms["servicewarn"] == "0") { $xml .= '<MenuItem base=""><Prompt>'.$lang["service"].' OK! '.$val_alarms["serviceup"].' </Prompt><URI>'.$XML_SERVER.'</URI></MenuItem>'; }
				else { $xml .= '<MenuItem base=""><Prompt>'.$lang["service"].': '.$val_alarms["serviceup"].','.$val_alarms["servicewarn"].','.$val_alarms["servicedown"].'</Prompt><URI>'.$XML_SERVER.'?type=servicestate</URI></MenuItem>'; }
				
				$xml .= '<MenuItem base=""><Prompt>'.$lang["hostlist"].'</Prompt><URI>'.$XML_SERVER.'?type=hostlist</URI></MenuItem>';
				$xml .= '<MenuItem base=""><Prompt>'.$lang["about"].'</Prompt><URI>'.$XML_SERVER.'?type=about</URI></MenuItem>';
				$xml .= '</AastraIPPhoneTextMenu>';
				echo output($xml,"UTF-8");
				exit;
		}
	}
	else { echo output(NewTextScreen("Status file ".$statusfile." not found!", '0', $XML_SERVER, 'File not Found!', '', '1'),"UTF-8");}

?>