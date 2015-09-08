<?
$plugin = "preclear.disk";
require_once ("webGui/include/Helpers.php");
$script_file    = "/boot/config/plugins/preclear.disk/preclear_disk.sh";
$script_version =  (is_file($script_file)) ? trim(shell_exec("$script_file -v 2>/dev/null|cut -d: -f2")) : NULL;
$fast_postread  = $script_version ? (strpos(file_get_contents($script_file), "fast_postread") ? TRUE : FALSE ) : FALSE;
$notifications  = $script_version ? (strpos(file_get_contents($script_file), "notify_channels") ? TRUE : FALSE ) : FALSE;

if (isset($_POST['display'])) $display = $_POST['display'];

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
    exec("/usr/bin/tmux kill-session -t '${name}' 2>/dev/null");
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
function get_unasigned_disks() {
  $disks = array();
  $paths = listDir("/dev/disk/by-id");
  natsort($paths);
  $unraid_flash = realpath("/dev/disk/by-label/UNRAID");
  $unraid_disks = array();
  foreach (parse_ini_string(shell_exec("/usr/bin/cat /proc/mdcmd 2>/dev/null")) as $k => $v) {
    if (strpos($k, "rdevName") !== FALSE && strlen($v)) {
      $unraid_disks[] = realpath("/dev/$v");
    }
  }
  // foreach ($unraid_disks as $k) {$o .= "  $k\n";}; debug("UNRAID DISKS:\n$o");
  $unraid_cache = array();
  foreach (parse_ini_file("/boot/config/disk.cfg") as $k => $v) {
    if (strpos($k, "cacheId") !== FALSE && strlen($v)) {
      foreach ( preg_grep("#".$v."$#i", $paths) as $c) $unraid_cache[] = realpath($c);
    }
  }
  // foreach ($unraid_cache as $k) {$g .= "  $k\n";}; debug("UNRAID CACHE:\n$g");
  foreach ($paths as $d) {
    $path = realpath($d);
    if (preg_match("#^(.(?!wwn|part))*$#", $d)) {
      if (! in_array($path, $unraid_disks) && ! in_array($path, $unraid_cache) && strpos($unraid_flash, $path) === FALSE) {
        if (in_array($path, array_map(function($ar){return $ar['device'];},$disks)) ) continue;
        $m = array_values(preg_grep("#$d.*-part\d+#", $paths));
        natsort($m);
        $disks[$d] = array("device"=>$path,"type"=>"ata","partitions"=>$m);
        // debug("Unassigned disk: $d");
      } else {
        // debug("Discarded: => $d ($path)");
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
    $disk['temperature'] = get_temp($key);
    $disk['size'] = sprintf("%s %s", my_scale( intval(trim(shell_exec("blockdev --getsize64 ${key} 2>/dev/null"))) , $unit), $unit);
    $disk = array_merge($disk, get_disk_info($key));
    $disks[$key] = $disk;
  }
  // debug("get_all_disks_info: ".(time() - $d1));
  usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
  return $disks;
}
function get_disk_info($device, $reload=FALSE){
  $disk = array();
  $attrs = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device ) 2>/dev/null"));
  exec("smartctl -i -d sat,12 $device 2>/dev/null", $smartInfo);
  $device = realpath($device);
  $disk['serial']       = $attrs['ID_SERIAL'];
  $disk['serial_short'] = $attrs['ID_SERIAL_SHORT'];
  $disk['device']       = $device;
  $disk['family']       = trim(split(":", array_values(preg_grep("#Model Family#", $smartInfo))[0])[1]);
  $disk['model']        = trim(split(":", array_values(preg_grep("#Device Model#", $smartInfo))[0])[1]);
  $disk['firmware']     = trim(split(":", array_values(preg_grep("#Firmware Version#", $smartInfo))[0])[1]);
  return $disk;
}
function is_disk_running($dev) {
  $state = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  return ($state == 0) ? TRUE : FALSE;
}
function get_temp($dev) {
  if (is_disk_running($dev)) {
    $temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
    return (is_numeric($temp)) ? $temp : "*";
  }
  return "*";
}

switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    echo "<script>var disksInfo = Object; var disksInfo =".json_encode($disks).";</script>";
    echo "<table class='preclear custom_head'><thead><tr><td>Device</td><td>Identification</td><td>Temp</td><td>Size</td><td>Preclear Status</td></tr></thead>";
    echo "<tbody>";
    if ( count($disks) ) {
      $odd="odd";
      foreach ($disks as $disk) {
        $disk_mounted = false;
        foreach ($disk['partitions'] as $p) if (is_mounted(realpath($p))) $disk_mounted = TRUE;
        $temp = my_temp($disk['temperature']);
        $disk_name = basename($disk['device']);
        $serial = $disk['serial'];
        echo "<tr class='$odd'>";
        printf( "<td><img src='/webGui/images/%s'> %s</td>", ( is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" ), $disk_name);
        echo "<td><span class='toggle-hdd' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i>".($p?"<span style='margin:4px;'></span>":"<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>").$serial."</td>";
        echo "<td>{$temp}</td>";
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
        echo "<td><span>${disk[size]}</span></td>";
        echo (is_file($script_file)) ? "<td>$status</td>" : "<td>Script not present</td>";
        echo "</tr>";
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    } else {
      echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    echo "</tbody></table><div style='min-height:20px;'></div>";
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
    $wait_confirm = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
    if (! $op ){
      $cmd = "$script_file {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$fast_read} /dev/$device";
      @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");
    } else if ($op == " -V"){
      $cmd = "$script_file {$op}{$fast_read}{$mail}{$notify} /dev/$device";
      @file_put_contents("/tmp/preclear_stat_{$device}","{$device}|NN|Starting...");
    } else {
      $cmd = "$script_file {$op} /dev/$device";
    }
    // echo $cmd;
    // exit();
    tmux_kill_window("preclear_disk_{$device}");
    tmux_new_session("preclear_disk_{$device}");
    tmux_send_command("preclear_disk_{$device}", $cmd);
    if ($wait_confirm) {
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