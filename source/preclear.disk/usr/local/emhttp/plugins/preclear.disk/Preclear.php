<?
$plugin = "preclear.disk";
require_once ("webGui/include/Helpers.php");
$script_file    = "/boot/config/plugins/preclear.disk/preclear_disk.sh";
$script_version =  (is_file($script_file)) ? trim(shell_exec("$script_file -v 2>/dev/null|cut -d: -f2")) : NULL;
$noprompt       = $script_version ? (strpos(file_get_contents($script_file), "noprompt") ? TRUE : FALSE ) : FALSE;
$state_file      = "/var/state/{$plugin}/state.ini";
if (isset($_POST['display'])) $display = $_POST['display'];

if (! is_dir(dirname($state_file)) ) {
  @mkdir(dirname($state_file),0777,TRUE);
}

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

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
    # Set TERM to xterm
    // tmux_send_command($name, "export TERM=xterm && tput clear");
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
  $disks = $paths = $unraid_disks = $unraid_cache = array();
  foreach (listDir("/dev/disk/by-id") as $p) {
    $r = realpath($p);
    if (!is_bool(strpos($r, "/dev/sd")) || !is_bool(strpos($r, "/dev/hd"))) {
      $paths[$r] = $p;
    }
  }
  natsort($paths);
  $unraid_flash = realpath("/dev/disk/by-label/UNRAID");
  foreach (parse_ini_string(shell_exec("/usr/bin/cat /proc/mdcmd 2>/dev/null")) as $k => $v) {
    if (strpos($k, "rdevName") !== FALSE && strlen($v)) {
      $unraid_disks[] = realpath("/dev/$v");
    }
  }
  foreach ($unraid_disks as $k) {$o .= "  $k\n";}; //debug("UNRAID DISKS:\n$o", "DEBUG");
  foreach (parse_ini_file("/boot/config/disk.cfg") as $k => $v) {
    if (strpos($k, "cacheId") !== FALSE && strlen($v)) {
      foreach ( preg_grep("#".$v."$#i", $paths) as $c) $unraid_cache[] = realpath($c);
    }
  }
  foreach ($unraid_cache as $k) {$g .= "  $k\n";}; //debug("UNRAID CACHE:\n$g", "DEBUG");
  foreach ($paths as $path => $d) {
    if (preg_match("#^(.(?!wwn|part))*$#", $d)) {
      if (! in_array($path, $unraid_disks) && ! in_array($path, $unraid_cache) && strpos($unraid_flash, $path) === FALSE) {
        if (in_array($path, array_map(function($ar){return $ar['device'];},$disks)) ) continue;
        $m = array_values(preg_grep("#$d.*-part\d+#", $paths));
        natsort($m);
        $disks[$d] = array("device"=>$path,"type"=>"ata","partitions"=>$m);
        //debug("Unassigned disk: $d", "DEBUG");
      } else {
        //debug("Discarded: => $d ($path)", "DEBUG");
        continue;
      }
    } 
  }
  return $disks;
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
    exec("smartctl -i -d sat,12 $device 2>/dev/null", $smartInfo);
    $disk['FAMILY']   = trim(split(":", array_values(preg_grep("#Model Family#", $smartInfo))[0])[1]);
    $disk['MODEL']    = trim(split(":", array_values(preg_grep("#Device Model#", $smartInfo))[0])[1]);
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
    $temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
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
    $device = urldecode($_POST['device']);
    $op       = (isset($_POST['op']) && $_POST['op'] != "0") ? " ".urldecode($_POST['op']) : "";
    $notify   = (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".urldecode($_POST['-o']) : "";
    $mail     = (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".urldecode($_POST['-M']) : "";
    $passes   = isset($_POST['-c']) ? " -c ".urldecode($_POST['-c']) : "";
    $read_sz  = (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".urldecode($_POST['-r']) : "";
    $write_sz = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
    $pre_read = (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
    $fast_read = (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";
    $noprompt = $noprompt ? " -J" : "";
    $wait_confirm = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
    if (! $op ){
      $cmd = "$script_file {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$fast_read}{$noprompt} /dev/$device";
      @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");
    } else if ($op == " -V"){
      $cmd = "$script_file {$op}{$fast_read}{$mail}{$notify}{$noprompt} /dev/$device";
      @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");
    } else {
      $cmd = "$script_file {$op}{$noprompt} /dev/$device";
    }
    // echo $cmd;
    // exit();
    tmux_kill_window("preclear_disk_{$device}");
    tmux_new_session("preclear_disk_{$device}");
    tmux_send_command("preclear_disk_{$device}", $cmd);
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