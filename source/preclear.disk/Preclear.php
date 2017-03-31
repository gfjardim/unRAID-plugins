<?
set_error_handler("log_error");
set_exception_handler( "log_exception" );
$plugin = "preclear.disk";

require_once( "webGui/include/Helpers.php" );
require_once( "plugins/${plugin}/assets/lib.php" );

#########################################################
#############           VARIABLES          ##############
#########################################################

$Preclear     = new Preclear;
$script_files = $Preclear->scriptFiles();
// $VERBOSE        = TRUE;
// $TEST           = TRUE;

if (isset($_POST['display']))
{
  $display = $_POST['display'];
}

if (! is_dir(dirname($state_file)) )
{
  @mkdir(dirname($state_file),0777,TRUE);
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function log_error($errno, $errstr, $errfile, $errline)
{
  switch($errno){
    case E_ERROR:               $error = "Error";                          break;
    case E_WARNING:             $error = "Warning";                        break;
    case E_PARSE:               $error = "Parse Error";                    break;
    case E_NOTICE:              $error = "Notice";                 return; break;
    case E_CORE_ERROR:          $error = "Core Error";                     break;
    case E_CORE_WARNING:        $error = "Core Warning";                   break;
    case E_COMPILE_ERROR:       $error = "Compile Error";                  break;
    case E_COMPILE_WARNING:     $error = "Compile Warning";                break;
    case E_USER_ERROR:          $error = "User Error";                     break;
    case E_USER_WARNING:        $error = "User Warning";                   break;
    case E_USER_NOTICE:         $error = "User Notice";                    break;
    case E_STRICT:              $error = "Strict Notice";                  break;
    case E_RECOVERABLE_ERROR:   $error = "Recoverable Error";              break;
    default:                    $error = "Unknown error ($errno)"; return; break;
  }
  debug("PHP {$error}: $errstr in {$errfile} on line {$errline}");
}

function log_exception( $e )
{
  debug("PHP Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
}

function debug($msg, $type = "NOTICE")
{
  if ( $type == "DEBUG" && ! $GLOBALS["VERBOSE"] )
  {
    return NULL;
  }
  $msg = "\n".date("D M j G:i:s T Y").": ".print_r($msg,true);
  file_put_contents($GLOBALS["log_file"], $msg, FILE_APPEND);
}


function _echo($m)
{
  echo "<pre>".print_r($m,TRUE)."</pre>";
};


function reload_partition($name)
{
  exec("hdparm -z /dev/{$name} >/dev/null 2>&1 &");
}


function listDir($root)
{
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();

  foreach ($iter as $path => $fileinfo)
  {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }

  return $paths;
}


function save_ini_file($file, $array)
{
  $res = array();

  foreach($array as $key => $val)
  {
    if(is_array($val))
    {
      $res[] = PHP_EOL."[".addslashes($key)."]";

      foreach($val as $skey => $sval)
      {
        $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.addslashes($sval).'"');
      }
    }

    else
    {
      $res[] = "$key = ".(is_numeric($val) ? $val : '"'.addslashes($val).'"');
    }
  }
  file_put_contents($file, implode(PHP_EOL, $res));
}

class Disk
{
  function __construct()
  {
    global $VERBOSE;
    $this->verbose = $VERBOSE;
  }

  private function unasigned_disks()
  {
    $paths          = listDir("/dev/disk/by-id");
    $disks_id       = preg_grep("#wwn-|-part#", $paths, PREG_GREP_INVERT);
    $disks_real     = array_map(function($p){return realpath($p);}, $disks_id);
    exec("/usr/bin/strings /boot/config/super.dat 2>/dev/null|grep -Po '.{10,}'", $disks_serial);
    exec("udevadm info --query=property --name /dev/disk/by-label/UNRAID 2>/dev/null|grep -Po 'ID_SERIAL=\K.*'", $flash_serial);
    $disks_cfg      = is_file("/boot/config/disk.cfg") ? parse_ini_file("/boot/config/disk.cfg") : array();
    $cache_serial   = array_flip(preg_grep("#cacheId#i", array_flip($disks_cfg)));
    $unraid_serials = array_merge($disks_serial,$cache_serial,$flash_serial);
    $unraid_disks   = array();

    if (is_file("/var/local/emhttp/disks.ini"))
    {
      $disksIni    = parse_ini_file("/var/local/emhttp/disks.ini", true);
      $unraid_real = array_filter(array_map(function($disk){return $disk['device'] ? "/dev/${disk['device']}" : null;}, $disksIni));
    }
    else
    {
      foreach( $unraid_serials as $serial )
      {
        $unraid_disks = array_merge($unraid_disks, preg_grep("#-".preg_quote($serial, "#")."#", $disks_id));
      }
      $unraid_real = array_map(function($p){return realpath($p);}, $unraid_disks);
    }

    $unassigned  = array_flip(array_diff(array_combine($disks_id, $disks_real), $unraid_real));
    natsort($unassigned);

    foreach ( $unassigned as $k => $disk )
    {
      unset($unassigned[$k]);
      $parts  = array_values(preg_grep("#{$disk}-part\d+#", $paths));
      $device = realpath($disk);

      if (! is_bool(strpos($device, "/dev/sd")) || ! is_bool(strpos($device, "/dev/hd")))
      {
        $unassigned[$disk] = array("device"=>$device,"partitions"=>$parts);
      }
    }
    debug("\nDisks:\n+ ".implode("\n+ ", array_map(function($k,$v){return "$k => $v";}, $disks_real, $disks_id)), "DEBUG");
    debug("\nunRAID Serials:\n+ ".implode("\n+ ", $unraid_serials), "DEBUG");
    debug("\nunRAID Disks:\n+ ".implode("\n+ ", $unraid_disks), "DEBUG");
    return $unassigned;
  }

}


function get_unasigned_disks()
{
  $paths          = listDir("/dev/disk/by-id");
  $disks_id       = preg_grep("#wwn-|-part#", $paths, PREG_GREP_INVERT);
  $disks_real     = array_map(function($p){return realpath($p);}, $disks_id);
  exec("/usr/bin/strings /boot/config/super.dat 2>/dev/null|grep -Po '.{10,}'", $disks_serial);
  exec("udevadm info --query=property --name /dev/disk/by-label/UNRAID 2>/dev/null|grep -Po 'ID_SERIAL=\K.*'", $flash_serial);
  $disks_cfg      = is_file("/boot/config/disk.cfg") ? parse_ini_file("/boot/config/disk.cfg") : array();
  $cache_serial   = array_flip(preg_grep("#cacheId#i", array_flip($disks_cfg)));
  $unraid_serials = array_merge($disks_serial,$cache_serial,$flash_serial);
  $unraid_disks   = array();

  if (is_file("/var/local/emhttp/disks.ini"))
  {
    $disksIni    = parse_ini_file("/var/local/emhttp/disks.ini", true);
    $unraid_real = array_filter(array_map(function($disk){return $disk['device'] ? "/dev/${disk['device']}" : null;}, $disksIni));
  }
  else
  {
    foreach( $unraid_serials as $serial )
    {
      $unraid_disks = array_merge($unraid_disks, preg_grep("#-".preg_quote($serial, "#")."#", $disks_id));
    }
    $unraid_real = array_map(function($p){return realpath($p);}, $unraid_disks);
  }

  $unassigned  = array_flip(array_diff(array_combine($disks_id, $disks_real), $unraid_real));
  natsort($unassigned);

  foreach ( $unassigned as $k => $disk )
  {
    unset($unassigned[$k]);
    $parts  = array_values(preg_grep("#{$disk}-part\d+#", $paths));
    $device = realpath($disk);

    if (! is_bool(strpos($device, "/dev/sd")) || ! is_bool(strpos($device, "/dev/hd")))
    {
      $unassigned[$disk] = array("device"=>$device,"partitions"=>$parts);
    }
  }
  debug("\nDisks:\n+ ".implode("\n+ ", array_map(function($k,$v){return "$k => $v";}, $disks_real, $disks_id)), "DEBUG");
  debug("\nunRAID Serials:\n+ ".implode("\n+ ", $unraid_serials), "DEBUG");
  debug("\nunRAID Disks:\n+ ".implode("\n+ ", $unraid_disks), "DEBUG");
  return $unassigned;
}


function is_mounted($dev)
{
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}


function get_all_disks_info($bus="all")
{
  $disks = benchmark( "get_unasigned_disks" );

  foreach ($disks as $key => $disk)
  {
    if ($disk['type'] != $bus && $bus != "all")
    {
      continue;
    }
    $disk = array_merge($disk, get_disk_info($key));
    $disks[$key] = $disk;
  }

  usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
  return $disks;
}


function get_info($device) {
  global $state_file;

  $parse_smart = function($smart, $property) 
  {
    $value = trim(explode(":", array_values(preg_grep("#$property#", $smart))[0])[1]);
    return ($value) ? $value : "n/a";
  };

  $whitelist = array("ID_MODEL","ID_SCSI_SERIAL","ID_SERIAL_SHORT");
  $state = is_file($state_file) ? @parse_ini_file($state_file, true) : array();

  if (array_key_exists($device, $state) && ! $reload)
  {
    return $state[$device];
  }

  else
  {
    $disk =& $state[$device];
    $udev = parse_ini_string(shell_exec("udevadm info --query=property --name ${device} 2>/dev/null"));
    $disk = array_intersect_key($udev, array_flip($whitelist));
    exec("smartctl -i -d sat,auto $device 2>/dev/null", $smartInfo);
    $disk['FAMILY']   = $parse_smart($smartInfo, "Model Family");
    $disk['MODEL']    = $parse_smart($smartInfo, "Device Model");

    if ($disk['FAMILY'] == "n/a" && $disk['MODEL'] == "n/a" )
    {
      $vendor         = $parse_smart($smartInfo, "Vendor");
      $product        = $parse_smart($smartInfo, "Product");
      $revision       = $parse_smart($smartInfo, "Revision");
      $disk['FAMILY'] = "{$vendor} {$product}";
      $disk['MODEL']  = "{$vendor} {$product} - Rev. {$revision}";
    }

    $disk['FIRMWARE'] = $parse_smart($smartInfo, "Firmware Version");
    $disk['SIZE']     = intval(trim(shell_exec("blockdev --getsize64 ${device} 2>/dev/null")));
    save_ini_file($state_file, $state);
    return $state[$device];
  }
}


function get_disk_info($device, $reload=FALSE)
{
  $disk = array();
  $attrs = benchmark("get_info", $device);
  $disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
  $disk['serial']       = trim("{$attrs[ID_MODEL]}_{$disk[serial_short]}");
  $disk['device']       = realpath($device);
  $disk['family']       = $attrs['FAMILY'];
  $disk['model']        = $attrs['MODEL'];
  $disk['firmware']     = $attrs['FIRMWARE'];
  $disk['size']         = sprintf("%s %s", my_scale($attrs['SIZE'] , $unit), $unit);
  $disk['temperature']  = benchmark("get_temp", $device);
  return $disk;
}


function is_disk_running($dev)
{
  global $plugin;
  $file      = "/var/state/{$plugin}/hdd_state.json";
  $stats     = is_file($file) ? json_decode(file_get_contents($file),TRUE) : array();
  $timestamp = isset($stats[$dev]['timestamp']) ? $stats[$dev]['timestamp'] : time();
  $running   = isset($stats[$dev]['running']) ? $stats[$dev]['running'] : NULL;

  if ( $running === NULL || (time() - $timestamp) > 300 )
  {
    $timestamp = time();
    $running   = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  }

  $stats[$dev] = array('timestamp' => $timestamp,
                       'running'   => $running);

  file_put_contents($file, json_encode($stats));

  return ($running == 0) ? TRUE : FALSE;
}


function get_temp($dev)
{
  global $plugin;
  $tc        = "/var/state/{$plugin}/hdd_temp.json";
  $stats     = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
  $all_types = [ "-d scsi", "-d ata", "-d auto", "-d sat,auto", "-d sat,12", "-d usbjmicron", "-d usbjmicron,0", "-d usbjmicron,1" ]; 
  $all_types = array_merge($all_types, [ "-x -d usbjmicron,x,0", "-x -d usbjmicron,x,1", "-d usbsunplus", "-d usbcypress", "-d sat -T permissive" ]);
  $timestamp = isset($stats[$dev]['timestamp']) ? $stats[$dev]['timestamp'] : time();
  $smart     = isset($stats[$dev]['smart']) ? $stats[$dev]['smart'] : null;
  $temp      = isset($stats[$dev]['temp']) ? $stats[$dev]['temp'] : null;

  if ( ! $smart )
  {
    debug("SMART parameters for drive [{$dev}] not found, probing...", "DEBUG");
    $smart = "none";
    foreach ($all_types as $type)
    {
      $res = shell_exec("smartctl --attributes {$type} '{$dev}' 2>/dev/null| grep -c 'Temperature_Celsius'");
      if ( $res > 0 )
      {
        debug("SMART parameters for disk [{$dev}] ($smart) found.", "DEBUG");
        $smart = $type;
        break;
      }
    }
  }

  if ( (time() - $timestamp > 900) || ! $temp )
  {
    if ( $smart != "none" && is_disk_running($dev) )
    {
      debug("Temperature probing of disk '{$dev}'", "DEBUG");
      $temp = trim(shell_exec("smartctl -A {$type} $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
      $temp = (is_numeric($temp)) ? $temp : null; 
      $timestamp = time();
    }

    else
    {
      $temp = null;
    }
  }

  $stats[$dev] = array('timestamp' => $timestamp,
                       'temp'      => $temp,
                       'smart'     => $smart);

  file_put_contents($tc, json_encode($stats));

  return $temp ? $temp : "*";

}


function benchmark()
{
  $params   = func_get_args();
  $function = $params[0];
  array_shift($params);
  $time     = -microtime(true); 
  $out      = call_user_func_array($function, $params);
  $time    += microtime(true); 
  debug("benchmark: $function(".implode(",", $params).") took ".sprintf('%f', $time)."s.", "DEBUG");
  return $out;
}


$start_time = time();
switch ($_POST['action'])
{

  case 'get_content':
    debug("Starting get_content: ".(time() - $start_time),'DEBUG');
    $disks = benchmark("get_all_disks_info");
    $all_status = array();

    if ( count($disks) )
    {
      $odd="odd";
      
      foreach ($disks as $disk)
      {
        $disk_name = basename($disk['device']);
        $disk_icon = benchmark("is_disk_running", "${disk['device']}") ? "green-on.png" : "green-blink.png";
        $serial    = $disk['serial'];
        $temp      = my_temp($disk['temperature']);
        $mounted   = array_reduce($disk['partitions'], function ($found, $partition) { return $found || is_mounted(realpath($partition)); }, false);
        $reports   = is_dir("/boot/preclear_reports") ? listDir("/boot/preclear_reports") : [];
        $reports   = array_filter($reports, function ($report) use ($disk)
                                  {
                                    return preg_match("|".$disk["serial_short"]."|", $report) && ( preg_match("|_report_|", $report) || preg_match("|_rpt_|", $report) ); 
                                  });

        if (count($reports))
        {
          $title  = "<span title='Click to view reports.' class='exec toggle-reports' hdd='{$disk_name}'>
                      <i class='glyphicon glyphicon-hdd hdd'></i>
                      <i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>
                      ${disk['serial']}
                    </span>";
          
          $report_files = "<div class='toggle-${disk_name}' style='display:none;'>";

          foreach ($reports as $report)
          {
            $report_files .= "<div style='margin:4px 0px 4px 0px;'>
                                <i class='glyphicon glyphicon-list-alt hdd'></i>
                                <span style='margin:7px;'></span>
                                <a href='${report}'>".pathinfo($report, PATHINFO_FILENAME)."</a>
                                <a class='exec' title='Remove Report' style='color:#CC0000;font-weight:bold;' onclick='rmReport(\"{$report}\", this);'>
                                  &nbsp;<i class='glyphicon glyphicon-remove hdd'></i>
                                </a>
                              </div>";  
          }
          
          $report_files .= "</div>";
        }
        else
        {
          $report_files="";
          $title  = "<span class='toggle-reports' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i><span style='margin:8px;'></span>{$serial}";
        }

        if ($Preclear->isRunning($disk_name))
        {
          $status  = $Preclear->Status($disk_name, $serial);
          $all_status[$disk['serial_short']] = $status;
        }
        else
        {
          $status  = $mounted ? "Disk mounted" : $Preclear->Link($disk_name, "text");
        }
        
        $disks_o .= "<tr class='$odd'>
                      <td><img src='/webGui/images/${disk_icon}'><a href='/Tools/New?name=$disk_name'> $disk_name</a></td>
                      <td>${title}${report_files}</td>
                      <td>{$temp}</td>
                      <td><span>${disk['size']}</span></td>
                      <td>{$status}</td>
                    </tr>";
        $disks_o .= $report_files;
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    }

    else 
    {
      $disks_o .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    debug("get_content Finished: ".(time() - $start_time),'DEBUG');
    echo json_encode(array("disks" => $disks_o, "info" => json_encode($disks), "status" => $all_status));
    break;



  case 'get_status':
    $disk_name = urldecode($_POST['device']);
    $serial    = urldecode($_POST['serial']);
    $status    = $Preclear->Status($disk_name, $serial);
    echo json_encode(array("status" => $status));
    break;


  case 'start_preclear':
    $device  = urldecode($_POST['device']);
    $serial  = $Preclear->diskSerial($device);
    $session = "preclear_disk_{$serial}";
    $op      = (isset($_POST['op']) && $_POST['op'] != "0") ? urldecode($_POST['op']) : "";
    $scope   = $_POST['scope'];
    $script  = $script_files[$scope];
    $devname = basename($device);

    @file_put_contents("/tmp/preclear_stat_{$devname}","{$devname}|NN|Starting...");

    if ($scope == "gfjardim")
    {
      $notify    = (isset($_POST['--notify']) && $_POST['--notify'] > 0) ? " --notify ".urldecode($_POST['--notify']) : "";
      $frequency = (isset($_POST['--frequency']) && $_POST['--frequency'] > 0 && intval($_POST['--notify']) > 0) ? " --frequency ".urldecode($_POST['--frequency']) : "";
      $cycles    = (isset($_POST['--cycles'])) ? " --cycles ".urldecode($_POST['--cycles']) : "";
      $pre_read  = (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
      $post_read = (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
      $test      = (isset($_POST['--test']) && $_POST['--test'] == "on") ? " --test" : "";
      $noprompt  = " --no-prompt";

      $cmd = "$script {$op}${notify}${frequency}{$cycles}{$pre_read}{$post_read}{$noprompt}{$test} $device";
      
    }

    else
    {
      $notify    = (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".urldecode($_POST['-o']) : "";
      $mail      = (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".urldecode($_POST['-M']) : "";
      $passes    = isset($_POST['-c']) ? " -c ".urldecode($_POST['-c']) : "";
      $read_sz   = (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".urldecode($_POST['-r']) : "";
      $write_sz  = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
      $pre_read  = (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
      $post_read = (isset($_POST['-X']) && $_POST['-X'] == "on") ? " -X" : "";
      $fast_read = (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";
      $confirm   = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
      $test      = (isset($_POST['-s']) && $_POST['-s'] == "on") ? " -s" : "";

      $capable  = array_key_exists("joel", $script_files) ? $Preclear->scriptCapabilities($script_files["joel"]) : [];
      $noprompt = (array_key_exists("noprompt", $capable) && $capable["noprompt"]) ? " -J" : "";
      
      if ( $post_read && $pre_read )
      {
        $post_read = " -n";
        $pre_read = "";
      }
      
      if (! $op )
      {
        $cmd = "$script {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$post_read}{$fast_read}{$noprompt}{$test} $device";
      }

      else if ( $op == "-V" )
      {
        $cmd = "$script {$op}{$fast_read}{$mail}{$notify}{$read_sz}{$write_sz}{$noprompt}{$test} $device";
      }

      else
      {
        $cmd = "$script {$op}{$noprompt} $device";
        @unlink("/tmp/preclear_stat_{$devname}");
      }
    }

    TMUX::killSession( $session );
    TMUX::NewSession( $session );
    TMUX::sendCommand($session, "$cmd");

    if ( $confirm && ! $noprompt )
    {
      foreach( range(0, 3) as $x )
      {
        if ( strpos(TMUX::getSession($session), "Answer Yes to continue") )
        {
          sleep(1);
          TMUX::sendCommand($session, "Yes");
          break;
        }

        else
        {
          sleep(1);
        }
      }
    }

    break;


  case 'stop_preclear':
    $serial = urldecode($_POST['serial']);
    $device = basename($Preclear->serialDisk($serial));
    TMUX::killSession("preclear_disk_{$serial}");
    @unlink("/tmp/preclear_stat_{$device}");
    reload_partition($serial);
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'clear_preclear':
    $serial = urldecode($_POST['serial']);
    $device = basename($Preclear->serialDisk($serial));
    TMUX::killSession("preclear_disk_{$serial}");
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'get_preclear':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    if ( ! TMUX::hasSession($session))
    {
      $output = "<script>window.close();</script>";
    }
    $content = TMUX::getSession($session);
    $output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
    if ( strpos($content, "Answer Yes to continue") )
    {
      $output .= "<br><center><button onclick='hit_yes(\"{$serial}\")'>Answer Yes</button></center>";
    }
    echo json_encode(array("content" => $output));
    break;


  case 'hit_yes':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    TMUX::sendCommand($session, "Yes");
    break;


  case 'remove_report':
    $file = $_POST['file'];
    if (! is_bool( strpos($file, "/boot/preclear_reports")))
    {
      unlink($file);
      echo "true";
    }
    break;
}


switch ($_GET['action']) {

  case 'show_preclear':
    $serial = urldecode($_GET['serial']);
    ?>
    <html>
    <body>
    <div id="data_content"></div>

    <?if (is_file("webGui/scripts/dynamix.js")):?>
    <script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>
    <?else:?>
    <script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>
    <?endif;?>
    <script src="/plugins/<?=$plugin;?>/assets/clipboard.min.js"></script>
    <script>
      var timers = {};
      var URL = "/plugins/<?=$plugin;?>/Preclear.php";
      var serial = "<?=$serial;?>";

      function get_preclear()
      {
        clearTimeout(timers.preclear);
        $.post(URL,{action:"get_preclear",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"},function(data) {
          if (data.content)
          {
            $("#data_content").html(data.content);
          }
        },"json").always(function() {
          timers.preclear=setTimeout('get_preclear()',1000);
        });
      }
      function hit_yes(serial)
      {
        $.post(URL,{action:"hit_yes",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"});
      }
      $(function() {
        document.title='Preclear for disk <?=$serial;?> ';
        get_preclear();
        new Clipboard('.btn');
      });
    </script>
    <div style="text-align: center;"><button class="btn" data-clipboard-target="#data_content">Copy to clipboard</button></div>
    </body>
    </html>
    <?
    break;
}

?>