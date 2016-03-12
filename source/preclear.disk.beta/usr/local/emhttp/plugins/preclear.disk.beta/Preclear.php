<?
set_error_handler("log_error");
require_once ("webGui/include/Helpers.php");

#########################################################
#############           VARIABLES          ##############
#########################################################

$plugin         = "preclear.disk.beta";
$state_file     = "/var/state/{$plugin}/state.ini";
$log_file       = "/var/log/{$plugin}.log";
$script_file    = "/usr/local/emhttp/plugins/${plugin}/script/preclear_disk.sh";
$script_version = (is_file($script_file)) ? trim(shell_exec("$script_file -v 2>/dev/null|cut -d: -f2")) : NULL;
$noprompt       = $script_version ? (strpos(file_get_contents($script_file), "noprompt") ? TRUE : FALSE ) : FALSE;
// $VERBOSE        = TRUE;

if (isset($_POST['display'])) $display = $_POST['display'];

if (! is_dir(dirname($state_file)) ) {
  @mkdir(dirname($state_file),0777,TRUE);
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function log_error($errno, $errstr, $errfile, $errline) {
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

function debug($m, $type = "NOTICE"){
  if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
  $m = "\n".date("D M j G:i:s T Y").": ".print_r($m,true);
  file_put_contents($GLOBALS["log_file"], $m, FILE_APPEND);
}

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";};

function sendLog() {
  global $var, $paths;
  $url = "http://gfjardim.maxfiles.org";
  $max_size = 2097152; # in bytes
  $notify = "/usr/local/emhttp/webGui/scripts/notify";
  $data = array('data'     => shell_exec("cat '{$GLOBALS['log_file']}' 2>&1 | tail -c $max_size -"),
                'language' => 'text',
                'title'    => '[Preclear Disk log]',
                'private'  => true,
                'expire'   => '2592000');
  $tmpfile = "/tmp/tmp-".mt_rand().".json";
  file_put_contents($tmpfile, json_encode($data));
  $out = shell_exec("curl -s -k -L -X POST -H 'Content-Type: application/json' --data-binary  @$tmpfile ${url}/api/json/create");
  unlink($tmpfile);
  $server = strtoupper($var['NAME']);
  $out = json_decode($out, TRUE);
  if (isset($out['result']['error'])){
    echo shell_exec("$notify -e 'Preclear Disk log upload failed' -s 'Alert [$server] - $title upload failed.' -d 'Upload of Unassigned Devices Log has failed: ".$out['result']['error']."' -i 'alert 1'");
    echo '{"result":"failed"}';
  } else {
    $resp = "${url}/".$out['result']['id']."/".$out['result']['hash'];
    exec("$notify -e 'Preclear Disk log uploaded - [".$out['result']['id']."]' -s 'Notice [$server] - $title uploaded.' -d 'A new copy of Unassigned Devices Log has been uploaded: $resp' -i 'normal 1'");
    echo '{"result":"'.$resp.'"}';
  }
}

function is_tmux_executable() {
  return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
}
function tmux_is_session($name) {
  exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
  return in_array($name, $screens);
}
function tmux_new_session($name) {
  if (! tmux_is_session($name)) {
    exec("/usr/bin/tmux new-session -d -x 140 -y 200 -s '${name}' 2>/dev/null");
  }
}
function tmux_get_session($name) {
  return (tmux_is_session($name)) ? shell_exec("/usr/bin/tmux capture-pane -t '${name}' 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : NULL;
}
function tmux_send_command($name, $cmd) {
  exec("/usr/bin/tmux send -t '$name' '$cmd' ENTER 2>/dev/null");
}
function tmux_kill_window($name) {
  if (tmux_is_session($name)) {
    exec("/usr/bin/tmux kill-session -t '${name}' >/dev/null 2>&1");
  }
}
function reload_partition($name) {
  exec("hdparm -z /dev/{$name} >/dev/null 2>&1 &");
}
function listDir($root) {
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();
  foreach ($iter as $path => $fileinfo) {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }
  return $paths;
}
function save_ini_file($file, $array) {
  $res = array();
  foreach($array as $key => $val) {
    if(is_array($val)) {
      $res[] = PHP_EOL."[$key]";
      foreach($val as $skey => $sval) $res[] = "$skey = ".(is_numeric($sval) ? $sval : '"'.$sval.'"');
    } else {
      $res[] = "$key = ".(is_numeric($val) ? $val : '"'.$val.'"');
    }
  }
  file_put_contents($file, implode(PHP_EOL, $res));
}
function get_unasigned_disks() {
  $paths          = listDir("/dev/disk/by-id");
  $disks_id       = preg_grep("#wwn-|-part#", $paths, PREG_GREP_INVERT);
  $disks_real     = array_map(function($p){return realpath($p);}, $disks_id);
  exec("/usr/bin/strings /boot/config/super.dat 2>/dev/null|grep -Po '.{10,}'", $disks_serial);
  $disks_cfg      = is_file("/boot/config/disk.cfg") ? parse_ini_file("/boot/config/disk.cfg") : array();
  $cache_serial   = array_flip(preg_grep("#cacheId#i", array_flip($disks_cfg)));
  $flash          = realpath("/dev/disk/by-label/UNRAID");
  $flash_serial   = array_filter($paths, function($p) use ($flash) {if(!is_bool(strpos($flash,realpath($p)))) return basename($p);});
  $unraid_serials = array_merge($disks_serial,$cache_serial,$flash_serial);
  $unraid_disks   = array();
  foreach($unraid_serials as $serial) {
    $unraid_disks = array_merge($unraid_disks, preg_grep("#".preg_quote($serial, "#")."#", $disks_id));
  }
  $unraid_real = array_map(function($p){return realpath($p);}, $unraid_disks);
  $unassigned  = array_flip(array_diff(array_combine($disks_id, $disks_real), $unraid_real));
  natsort($unassigned);
  foreach ($unassigned as $k => $disk) {
    unset($unassigned[$k]);
    $parts = array_values(preg_grep("#{$disk}-part\d+#", $paths));
    $device = realpath($disk);
    if (! is_bool(strpos($device, "/dev/sd")) || ! is_bool(strpos($device, "/dev/hd"))) {
      $unassigned[$disk] = array("device"=>$device,"partitions"=>$parts);
    }
  }
  debug("\nDisks:\n+ ".implode("\n+ ", array_map(function($k,$v){return "$k => $v";}, $disks_real, $disks_id)), "DEBUG");
  debug("\nunRAID Serials:\n+ ".implode("\n+ ", $unraid_serials), "DEBUG");
  debug("\nunRAID Disks:\n+ ".implode("\n+ ", $unraid_disks), "DEBUG");
  return $unassigned;
}

function is_mounted($dev) {
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}

function get_all_disks_info($bus="all") {
  // $d1 = time();
  $disks = get_unasigned_disks();
  foreach ($disks as $key => $disk) {
    if ($disk['type'] != $bus && $bus != "all") continue;
    $disk = array_merge($disk, get_disk_info($key));
    $disks[$key] = $disk;
  }
  // debug("get_all_disks_info: ".(time() - $d1));
  usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
  return $disks;
}
function get_info($device) {
  global $state_file;
  $whitelist = array("ID_MODEL","ID_SCSI_SERIAL","ID_SERIAL_SHORT");
  $state = is_file($state_file) ? @parse_ini_file($state_file, true) : array();
  if (array_key_exists($device, $state) && ! $reload) {
    return $state[$device];
  } else {
    $disk =& $state[$device];
    $udev = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device 2>/dev/null) 2>/dev/null"));
    $disk = array_intersect_key($udev, array_flip($whitelist));
    exec("smartctl -i -d sat,auto $device 2>/dev/null", $smartInfo);
    $disk['FAMILY']   = trim(split(":", array_values(preg_grep("#Model Family#", $smartInfo))[0])[1]);
    $disk['MODEL']    = trim(split(":", array_values(preg_grep("#Device Model#", $smartInfo))[0])[1]);
    if (empty($disk['FAMILY']) && empty($disk['MODEL'])) {
      $vendor   = trim(split(":", array_values(preg_grep("#Vendor#", $smartInfo))[0])[1]);
      $product  = trim(split(":", array_values(preg_grep("#Product#", $smartInfo))[0])[1]);
      $revision = trim(split(":", array_values(preg_grep("#Revision#", $smartInfo))[0])[1]);
      $disk['FAMILY'] = "{$vendor} {$product}";
      $disk['MODEL']  = "{$vendor} {$product} - Rev. {$revision}";
    }
    $disk['FIRMWARE'] = trim(split(":", array_values(preg_grep("#Firmware Version#", $smartInfo))[0])[1]);
    $disk['SIZE']     = intval(trim(shell_exec("blockdev --getsize64 ${device} 2>/dev/null")));
    save_ini_file($state_file, $state);
    return $state[$device];
  }
}

function get_disk_info($device, $reload=FALSE){
  $disk = array();
  $attrs = get_info($device);
  $disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
  $disk['serial']       = "{$attrs[ID_MODEL]}_{$disk[serial_short]}";
  $disk['device']       = realpath($device);
  $disk['family']       = $attrs['FAMILY'];
  $disk['model']        = $attrs['MODEL'];
  $disk['firmware']     = $attrs['FIRMWARE'];
  $disk['size']         = sprintf("%s %s", my_scale($attrs['SIZE'] , $unit), $unit);
  $disk['temperature']  = get_temp($device);
  return $disk;
}
function is_disk_running($dev) {
  $state = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  return ($state == 0) ? TRUE : FALSE;
}
function get_temp($dev) {
  $tc = "/var/state/{$plugin}/hdd_temp.json";
  $temps = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
  if (isset($temps[$dev]) && (time() - $temps[$dev]['timestamp']) < 180 ) {
    return $temps[$dev]['temp'];
  } else if (is_disk_running($dev)) {
    $temp = trim(shell_exec("smartctl -A -d sat,auto $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
    $temp = (is_numeric($temp)) ? $temp : "*";
    $temps[$dev] = array('timestamp' => time(),
                         'temp'      => $temp);
    file_put_contents($tc, json_encode($temps));
    return $temp;
  } else {
    return "*";
  }
}

switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    // echo "<script>var disksInfo =".json_encode($disks).";</script>";
    if ( count($disks) ) {
      $odd="odd";
      foreach ($disks as $disk) {
        $disk_mounted = false;
        foreach ($disk['partitions'] as $p) if (is_mounted(realpath($p))) $disk_mounted = TRUE;
        $temp = my_temp($disk['temperature']);
        $disk_name = basename($disk['device']);
        $serial = $disk['serial'];
        $disks_o .= "<tr class='$odd'>";
        $disks_o .= sprintf( "<td><img src='/webGui/images/%s'> %s</td>", ( is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" ), $disk_name);
        $disks_o .= "<td><span class='toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i>".($p?"<span style='margin:4px;'></span>":"<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>").$serial."</td>";
        $disks_o .= "<td>{$temp}</td>";
        $status = $disk_mounted ? "Disk mounted" : "<a class='exec' onclick='start_preclear(\"{$disk_name}\")'>Start Preclear</a>";
        if (tmux_is_session("preclear_disk_{$disk_name}")) {
          $status = "<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
          $status = "{$status}<a title='Clear' style='color:#CC0000;' class='exec' onclick='remove_session(\"{$disk_name}\");'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
        }
        if (is_file("/tmp/preclear_stat_{$disk_name}")) {
          $preclear = explode("|", file_get_contents("/tmp/preclear_stat_{$disk_name}"));
          if (count($preclear) > 3) {
            if (file_exists( "/proc/".trim($preclear[3]))) {
              $status = "<span style='color:#478406;'>{$preclear[2]}</span>";
              if (tmux_is_session("preclear_disk_{$disk_name}")) $status = "$status<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
              $status = "{$status}<a class='exec' title='Stop Preclear' style='color:#CC0000;' onclick='stop_preclear(\"{$serial}\",\"{$disk_name}\");'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
            } else {
              $status = "<span >{$preclear[2]}</span>";
              if (tmux_is_session("preclear_disk_{$disk_name}")) $status = "$status<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
              $status = "{$status}<a class='exec' style='color:#CC0000;font-weight:bold;' onclick='clear_preclear(\"{$disk_name}\");' title='Clear stats'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
            } 
          } else {
            $status = "<span >{$preclear[2]}</span>";
            if (tmux_is_session("preclear_disk_{$disk_name}")) $status = "$status<a class='exec' onclick='openPreclear(\"{$disk_name}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
            $status = "{$status}<a class='exec' style='color:#CC0000;font-weight:bold;'onclick='stop_preclear(\"{$serial}\",\"{$disk_name}\");' title='Clear stats'> <i class='glyphicon glyphicon-remove hdd'></i></a>";
          } 
        }

        $status = str_replace("^n", " " , $status);
        $disks_o .= "<td><span>${disk[size]}</span></td>";
        $disks_o .= (is_file($script_file)) ? "<td>$status</td>" : "<td>Script not present</td>";
        $disks_o .= "</tr>";
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    } else {
      $disks_o .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    echo json_encode(array("disks" => $disks_o, "info" => json_encode($disks)));
    break;

  case 'start_preclear':
    $device    = urldecode($_POST['device']);
    $op        = (isset($_POST['op']) && $_POST['op'] != "0") ? urldecode($_POST['op']) : "";
    // $notify    = (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".urldecode($_POST['-o']) : "";
    // $mail      = (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".urldecode($_POST['-M']) : "";
    $cycles    = isset($_POST['--cycles']) ? " --cycles ".urldecode($_POST['--cycles']) : "";
    $read_sz   = (isset($_POST['--read-size']) && $_POST['--read-size'] != 0) ? " --read-size ".urldecode($_POST['--read-size']) : "";
    // $write_sz  = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
    $pre_read  = (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
    $post_read = (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
    // $fast_read = (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";

    $noprompt  = " --no-prompt";
    // $wait_confirm = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;

    if (!$op) {
      $cmd = "$script_file {$op}{$cycles}{$read_sz}{$pre_read}{$post_read}{$noprompt} /dev/$device";
    } else {
      $cmd = "$script_file {$op}{$read_sz} /dev/$device";
    }
    @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");

    tmux_kill_window("preclear_disk_{$device}");
    tmux_new_session("preclear_disk_{$device}");
    tmux_send_command("preclear_disk_{$device}", $cmd);
    sleep(2);
    if ($wait_confirm && ! $noprompt) {
      foreach(range(0, 30) as $x) {
        if ( strpos(tmux_get_session("preclear_disk_{$device}"), "Answer Yes to continue") ) {
          sleep(1);
          tmux_send_command("preclear_disk_{$device}", "Yes");
          break;
        } else {
          sleep(1);
        }
      }
    }
    break;
  case 'stop_preclear':
    $device = urldecode($_POST['device']);
    tmux_kill_window("preclear_disk_{$device}");
    @unlink("/tmp/preclear_stat_{$device}");
    reload_partition($device);
    echo "<script>parent.location=parent.location;</script>";
    break;
  case 'clear_preclear':
    $device = urldecode($_POST['device']);
    tmux_kill_window("preclear_disk_{$device}");
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;
  case 'send_log':
    return sendLog();
    break;
}
switch ($_GET['action']) {
  case 'show_preclear':
    $device = urldecode($_GET['device']);
    echo (is_file("webGui/scripts/dynamix.js")) ? "<script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>" : 
                                                  "<script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>";
    $content = tmux_get_session("preclear_disk_".$device);
    if ( $content === NULL ) {
      echo "<script>window.close();</script>";
    }
    echo "<pre>".preg_replace("#\n+#", "<br>", $content)."</pre>";
    echo "<script>document.title='Preclear for disk /dev/{$device} ';$(function(){setTimeout('location.reload()',5000);});</script>";
    break;
}

?>