<?
$plugin = "gfjardim.usb.automount";

$paths = array("smb_extra"       => "/boot/config/smb-extra.conf",
               "smb_usb_shares"  => "/etc/samba/smb-usb-shares",
               "usb_mount_point" => "/mnt/usb",
               "log"             => "/var/log/usb_automount.log",
               "config_file"     => "/boot/config/plugins/${plugin}/automount.cfg"
               );


#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

$echo = function($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

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

function debug($m){
  $c = (is_file($GLOBALS["paths"]["log"])) ? @file($GLOBALS["paths"]["log"],FILE_IGNORE_NEW_LINES) : array();
  $c[] = date("D M j G:i:s T Y").": $m";
  file_put_contents($GLOBALS["paths"]["log"], implode(PHP_EOL, $c));
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

function formatBytes($size) {
  if ($size == 0){ return "0 B";}
  $base = log($size) / log(1024);
  $suffix = array('B', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
  return round(pow(1024, $base - floor($base)), 1) ." ". $suffix[floor($base)];
}

function safe_name($string) {
  $string = str_replace("\\x20", " ", $string);
  $string = htmlentities($string, ENT_QUOTES, 'UTF-8');
  $string = preg_replace('~&([a-z]{1,2})(acute|cedil|circ|grave|lig|orn|ring|slash|th|tilde|uml);~i', '$1', $string);
  $string = html_entity_decode($string, ENT_QUOTES, 'UTF-8');
  $string = preg_replace('~[^0-9a-z -_]~i', '', $string);
  $string = preg_replace('~[-_]~i', ' ', $string);
  return trim($string);
}

function exist_in_file($file, $val) {
  return (preg_grep("%${val}%", @file($file))) ? TRUE : FALSE;
}


#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($sn, $val) {
  $config_file = $GLOBALS["paths"]["config_file"];
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  return (isset($config[$sn][$val])) ? $config[$sn][$val] : NULL;
}

function is_automount($sn) {
  $auto = get_config($sn, "automount");
  return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
}


function toggle_automount($sn, $status) {
  $config_file = $GLOBALS["paths"]["config_file"];
  if (! is_file($config_file)) @mkdir(dirname($config_file),0777,TRUE);
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  $config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
  save_ini_file($config_file, $config);
  return ($config[$sn]["automount"] == "yes") ? TRUE : FALSE;
}

function execute_script($info, $action) { 
  $out = ''; 
  $error = '';
  putenv("ACTION=${action}");
  foreach ($info as $key => $value) putenv(strtoupper($key)."=${value}");
  $cmd = get_config($info['serial'], "command");
  if (! $cmd) {debug("Command not available, skipping."); return FALSE;}
  debug("Running command '${cmd}' with action '${action}'.");
  @chmod($cmd, 0777);
  exec("$cmd > /tmp/${info[serial]}.log 2>&1");
}

function set_command($sn, $cmd) {
  $config_file = $GLOBALS["paths"]["config_file"];
  if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  $config[$sn]["command"] = htmlentities($cmd, ENT_COMPAT);
  save_ini_file($config_file, $config);
  return (isset($config[$sn]["command"])) ? TRUE : FALSE;
}

function remove_config_disk($sn) {
  $config_file = $GLOBALS["paths"]["config_file"];
  if (! is_file($config_file)) @mkdir(dirname($config_file),0666,TRUE);
  $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
  unset($config[$sn]);
  save_ini_file($config_file, $config);
  return (isset($config[$sn])) ? TRUE : FALSE;
}


#########################################################
############        MOUNT FUNCTIONS        ##############
#########################################################

function is_mounted($dev) {
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}

function get_mount_params($fs) {
  switch ($fs) {
    case 'hfsplus':
    return "force,rw,users,async,umask=000";
    break;
    default:
    return "auto,async,nodev,nosuid,umask=000";
    break;
  }
}

function do_mount($dev, $dir, $fs) {
  if ( file_exists($dev) ) {
    return do_mount_disk($dev, $dir, $fs);
  } else {
    return do_mount_mtp($dev, $dir);
  }
}

function do_unmount($dev, $dir) {
  if ( file_exists($dev) ) {
    return do_umount_disk($dev, $dir, $fs);
  } else {
    return do_umount_mtp($dev, $dir);
  }
}




#########################################################
############        SHARE FUNCTIONS         #############
#########################################################

function is_shared($name) {
  return ( shell_exec("smbclient -g -L localhost -U% 2>&1|awk -F'|' '/Disk/{print $2}'|grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}

function add_smb_share($dir, $share_name) {
  global $paths;
  if(!is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  $share_cont = sprintf("[%s]\npath = %s\nread only = No\nguest ok = Yes\ncreate mode = 0644\ndirectory mode = 0755 ", $share_name, $dir);
  debug("Defining share '$share_name' on file '$share_conf' .");
  file_put_contents($share_conf, $share_cont);
  if (! exist_in_file($paths['smb_extra'], $share_name)) {
    debug("Adding share $share_name to ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    $c[] = ""; $c[] = "include = $share_conf";
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if( ! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("killall -s 1 smbd 2>/dev/null && killall -s 1 nmbd 2>/dev/null");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
  if(is_shared($share_name)) {
    debug("Directory '${dir}' shared successfully."); return TRUE;
  } else {
    debug("Sharing directory '${dir}' failed."); return FALSE;
  }
}

function rm_smb_share($dir, $share_name) {
  global $paths;
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  debug("Removing share definitions from '$share_conf'.");
  if (is_file($share_conf)) {
    @unlink($share_conf);
    debug("Removing share definitions from '$share_conf'.");
  }
  if (exist_in_file($paths['smb_extra'], $share_conf)) {
    debug("Removing share definitions from ".$paths['smb_extra']);
    $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
    # Do Cleanup
    $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
    foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
    $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
    $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
    file_put_contents($paths['smb_extra'], $c);
  }
  debug("Reloading Samba configuration. ");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) close-share '${share_name}' 2>&1");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
  if(! is_shared($share_name)) {
    debug("Successfully removed share '${share_name}'."); return TRUE;
  } else {
    debug("Removal of share '${share_name}' failed."); return FALSE;
  }
}


#########################################################
############         DISK FUNCTIONS         #############
#########################################################

function do_mount_disk($dev, $dir, $fs) {
  if (! is_mounted($dev) || is_mounted($dir)) {
    @mkdir($dir,0777,TRUE);
    $cmd = "mount -t auto -o ".get_mount_params($fs)." '${dev}' '${dir}'";
    debug("Mounting drive with command: $cmd");
    $o = shell_exec($cmd." 2>&1");
    foreach (range(0,5) as $t) {
      if (is_mounted($dev)) {
        debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
  }
}

function do_umount_disk($dev, $dir) {
  if (is_mounted($dev) != 0){
    debug("Unmounting ${dev}...");
    $o = shell_exec("umount '${dev}' 2>&1");
    for ($i=0; $i < 10; $i++) {
      if (! is_mounted($dev)){
        rmdir($dir);
        debug("Successfully unmounted '$dev'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Unmount of ${dev} failed. Error message: $o"); return FALSE;
  }
}

function get_usb_disks() {
  $disks = array();
  foreach (listDir("/dev/disk/by-path") as $d) {
    if (preg_match("/.*(usb).*?-part\d+/i", $d)){
      $d = realpath($d);
      if ($d != realpath("/dev/disk/by-label/UNRAID")) {
        $disks[] = $d;
      }
    }
  }
  return $disks;
}

function get_all_disks_info() {
  $o = array();
  foreach(get_usb_disks() as $d){
    $o[] = get_partition_info($d);
  }
  return $o;
}

function get_partition_info($device){
  $f_size = function($s) { return (is_numeric(trim($s))) ? formatBytes($s*1024) : "-";};
  global $_ENV, $paths;
  $disk = array();
  $attrs = (isset($_ENV['DEVTYPE'])) ? $_ENV : parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $device ) 2>/dev/null"));

  // $GLOBALS['echo']($attrs);
  if ($attrs['DEVTYPE'] == "partition") {
    $disk['serial']       = $attrs['ID_SERIAL'];
    $disk['serial_short'] = $attrs['ID_SERIAL_SHORT'];
    $disk['device']       = $device;
    if (isset($attrs['ID_FS_LABEL'])){
      $disk['label'] = safe_name($attrs['ID_FS_LABEL_ENC']);
    } else if (isset($attrs['ID_VENDOR']) && isset($attrs['ID_MODEL'])){
      $disk['label'] = sprintf("%s %s", safe_name($attrs['ID_VENDOR']), safe_name($attrs['ID_MODEL']));
    } else {
      $disk['label'] = safe_name($attrs['ID_SERIAL']);
    }
    // Append partition number do muti-partitioned disks
    preg_match_all("#(.*?)(\d+$)#", $device, $matches);
    $disk['label']  = (count(preg_grep("%".$matches[1][0]."%i", get_usb_disks())) > 1) ? $disk['label']."-part".$matches[2][0] : $disk['label'];

    $disk['fstype'] = safe_name($attrs['ID_FS_TYPE']);
    $disk['target'] = str_replace("\\040", " ", trim(shell_exec("cat /proc/mounts 2>&1|grep ${device}|awk '{print $2}'")));
    $size           = trim(shell_exec("blockdev --getsize64 ${device} 2>/dev/null"));
    $disk['size']   = is_numeric($size) ? formatBytes($size) : "-";
    $used           = trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep ${device}|awk '{print $1}'"));
    $disk['used']   = is_numeric($used) ? formatBytes($used*1024): "-";
    $disk['avail']  = $f_size(($used) ? (intval($size) - intval($used)*1024) : "");
    $disk['mountpoint'] = preg_replace("%\s+%", "_", sprintf("%s/%s", $paths['usb_mount_point'], $disk['label']));
    $disk['owner'] = (isset($_ENV['DEVTYPE'])) ? "udev" : "user";
    return $disk;
  }
}

function get_fsck_commands($fs) {
  switch ($fs) {
    case 'vfat':
      return array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
      break;
    case 'ntfs':
      return array('ro'=>'/bin/ntfsfix -n %s','rw'=>'/bin/ntfsfix -a %s');
      break;
    case 'exfat':
      return array('ro'=>'/sbin/exfatfsck %s','rw'=>false);
      break;
    case 'hfsplus';
      return array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
      break;
  }
}

