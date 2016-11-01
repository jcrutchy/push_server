<?php

#####################################################################################################

error_reporting(E_ALL);
set_time_limit(0);
ob_implicit_flush();
date_default_timezone_set("UTC");
ini_set("memory_limit","512M");

if ((isset($argv[1])==False) or (isset($argv[2])==False))
{
  show_message("to run ws log server: php log-server.php listen_address listen_port");
  show_message("eg: php log-server.php 192.168.0.188 50001");
  return;
}

define("LISTENING_ADDRESS",trim($argv[1]));
define("LISTENING_PORT",trim($argv[2]));
define("SELECT_TIMEOUT",200000); # microseconds (200000 = 0.2 seconds)

define("ROOT_PATH","/var/include/vhosts/default/inc");

define("LOG_PIPE_FILE",ROOT_PATH."/data/ws_log");

set_error_handler("error_handler");

# include delay to allow push server to create log pipe file on system startup
$t=microtime(True);
while ((microtime(True)-$t)<5)
{
  if (file_exists(LOG_PIPE_FILE)==True)
  {
    break;
  }
}
if (file_exists(LOG_PIPE_FILE)==False)
{
  show_message("PIPE FILE NOT FOUND");
  return;
}

$log_pipe=fopen(LOG_PIPE_FILE,"r");

$sockets=array();
$connections=array();
$server=stream_socket_server("tcp://".LISTENING_ADDRESS.":".LISTENING_PORT,$err_no,$err_msg);
if ($server===False)
{
  show_message("could not bind to socket: ".$err_msg,True);
  return;
}
show_message("log server started");
stream_set_blocking($server,0);
$sockets[]=$server;
while (True)
{
  if (file_exists(LOG_PIPE_FILE)==False)
  {
    break;
  }
  $read=array($log_pipe);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0,SELECT_TIMEOUT);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=fgets($log_pipe);
      if (trim($data)=="q")
      {
        break;
      }
      broadcast_to_all($data);
    }
  }
  $read=array(STDIN);
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0);
  if ($change_count!==False)
  {
    if ($change_count>=1)
    {
      $data=trim(fgets(STDIN));
      if ($data=="q")
      {
        break;
      }
    }
  }
  $read=$sockets;
  $write=Null;
  $except=Null;
  $change_count=stream_select($read,$write,$except,0);
  if ($change_count===False)
  {
    show_message("stream_select failed",True);
    break;
  }
  if ($change_count<1)
  {
    continue;
  }
  foreach ($read as $read_key => $read_socket)
  {
    if ($read[$read_key]===$server)
    {
      $client=stream_socket_accept($server,120);
      if (($client===False) or ($client==Null))
      {
        show_message("stream_socket_accept error/timeout",True);
        continue;
      }
      stream_set_blocking($client,0);
      $sockets[]=$client;
      $client_key=array_search($client,$sockets,True);
      $new_connection=array();
      $new_connection["peer_name"]=stream_socket_get_name($client,True);
      $connections[$client_key]=$new_connection;
      show_message("client connected");
    }
    else
    {
      $client_key=array_search($read[$read_key],$sockets,True);
      $data="";
      do
      {
        $buffer=fread($sockets[$client_key],8);
        if (strlen($buffer)===False)
        {
          show_message("read error",True);
          close_client($client_key);
          continue 2;
        }
        $data.=$buffer;
      }
      while (strlen($buffer)>0);
      if (strlen($data)==0)
      {
        show_message("client terminated connection",True);
        close_client($client_key);
        continue;
      }
      on_msg($client_key,$data);
    }
  }
}
foreach ($sockets as $key => $socket)
{
  if ($sockets[$key]!==$server)
  {
    close_client($key);
  }
}

stream_socket_shutdown($server,STREAM_SHUT_RDWR);
fclose($server);

fclose($log_pipe);

#####################################################################################################

function error_handler($errno,$errstr,$errfile,$errline)
{
  $continue_errors=array(
    "failed with errno=32 Broken pipe",
    "failed with errno=104 Connection reset by peer");
  for ($i=0;$i<count($continue_errors);$i++)
  {
    if (strpos($errstr,$continue_errors[$i])!==False)
    {
      return True;
    }
  }
  echo "*** $errstr in $errfile on line $errline".PHP_EOL;
  die; # FOR TEST/DEBUG
}

#####################################################################################################

function on_msg($client_key,$data)
{
  global $sockets;
  global $connections;
}

#####################################################################################################

function close_client($client_key)
{
  global $sockets;
  global $connections;
  show_message("closing client connection",True);
  stream_socket_shutdown($sockets[$client_key],STREAM_SHUT_RDWR);
  fclose($sockets[$client_key]);
  unset($sockets[$client_key]);
  unset($connections[$client_key]);
}

#####################################################################################################

function broadcast_to_all($msg)
{
  global $connections;
  foreach ($connections as $key => $conn)
  {
    do_reply($key,$msg);
  }
}

#####################################################################################################

function do_reply($client_key,&$msg)
{
  global $sockets;
  $total_sent=0;
  while ($total_sent<strlen($msg))
  {
    $buf=substr($msg,$total_sent);
    $written=fwrite($sockets[$client_key],$buf,min(strlen($buf),8192));
    if (($written===False) or ($written<=0))
    {
      show_message("error writing to client socket",True);
      close_client($client_key);
      return;
    }
    $total_sent+=$written;
  }
}

#####################################################################################################

function show_message($msg,$star=False)
{
  $prefix="";
  if ($star==True)
  {
    $prefix="*** ";
  }
  echo $prefix.rtrim($msg).PHP_EOL;
}

#####################################################################################################