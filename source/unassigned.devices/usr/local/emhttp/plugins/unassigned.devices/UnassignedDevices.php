<?PHP
$plugin = "unassigned.devices";
require_once("plugins/${plugin}/include/lib.php");
require_once ("webGui/include/Helpers.php");

if (isset($_POST['display'])) $display = $_POST['display'];
if (isset($_POST['var'])) $var = $_POST['var'];

function pid_is_running($pid) {
  return file_exists( "/proc/$pid" );
}
function is_tmux_executable() {
  return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
}
function tmux_is_session($name) {
  if (is_tmux_executable()) {
    exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
    return in_array($name, $screens);
  } else {return false;}
}
function get_preclear_status($disk) {
  if (is_file("/tmp/preclear_stat_{$disk}")) {
    $preclear   = explode("|", file_get_contents("/tmp/preclear_stat_{$disk}"));
    $pid        = (count($preclear) > 3) ? trim($preclear[3]) : null;
    $is_running = ($pid && file_exists( "/proc/{$pid}")) ? true : false;
    if ($pid && $is_running) {
      $status = "<span style='color:#478406;'>{$preclear[2]}</span>";
    } elseif ($pid && ! $is_running) {
      $status  = "<span style='color:#CC0000;'>{$preclear[2]} ";
      $status .= "<a class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_preclear(\"{$disk}\");' title='Clear stats'> ";
      $status .= "<i class='glyphicon glyphicon-remove hdd'></i></a></span>";
    } else {
      $status  = "{$preclear[2]}<a class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_preclear(\"{$disk}\");' title='Clear stats'> ";
      $status .= "<i class='glyphicon glyphicon-remove hdd'></i></a>";
    }
    if (tmux_is_session("preclear_disk_{$disk}") && is_file("plugins/preclear.disk/Preclear.php")) {
      $status = "$status<a class='openPreclear exec' onclick='openPreclear(\"{$disk}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
    }
    return "<i class='glyphicon glyphicon-dashboard hdd'></i><span style='margin:4px;'></span>{$status}";
  }
}

function render_used_and_free($partition) {
  global $display;
  $o = "";
  if (strlen($partition['target'])) {
    switch ($display['text']) {
    case 0:
      $text1 = true; $text2 = true; break;
    case 1: case 2:
      $text1 = false; $text2 = false; break;
    case 10: case 20:
      $text1 = true; $text2 = false; break;
    case 11: case 21:
      $text1 = false; $text2 = true; break;
    }
    if ($text1) {
      $o .= "<td>".my_scale($partition['used'], $unit)." $unit</td>";
    } else {
      $used = $partition['size'] ? 100 - round(100*$partition['avail']/$partition['size']) : 0;
      $o .= "<td><div class='usage-disk'><span style='margin:0;width:{$used}%' class='".usage_color($used,false)."'><span>".my_scale($partition['used'], $unit)." $unit</span></span></div></td>";
    }
    if ($text2) {
      $o .= "<td>".my_scale($partition['avail']*1024, $unit)." $unit</td>";
    } else {
      $free = $partition['size'] ? round(100*$partition['avail']/$partition['size']) : 0;
      $o .=  "<td><div class='usage-disk'><span style='margin:0;width:{$free}%' class='".usage_color($free,true)."'><span>".my_scale($partition['avail'], $unit)." $unit</span></span></div></td>";
    }
  } else {
    $o .= "<td>-</td><td>-</td>";
  }
  return $o;
}

function render_partition($disk, $partition) {
  global $plugin, $paths, $echo;
  if (! isset($partition['device'])) return array();
  $out = array();
  $mounted = is_mounted($partition['device']);
  if ( (! $mounted &&  $partition['fstype'] != 'btrfs') || ($mounted && $partition['fstype'] == 'btrfs') ) {
    $fscheck = "<a class='exec' onclick='openWindow_fsck(\"/plugins/${plugin}/include/fsck.php?disk={$partition[device]}&fs={$partition[fstype]}&type=ro\",\"Check filesystem\",600,900);'><i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}</a>";
  } else {
    $fscheck = "<i class='glyphicon glyphicon-th-large partition'></i>{$partition[part]}";
  }

  $rm_partition = (get_config("LOCAL", "Config", "destructive_mode") == "enabled") ? "<span title='Remove Partition' class='exec' style='color:#CC0000;font-weight:bold;' onclick='rm_partition(this,\"{$disk[device]}\",\"{$partition[part]}\");'><i class='glyphicon glyphicon-remove hdd'></i></span>" : "";
  $mpoint = "<div>{$fscheck}<i class='glyphicon glyphicon-arrow-right'></i>";
  if ($mounted) {
    $mpoint .= "<a href='/Shares/Browse?dir={$partition[mountpoint]}'>{$partition[mountpoint]}</a></div>";
  } else {
    $action = "/plugins/${plugin}/UnassignedDevices.php?action=change_mountpoint&device={$partition[serial]}&name=mountpoint.{$partition[part]}&context=LOCAL";
    $mpoint .= "<form method='POST' action='$action' target='progressFrame' style='display:inline;margin:0;padding:0;'><span class='text exec'>";
    $mpoint .= "<a>{$partition[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$partition[mountpoint]}' hidden />";
    $mpoint .= "</form> {$rm_partition}</div>";
  }
  $mbutton = make_mount_button($partition);
  $out[] = "<tr class='$outdd toggle-parts toggle-".basename($disk['device'])."' style='__SHOW__' >";
  $out[] = "<td></td>";
  $out[] = "<td>{$mpoint}</td>";
  $out[] = "<td class='mount'>{$mbutton}</td>";
  $out[] = "<td>-</td>";
  $out[] = "<td >".$partition['fstype']."</td>";
  $out[] = "<td><span>".my_scale($partition['size'], $unit)." $unit</span></td>";
  $out[] = "<td>".(strlen($partition['target']) ? shell_exec("lsof '${partition[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td>";
  $out[] = "<td>-</td>";
  $out[] = "<td><input type='checkbox' class='toggle_share' info='".htmlentities(json_encode($partition))."' ".(($partition['shared']) ? 'checked':'')."></td>";
  $out[] = render_used_and_free($partition);
  $out[] = "<td><a href='/Main/EditScript?s=".urlencode($partition['serial'])."&l=".urlencode(basename($partition['mountpoint']))."&p=".urlencode($partition['part'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_config("LOCAL",$partition['serial'],"command.{$partition[part]}")) ? "":"opacity: 0.4;" )."'></a></td>";
  $out[] = "<tr>";
  return $out;
}

function make_mount_button($device) {
  global $paths;
  $button = "<span style='width:auto;text-align:right;'><button type='button' device='{$device[device]}' class='array' context='%s' role='%s' %s><i class='%s'></i>  %s</button></span>";
  if (isset($device['partitions'])) {
    $mounted = in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $device['partitions']));
    $disable = count(array_filter($device['partitions'], function($p){ if (! empty($p['fstype']) && $p['fstype'] != "precleared") return TRUE;})) ? "" : "disabled";
    $format = (isset($device['partitions']) && ! count($device['partitions'])) || $device['partitions'][0]['fstype'] == "precleared" ? true : false;
    $context = "disk";
  } else {
    $mounted = is_mounted($device['device']);
    $disable = (! empty($device['fstype']) && $device['fstype'] != "precleared") ? "" : "disabled";
    $format = ((isset($device['fstype']) && empty($device['fstype'])) || $device['fstype'] == "precleared") ? true : false;
    $context = "partition";
  }
  $preclearing   = is_file("/tmp/preclear_stat_".basename($device['disk']));
  $is_mounting   = array_values(preg_grep("@/mounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
  $is_mounting   = (time() - filemtime($is_mounting) < 300) ? TRUE : FALSE;
  $is_unmounting = array_values(preg_grep("@/unmounting_".basename($device['device'])."@i", listDir(dirname($paths['mounting']))))[0];
  $is_unmounting = (time() - filemtime($is_unmounting) < 300) ? TRUE : FALSE;
  if ($format) {
    $disable = (get_config("LOCAL", "Config", "destructive_mode") == "enabled" && ! $preclearing) ? "" : "disabled";
    $button = sprintf($button, $context, 'format', $disable, 'glyphicon glyphicon-erase', 'Format');
  } elseif ($is_mounting) {
    $button = sprintf($button, $context, 'umount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Mounting...');
  } elseif ($is_unmounting) {
    $button = sprintf($button, $context, 'mount', 'disabled', 'fa fa-circle-o-notch fa-spin', 'Unmounting...');
  } elseif ($mounted) {
    $button = sprintf($button, $context, 'umount', '', 'glyphicon glyphicon-export', 'Unmount');
  } else {
    $button = sprintf($button, $context, 'mount', $disable, 'glyphicon glyphicon-import', 'Mount');
  }
  return $button;
}

switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    $preclear = "";
    if ( count($disks) ) {
      $odd="odd";
      foreach ($disks as $disk) {
        $mounted       = in_array(TRUE, array_map(function($ar){return is_mounted($ar['device']);}, $disk['partitions']));
        $temp          = my_temp($disk['temperature']);
        $disk_name     = basename($disk['device']);
        $p             = (count($disk['partitions']) <= 1) ? render_partition($disk, $disk['partitions'][0]) : FALSE;
        $preclearing   = is_file("/tmp/preclear_stat_{$disk_name}") ? get_preclear_status($disk_name) : NULL;
        $is_precleared = ($disk['partitions'][0]['fstype'] == "precleared") ? true : false;
        $mbutton = make_mount_button($disk);

        $preclear = $preclearing ? "<div class='preclear_status' disk='{$disk_name}'>".str_replace('%','%%',$preclearing)."</div>" : "";
        if (! $mounted && file_exists("plugins/preclear.disk/icons/precleardisk.png")) {
          $preclear = "<span style='margin:4px;'></span><a title='Preclear' href='/Settings/Preclear?disk={$disk_name}'><img src='/plugins/preclear.disk/icons/precleardisk.png'></a>{$preclear}";
        }

        $hdd_serial = "<span class='toggle-hdd %s' hdd='{$disk_name}'><i class='glyphicon glyphicon-hdd hdd'></i>%s{$disk[serial]}</span>{$preclear}";
        if ($p === FALSE) {
          $hdd_serial = sprintf($hdd_serial, "exec", "<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>");
        } elseif(empty($p)) {
          $hdd_serial = sprintf($hdd_serial, "", "<span style='margin:4px;'></span>");
        } elseif ( $is_precleared || $preclearing) {
          $hdd_serial = sprintf($hdd_serial, "", "<span style='margin:4px;'></span>");
        } else {
          $hdd_serial = sprintf($hdd_serial, "exec", "<i class='glyphicon glyphicon-plus-sign glyphicon-append'></i>");
        }

        $o_disks .= "<tr class='$odd toggle-disk'>";
        $o_disks .= "<td><img src='/webGui/images/".(is_disk_running($disk['device']) ? "green-on.png":"green-blink.png" )."'> ";
        $o_disks .= "<a href='/Main/Device?name={$disk_name}&file=/tmp/screen_buffer'>{$disk_name}</a></td>";
        $o_disks .= "<td>{$hdd_serial}</td>";
        $o_disks .= "<td class='mount'>{$mbutton}</td>";
        $o_disks .= "<td>{$temp}</td>";
        $o_disks .= ($p)?$p[5]:"<td>-</td>";
        $o_disks .= "<td>".my_scale($disk['size'],$unit)." {$unit}</td>";
        $o_disks .= ($p)?$p[7]:"<td>-</td><td>-</td>";
        $o_disks .= "<td><input type='checkbox' class='automount' context='LOCAL' serial='".$disk['serial']."' ".(($disk['partitions'][0]['automount']) ? 'checked':'')."></td>";
        $o_disks .= ($p)?$p[9]:"<td>-</td>";
        $o_disks .= ($p)?$p[10]:"<td>-</td>";
        $o_disks .= ($p)?$p[11]:"<td>-</td>";
        $o_disks .= "</tr>";
        if (! $is_precleared && ! $preclearing) {
          foreach ($disk['partitions'] as $partition) {
            foreach (render_partition($disk, $partition) as $l) $o_disks .= str_replace("__SHOW__", (count($disk['partitions']) >1 ? "display:none;":"display:none;" ), $l );
          }
        }
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    } else {
      $o_disks .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }

    # Remote
    $mounts = get_remote_shares(); 
    if (count($mounts)) {
      $odd="odd";
      foreach ($mounts as $mount) {
        $mounted = is_mounted($mount['device']);
        $is_alive = (trim(exec("ping -c 1 -W 1 {$mount[ip]} >/dev/null 2>&1; echo $?")) == 0 ) ? TRUE : FALSE;
        $protocol = $mount['protocol'];

        $o_remotes .= "<tr class='$odd' info='".htmlentities(json_encode($mount))."'>";
        $o_remotes .= sprintf( "<td><img src='/webGui/images/%s'> ".strtolower($protocol)."</td>", ( $is_alive ? "green-on.png":"green-blink.png" ));
        $o_remotes .= "<td><div><img src='{$mount[icon]}'><span style='margin:4px;'></span>{$mount[device]}</div></td>";
        if ($mounted) {
          $o_remotes .= "<td><span style='margin:4px;'><a href='/Shares/Browse?dir={$mount[mountpoint]}'>{$mount[mountpoint]}</a></td>";
        } else {
          $action = "/plugins/${plugin}/UnassignedDevices.php?action=change_mountpoint&device={$mount[serial]}&name=mountpoint&context={$protocol}";
          $o_remotes .= "<td><form method='POST' action='$action' target='progressFrame' style='display:inline;margin:0;padding:0;'><span class='text exec'>";
          $o_remotes .= "<a>{$mount[mountpoint]}</a></span><input class='input' type='text' name='mountpoint' value='{$mount[mountpoint]}' hidden />";
          $o_remotes .= "</form></td>";
        }
        $mbutton =  "<td><span style='width:auto;text-align:right;'><button type='button' class='array' onclick=\"disk_op(this, '%s','{$mount[device]}');\">";
        $mbutton .= "<i class='glyphicon glyphicon-%s'></i> %s</button></span></td>";
        $o_remotes .= ($mounted) ? sprintf($mbutton, 'umount', 'export', 'Unmount') : 
                          sprintf($mbutton, 'mount', 'import', 'Mount');

        $o_remotes .= "<td><span>".my_scale($mount['size'], $unit)." $unit</span></td>";
        $o_remotes .= render_used_and_free($mount);
        $link = "<a href='/Main/EditScript?s=".urlencode($mount['serial'])."&l=".urlencode(basename($mount['mountpoint']))."'>";
        $o_remotes .= "<td><input type='checkbox' class='automount' context='{$protocol}' serial='{$mount[serial]}' ".($mount['automount'] ? 'checked':'')."></td>";
        $opacity = get_config($protocol, $mount['serial'],"command") ? "" : "opacity: 0.4;";
        $o_remotes .= "<td>{$link}<img src='/webGui/images/default.png' style='cursor:pointer;width:16px;{$opacity}'></a></td>";
        $o_remotes .= $mounted ? "<td><i class='glyphicon glyphicon-remove hdd'></i></td>" : 
                        "<td><a class='exec' style='color:#CC0000;font-weight:bold;' onclick='remove_config(this,\"{$protocol}\");' title='Remove {$protocol} mount'>
                        <i class='glyphicon glyphicon-remove hdd'></i></a></td>";
        $o_remotes .= "</tr>";
        $odd = ($odd == "odd") ? "even" : "odd";
      }
    } else {
      $o_remotes .= "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No remote shares configured.</td></tr>";
    }

    $config_file = $GLOBALS["paths"]["config_file"];
    $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
    $disks_serials = array();
    foreach ($disks as $disk) $disks_serials[] = $disk['serial'];
    $ct = "";
    foreach ($config as $serial => $value) {
      if($serial == "Config") continue;
      if (! preg_grep("#${serial}#", $disks_serials)){

        $ct .= "<tr info='".htmlentities(json_encode(array("serial" => $serial)))."'><td><img src='/webGui/images/green-blink.png'> missing</td><td>$serial</td><td><input type='checkbox' class='automount' serial='${serial}' ".( is_automount("LOCAL", $serial) ? 'checked':'' )."></td><td colspan='7'><a style='cursor:pointer;' onclick='remove_config(this,\"LOCAL\")'>Remove</a></td></tr>";
      }
    }
    if (strlen($ct)) {
      $misc .= "<div id='title'><span class='left'><img src='/plugins/{$plugin}/icons/hourglass.png' class='icon'>Historical Devices</span></div>";
      $misc .= "<table class='usb_absent custom_head'><thead><tr><td>Device</td><td>Serial Number</td><td>Auto mount</td><td colspan='7'>Remove config</td></tr></thead><tbody>${ct}</tbody></table>";
    }

    echo json_encode(array("disks" => $o_disks, "remotes" => $o_remotes, "misc" => $misc));
    break;

  /*  CONFIG  */
  case 'automount':
    $serial  = urldecode(($_POST['serial']) );
    $status  = urldecode(($_POST['status'] ));
    $context = urldecode(($_POST['context']));
    echo json_encode(array( 'automount' => toggle_automount($context, $serial, $status) ));
  break;
  case 'get_command':
    $serial = urldecode(($_POST['serial']));
    $part   = urldecode(($_POST['part']));
    echo json_encode(array( 'command' => get_config("LOCAL", $serial, "command.{$part}"), "background" =>  get_config("LOCAL", $serial, "command_bg.{$part}") ));
  break;
  case 'set_command':
    $serial  = urldecode($_POST['serial']);
    $part    = urldecode($_POST['part']);
    $cmd     = urldecode($_POST['command']);
    $context = urldecode($_POST['context']);
    debug(" set_config($context, $serial, 'command{$part}', $cmd);");
    // set_config($context, $serial, "command_bg{$part}", urldecode($_POST['background']));
    set_config($context, $serial, "command{$part}", $cmd);
  break;
  case 'remove_config':
    $device = urldecode(($_POST['device']));
    $context = urldecode(($_POST['context']));
    echo json_encode(array( 'result' => remove_config($context, $device)));
  break;
  case 'toggle_share':
    $info = json_decode(html_entity_decode($_POST['info']), true);
    $status = urldecode(($_POST['status']));
    $result = toggle_share($info['serial'], $info['part'], $status);
    echo json_encode(array( 'result' => $result));
    if ($result && strlen($info['target'])) {
      add_smb_share($info['mountpoint'], $info['label']);
      add_nfs_share($info['mountpoint']);
    } else {
      rm_smb_share($info['mountpoint'], $info['label']);
      rm_nfs_share($info['mountpoint']);
    }
  break;

  /*  DISK  */
  case 'mount':
    $device = urldecode($_POST['device']);
    exec("plugins/${plugin}/scripts/unassigned_mount '$device' >/dev/null 2>&1 &");
  break;
  case 'umount':
    $device = urldecode($_POST['device']);
    echo exec("plugins/${plugin}/scripts/unassigned_umount '$device' >/dev/null 2>&1 &");
  break;
  case 'rescan_disks':
    exec("/sbin/udevadm trigger --action=change 2>&1");
  break;
  case 'format_disk':
    $device = urldecode($_POST['device']);
    $fs = urldecode($_POST['fs']);
    echo json_encode(array( 'result' => format_disk($device, $fs)));
    break;
  case 'format_partition':
    $device = urldecode($_POST['device']);
    $fs = urldecode($_POST['fs']);
    echo json_encode(array( 'result' => format_partition($device, $fs)));
    break;
  case 'rm_partition':
    $device = urldecode($_POST['device']);
    $partition = urldecode($_POST['partition']);
    remove_partition($device, $partition );
    break;

  /*  SAMBA  */
  case 'list_samba_shares':
    $ip = urldecode($_POST['IP']);
    $user = isset($_POST['USER']) ? urlencode($_POST['USER']) : NULL;
    $pass = isset($_POST['PASS']) ? urlencode($_POST['PASS']) : NULL;
    $login = $user ? ($pass ? "-U '{$user}%{$pass}'" : "-U '{$user}' -N") : "-U%";
    echo shell_exec("smbclient -g -L $ip $login 2>&1|awk -F'|' '/Disk/{print $2}'|sort");
  break;
  case 'list_samba_hosts':
    $hosts = array();
    foreach ( explode(PHP_EOL, shell_exec("/usr/bin/nmblookup {$var[WORKGROUP]} 2>/dev/null") ) as $l ) {
      if (! is_bool( strpos( $l, "<00>") ) ) {
        $ip = explode(" ", $l)[0];
        foreach ( explode(PHP_EOL, shell_exec("/usr/bin/nmblookup -r -A $ip 2>&1") ) as $l ) {
          if (! is_bool( strpos( $l, "<00>") ) ) {
            $hosts[] = trim(explode(" ", $l)[0])."\n";
            break;
          }
        }
      }
    }
    natsort($hosts);
    echo implode(PHP_EOL, array_unique($hosts));
    break;
  case 'add_remote_share':
    unset($_POST['action']);
    $protocol = urldecode($_POST['PROTOCOL']);
    $ip       = urldecode($_POST['IP']);
    $share    = urldecode($_POST['SHARE']);
    foreach ($_POST as $k => $v) {
      if (strlen($v)) set_config($protocol, "{$protocol}://${ip}/${share}", strtolower(urldecode($k)), urldecode($v));
    }
  break;

  /*  NFS  */
  case 'list_nfs_shares':
    $ip = urldecode($_POST['IP']);
    echo shell_exec("/usr/sbin/showmount --no-headers -e '{$ip}' 2>/dev/null|cut -d' ' -f1|sort");
    break;
  case 'list_nfs_hosts':
    $all_ips = preg_replace("@\d+$@","1-255",$var['IPADDR']);
    echo shell_exec("/usr/bin/nmap --open -p T:2049 --min-parallelism 100 {$all_ips} 2>&1|grep -oP 'Nmap scan report for \K.*'");
    break;

  /*  MISC */
  case 'detect':
    echo json_encode(array("reload" => is_file($paths['reload'])));
    break;
  case 'remove_hook':
    @unlink($paths['reload']);
    break;
  case 'get_preclear':
    $device = urldecode($_POST['device']);
    echo get_preclear_status($device);
  break;
  case 'rm_preclear':
    $device = urldecode($_POST['device']);
    @unlink("/tmp/preclear_stat_{$device}");
  break;
  case 'send_log':
    return sendLog();
    break;
}
switch ($_GET['action']) {
  case 'change_mountpoint':
    $device     = urldecode($_GET['device']);
    $name       = urldecode($_GET['name']);
    $context    = urldecode($_GET['context']);
    $mountpoint = urldecode($_POST['mountpoint']);
    set_config($context, $device, $name, $mountpoint);
    require_once("update.htm");
    break;
}
?>
