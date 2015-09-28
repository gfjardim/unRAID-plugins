<?
set_error_handler("log_error");
$plugin = "unassigned.devices";
require_once("/usr/local/emhttp/plugins/${plugin}/include/classes.php");
// $VERBOSE=TRUE;

$paths = array("smb_extra"       => "/boot/config/smb-extra.conf",
               "smb_usb_shares"  => "/etc/samba/unassigned-shares",
               "usb_mountpoint"  => "/mnt/disks",
               "log"             => "/var/log/{$plugin}.log",
               "config_file"     => "/boot/config/plugins/{$plugin}/{$plugin}.cfg",
               "state"           => "/var/state/${plugin}/${plugin}.ini",
               "hdd_temp"        => "/var/state/${plugin}/hdd_temp.json",
               "remote_config"   => "/boot/config/plugins/${plugin}/remote_config.cfg",
               "reload"          => "/var/state/${plugin}/reload.state",
               "unmounting"      => "/var/state/${plugin}/unmounting_%s.state",
               "mounting"        => "/var/state/${plugin}/mounting_%s.state",
               );

if (! isset($var)){
  if (! is_file("/usr/local/emhttp/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(ss -napt|grep emhttp|grep -Po ':\K\d+')");
  $var = @parse_ini_file("/usr/local/emhttp/state/var.ini");
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

function _echo($m) { echo "<pre>".print_r($m,TRUE)."</pre>";}; 

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

function debug($m, $type = "NOTICE"){
  if ($type == "DEBUG" && ! $GLOBALS["VERBOSE"]) return NULL;
  $m = "\n".date("D M j G:i:s T Y").": ".print_r($m,true);
  file_put_contents($GLOBALS["paths"]["log"], $m, FILE_APPEND);
  // echo print_r($m,true)."\n";
}

function shell_exec_debug($cmd) {
  debug("cmd: $cmd");
  debug(shell_exec("$cmd 2>&1"));
}

function listDir($root, $filter=null) {
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();
  foreach ($iter as $path => $fileinfo) {
    if ($filter && is_bool(strpos($path, $filter))) continue;
    if (! $fileinfo->isDir()) $paths[] = $path;
  }
  return $paths;
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

function is_disk_running($dev) {
  $state = trim(shell_exec("hdparm -C $dev 2>/dev/null| grep -c standby"));
  return ($state == 0) ? TRUE : FALSE;
}

function lsof($dir) {
  return intval(trim(shell_exec("lsof '{$dir}' 2>/dev/null|grep -c -v COMMAND")));
}

function get_temp($dev) {
  $tc = $GLOBALS["paths"]["hdd_temp"];
  $temps = is_file($tc) ? json_decode(file_get_contents($tc),TRUE) : array();
  if (is_disk_running($dev)) {
    if (isset($temps[$dev]) && (time() - $temps[$dev]['timestamp']) < 180 ) {
      return $temps[$dev]['temp'];
    } else {
      $temp = trim(shell_exec("smartctl -A -d sat,12 $dev 2>/dev/null| grep -m 1 -i Temperature_Celsius | awk '{print $10}'"));
      $temp = (is_numeric($temp)) ? $temp : "*";
      $temps[$dev] = array('timestamp' => time(),
                           'temp'      => $temp);
      file_put_contents($tc, json_encode($temps));
      return $temp;
    }
  }
  else {
    return "*";
  }
}

function verify_precleared($dev) {
  $cleared        = TRUE;
  $disk_blocks    = intval(trim(exec("blockdev --getsz $dev  | awk '{ print $1 }'")));
  $max_mbr_blocks = hexdec("0xFFFFFFFF");
  $over_mbr_size  = ( $disk_blocks >= $max_mbr_blocks ) ? TRUE : FALSE;
  $partition_size = $over_mbr_size ? $max_mbr_blocks : $disk_blocks;
  $pattern        = $over_mbr_size ? array("00000", "00000", "00002", "00000", "00000", "00255", "00255", "00255") : 
                                     array("00000", "00000", "00000", "00000", "00000", "00000", "00000", "00000");


  $b["mbr1"] = trim(shell_exec("dd bs=446 count=1 if=$dev 2>/dev/null        |sum|awk '{print $1}'"));
  $b["mbr2"] = trim(shell_exec("dd bs=1 count=48 skip=462 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
  $b["mbr3"] = trim(shell_exec("dd bs=1 count=1  skip=450 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
  $b["mbr4"] = trim(shell_exec("dd bs=1 count=1  skip=511 if=$dev 2>/dev/null|sum|awk '{print $1}'"));
  $b["mbr5"] = trim(shell_exec("dd bs=1 count=1  skip=510 if=$dev 2>/dev/null|sum|awk '{print $1}'"));

  foreach (range(0,15) as $n) {
    $b["byte{$n}"] = trim(shell_exec("dd bs=1 count=1 skip=".(446+$n)." if=$dev 2>/dev/null|sum|awk '{print $1}'"));
    $b["byte{$n}h"] = sprintf("%02x",$b["byte{$n}"]);
  }

  debug("Verifying '$dev' for preclear signature.", "DEBUG");

  if ( $b["mbr1"] != "00000" || $b["mbr2"] != "00000" || $b["mbr3"] != "00000" || $b["mbr4"] != "00170" || $b["mbr5"] != "00085" ) {
    debug("Failed test 1: MBR signature not valid.", "DEBUG"); 
    $cleared = FALSE;
  }
  # verify signature
  foreach ($pattern as $key => $value) {
    if ($b["byte{$key}"] != $value) {
      debug("Failed test 2: signature pattern $key ['$value'] != '".$b["byte{$key}"]."'", "DEBUG");
      $cleared = FALSE;
    }
  }
  $sc = hexdec("0x{$b[byte11h]}{$b[byte10h]}{$b[byte9h]}{$b[byte8h]}");
  $sl = hexdec("0x{$b[byte15h]}{$b[byte14h]}{$b[byte13h]}{$b[byte12h]}");
  switch ($sc) {
    case 63:
    case 64:
      $partition_size -= $sc;
      break;
    case 1:
      if (! $over_mbr_size) {
        debug("Failed test 3: start sector ($sc) is invalid.", "DEBUG");
        $cleared = FALSE;
      }
      break;
    default:
      debug("Failed test 4: start sector ($sc) is invalid.", "DEBUG");
      $cleared = FALSE;
      break;
  }
  if ( $partition_size != $sl ) {
    debug("Failed test 5: disk size don't match. [$partition_size] [$sl] ", "DEBUG");
    $cleared = FALSE;
  }
  if ($cleared) debug("Disk '{$dev}' is precleared.");
  return $cleared;
}

function get_format_cmd($dev, $fs) {
  switch ($fs) {
    case 'xfs':
      return "/sbin/mkfs.xfs {$dev}";
      break;
    case 'ntfs':
      return "/sbin/mkfs.ntfs -Q {$dev}";
      break;
    case 'btrfs':
      return "/sbin/mkfs.btrfs {$dev}";
      break;
    case 'ext4':
      return "/sbin/mkfs.ext4 {$dev}";
      break;
    case 'exfat':
      return "/sbin/mkfs.exfat {$dev}";
      break;
    case 'fat32':
      return "/sbin/mkfs.fat -s 8 -F 32 {$dev}";
      break;
    default:
      return false;
      break;
  }
}

function format_partition($partition, $fs) {
  $local = new LOCAL();
  $part = $local->get_partition_info($partition, array());
  if ( $part['fstype'] ) {
    debug("Aborting format: partition '{$partition}' is already formatted with '{$part[fstype]}' filesystem.");
    return NULL;
  }
  debug("Formatting partition '{$partition}' with '$fs' filesystem.");
  shell_exec_debug(get_format_cmd($partition, $fs));
  $disk = preg_replace("@\d+@", "", $partition);
  shell_exec_debug("/usr/sbin/hdparm -z {$disk}");
  sleep(3);
  @touch($GLOBALS['paths']['reload']);
}

function format_disk($dev, $fs) {
  if (get_config("LOCAL", "Config", "destructive_mode") != "enabled") {
    debug("Aborting removal: destructive_mode disabled");
    return FALSE;
  }
  # making sure it doesn't have partitions
  $local = new LOCAL();
  foreach ($local->get_all_disks_info() as $d) {
    if ($d['device'] == $dev && count($d['partitions']) && ! $d['precleared']) {
      debug("Aborting format: disk '{$dev}' has '".count($d['partitions'])."' partition(s).");
      return FALSE;
    }
  }
  $max_mbr_blocks = hexdec("0xFFFFFFFF");
  $disk_blocks    = intval(trim(exec("blockdev --getsz $dev  | awk '{ print $1 }'")));
  $disk_schema    = ( $disk_blocks >= $max_mbr_blocks ) ? "gpt" : "msdos";
  debug("Clearing partition table of disk '$dev'.");
  shell_exec_debug("/usr/bin/dd if=/dev/zero of={$dev} bs=2M count=1");
  debug("Reloading disk '{$dev}' partition table.");
  shell_exec_debug("/usr/sbin/hdparm -z {$dev}");
  debug("Creating a '{$disk_schema}' partition table on disk '{$dev}'.");
  shell_exec_debug("/usr/sbin/parted {$dev} --script -- mklabel {$disk_schema}");
  debug("Creating a primary partition on disk '{$dev}'.");
  shell_exec_debug("/usr/sbin/parted -a optimal {$dev} --script -- mkpart primary 0% 100%");
  debug("Formatting disk '{$dev}' with '$fs' filesystem.");
  shell_exec_debug(get_format_cmd("{$dev}1", $fs));
  debug("Reloading disk '{$dev}' partition table.");
  shell_exec_debug("/usr/sbin/hdparm -z {$dev}");
  sleep(3);
  @touch($GLOBALS['paths']['reload']);
}

function remove_partition($dev, $part) {
  if (get_config("LOCAL", "Config", "destructive_mode") != "enabled") {
    debug("Aborting removal: destructive_mode disabled");
    return FALSE;
  }
  $local = new LOCAL();
  foreach ($local->get_all_disks_info() as $d) {
    if ($d['device'] == $dev) {
      foreach ($d['partitions'] as $p) {
        if ($p['part'] == $part && $p['target']) {
          debug("Aborting removal: partition '{$part}' is mounted.");
          return FALSE;
        } 
      }
    }
  }
  debug("Removing partition '{$part}' from disk '{$dev}'.");
  shell_exec_debug("/usr/sbin/parted {$dev} --script -- rm {$part}");
}

function sendLog() {
  global $var, $paths;
  $url = "http://gfjardim.maxfiles.org";
  $max_size = 2097152; # in bytes
  $notify = "/usr/local/emhttp/webGui/scripts/notify";
  $data = array('data'     => shell_exec("cat '{$paths[log]}' 2>&1 | tail -c $max_size -"),
                'language' => 'text',
                'title'    => '[Unassigned Devices log]',
                'private'  => true,
                'expire'   => '2592000');
  $tmpfile = "/tmp/tmp-".mt_rand().".json";
  file_put_contents($tmpfile, json_encode($data));
  $out = shell_exec("curl -s -k -L -X POST -H 'Content-Type: application/json' --data-binary  @$tmpfile ${url}/api/json/create");
  unlink($tmpfile);
  $server = strtoupper($var['NAME']);
  $out = json_decode($out, TRUE);
  if (isset($out['result']['error'])){
    echo shell_exec("$notify -e 'Unassigned Devices log upload failed' -s 'Alert [$server] - $title upload failed.' -d 'Upload of Unassigned Devices Log has failed: ".$out['result']['error']."' -i 'alert 1'");
    echo '{"result":"failed"}';
  } else {
    $resp = "${url}/".$out['result']['id']."/".$out['result']['hash'];
    exec("$notify -e 'Unassigned Devices log uploaded - [".$out['result']['id']."]' -s 'Notice [$server] - $title uploaded.' -d 'A new copy of Unassigned Devices Log has been uploaded: $resp' -i 'normal 1'");
    echo '{"result":"'.$resp.'"}';
  }
}

#########################################################
############        CONFIG FUNCTIONS        #############
#########################################################

function get_config($protocol, $sec, $var) {
  $class = get_proc_class($protocol);
  return $class->get_config($sec, $var);
}

function set_config($protocol, $sec, $var, $val) {
  $class = get_proc_class($protocol);
  return $class->set_config($sec, $var, $val);
}

function is_automount($protocol, $sec, $usb=false) {
  $class = get_proc_class($protocol);
  return $class->is_automount($sec, $usb);
}

function toggle_automount($protocol, $sec, $status) {
  $class = get_proc_class($protocol);
  return $class->toggle_automount($sec, $status);
}

function execute_script($info, $action) { 
  $out = ''; 
  $error = '';
  putenv("ACTION=${action}");
  foreach ($info as $key => $value) putenv(strtoupper($key)."=${value}");
  $cmd = $info['command'];
  $bg = ($info['command_bg'] == "true" && $action == "ADD") ? "&" : "";
  if ($common_cmd = get_config("LOCAL", "Config", "common_cmd")) {
    debug("Running common script: '{$common_cmd}'");
    exec($common_cmd);
  }
  if (! $cmd) {debug("Command not available, skipping."); return FALSE;}
  debug("Running command '${cmd}' with action '${action}'.");
  @chmod($cmd, 0755);
  $cmd = isset($info['serial']) ? "$cmd > /tmp/${info[serial]}.log 2>&1 $bg" : "$cmd > /tmp/".preg_replace('~[^\w]~i', '', $info['device']).".log 2>&1 $bg";
  exec($cmd);
}

function remove_config($protocol, $sec) {
  $class = get_proc_class($protocol);
  return $class->remove_config($sec);
}


#########################################################
############        MOUNT FUNCTIONS        ##############
#########################################################

function is_mounted($dev) {
  return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
  // return (shell_exec("mount 2>&1|grep -c '${dev} '") == 0) ? FALSE : TRUE;
}

function get_mount_params($fs, $dev) {
  $discard = trim(shell_exec("cat /sys/block/".preg_replace("#\d+#i", "", basename($dev))."/queue/discard_max_bytes 2>/dev/null")) ? ",discard" : "";
  switch ($fs) {
    case 'hfsplus':
      return "force,rw,users,async,umask=000";
      break;
    case 'xfs':
      return "rw,noatime,nodiratime{$discard}";
      break;
    case 'exfat':
    case 'vfat':
    case 'ntfs':
      return "auto,async,nodev,nosuid,umask=000";
      break;
    case 'ext4':
      return "auto,async,nodev,nosuid{$discard}";
      break;
    case 'cifs':
      return "rw,nounix,iocharset=utf8,_netdev,username=%s,password=%s,uid=99,gid=100";
      break;
    case 'nfs':
      return "defaults";
      break;
    default:
      return "auto,async,noatime,nodiratime";
      break;
  }
}

function do_mount($info) {
  switch ($info['fstype']) {
    case 'cifs':
      $class = get_proc_class("SMB");
      return $class->mount($info);
      break;
    
    default:
      $class = get_proc_class("LOCAL");
      return $class->mount($info);
      break;
  }
}

function do_unmount($dev, $dir, $force = FALSE) {
  if (is_mounted($dev) != 0){
    debug("Unmounting ${dev}...");
    $o = shell_exec("umount".($force ? " -f -l" : "")." '${dev}' 2>&1");
    for ($i=0; $i < 10; $i++) {
      if (! is_mounted($dev)){
        if (is_dir($dir)) @rmdir($dir);
        debug("Successfully unmounted '$dev'"); return TRUE;
      } else { sleep(0.5);}
    }
    debug("Unmount of ${dev} failed. Error message: \n$o");
    sleep(1);
    if (! lsof($dir) && ! $force) {
      debug("Since there aren't open files, try to force unmount.");
      return do_unmount($dev, $dir, true);
    }

    return FALSE;
  }
}

#########################################################
############        SHARE FUNCTIONS         #############
#########################################################

function is_shared($name) {
  return ( shell_exec("smbclient -g -L localhost -U% 2>&1|awk -F'|' '/Disk/{print $2}'|grep -c '${name}'") == 0 ) ? FALSE : TRUE;
}

function config_shared($sn, $part) {
  $share = get_config("LOCAL", $sn, "share.{$part}");
  return ($share == "yes") ? TRUE : FALSE; 
}

function toggle_share($serial, $part, $status) {
  $new = ($status == "true") ? "yes" : "no";
  set_config("LOCAL", $serial, "share.{$part}", $new);
  return ($new == 'yes') ? TRUE:FALSE;
}
function add_smb_share($dir, $share_name) {
  global $paths;
  $config = is_file($paths['config_file']) ? @parse_ini_file($paths['config_file'], true) : array();
  $config = $config["Config"];
  $share_name = basename($dir);
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  if (exist_in_file($paths['smb_extra'], $share_conf) && is_file($share_conf)) {
    return true;
  }
  debug("Defining share '$share_name' on file '$share_conf'.");

  if ($config["smb_security"] == "yes") {
    $read_users = $write_users = array();
    exec("cat /boot/config/passwd 2>/dev/null|grep :100:|cut -d: -f1|grep -v nobody", $valid_users);
    $invalid_users = array_filter($valid_users, function($v) use($config, &$read_users, &$write_users) { 
      if ($config["smb_{$v}"] == "read-only") {$read_users[] = $v;}
      elseif ($config["smb_{$v}"] == "read-write") {$write_users[] = $v;}
      else {return $v;}
    });
    $valid_users = array_diff($valid_users, $invalid_users);
    if (count($valid_users)) {
      $valid_users = "\nvalid users = ".implode(', ', $valid_users);
      $write_users = count($write_users) ? "\nwrite list = ".implode(', ', $write_users) : "";
      $read_users = count($read_users) ? "\nread list = ".implode(', ', $read_users) : "";
      $share_cont =  "[{$share_name}]\npath = {$dir}{$valid_users}{$write_users}{$read_users}";
    } else {
      $share_cont =  "[{$share_name}]\npath = {$dir}\ninvalid users = @users";
    }
  } else {
    $share_cont = "[{$share_name}]\npath = {$dir}\nread only = No\nguest ok = Yes ";
  }

  if(!is_dir($paths['smb_usb_shares'])) @mkdir($paths['smb_usb_shares'],0755,TRUE);

  file_put_contents($share_conf, $share_cont);
  if (! exist_in_file($paths['smb_extra'], $share_conf)) {
    debug("Adding share '$share_name' to ".$paths['smb_extra'].".");
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
  $share_name = basename($dir);
  $share_conf = preg_replace("#\s+#", "_", realpath($paths['smb_usb_shares'])."/".$share_name.".conf");
  if (! exist_in_file($paths['smb_extra'], $share_conf) || ! is_file($share_conf)) {
    return true;
  }
  debug("Removing SMB share '$share_name' from '$share_conf'.");
  if (is_file($share_conf)) {
    @unlink($share_conf);
    debug("Removing file '$share_conf'.");
  }
  debug("Removing share definitions from ".$paths['smb_extra'])."'.";
  $c = (is_file($paths['smb_extra'])) ? @file($paths['smb_extra'],FILE_IGNORE_NEW_LINES) : array();
  # Do Cleanup
  $smb_extra_includes = array_unique(preg_grep("/include/i", $c));
  foreach($smb_extra_includes as $key => $inc) if(! is_file(parse_ini_string($inc)['include'])) unset($smb_extra_includes[$key]); 
  $c = array_merge(preg_grep("/include/i", $c, PREG_GREP_INVERT), $smb_extra_includes);
  $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
  file_put_contents($paths['smb_extra'], $c);
  debug("Reloading Samba configuration. ");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) close-share '${share_name}' 2>&1");
  shell_exec("/usr/bin/smbcontrol $(cat /var/run/smbd.pid 2>/dev/null) reload-config 2>&1");
  sleep(1);
  if(! is_shared($share_name)) {
    debug("Successfully removed share '${share_name}'."); return TRUE;
  } else {
    debug("Removal of share '${share_name}' failed."); return FALSE;
  }
}

function add_nfs_share($dir) {
  $reload = FALSE;
  foreach (array("/etc/exports","/etc/exports-") as $file) {
    if (! exist_in_file($file, "\"{$dir}\"")) {
      $c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
      debug("Adding NFS share '$dir' to '$file'.");
      $fsid = 100 + count(preg_grep("@^\"@", $c));
      $c[] = "\"{$dir}\" -async,no_subtree_check,fsid={$fsid} *(sec=sys,rw,insecure,anongid=100,anonuid=99,all_squash)";
      $c[] = "";
      // $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
      file_put_contents($file, implode(PHP_EOL, $c));
      $reload = TRUE;
    }
  }
  if ($reload) shell_exec("/usr/sbin/exportfs -ra");
}

function rm_nfs_share($dir) {
  $reload = FALSE;
  foreach (array("/etc/exports","/etc/exports-") as $file) {
    if ( exist_in_file($file, "\"{$dir}\"") && strlen($dir)) {
      $c = (is_file($file)) ? @file($file,FILE_IGNORE_NEW_LINES) : array();
      debug("Removing NFS share '$dir' from '$file'.");
      $c = preg_grep("@\"{$dir}\"@i", $c, PREG_GREP_INVERT);
      // $c = preg_replace('/\n\s*\n\s*\n/s', PHP_EOL.PHP_EOL, implode(PHP_EOL, $c));
      file_put_contents($file, implode(PHP_EOL, $c));
      $reload = TRUE;
    }
  }
  if ($reload) shell_exec("/usr/sbin/exportfs -ra");
  return TRUE;
}

function reload_shares() {
  $local = get_proc_class('LOCAL');
  foreach ($local->undisks as $id => $disk) {
    foreach ($disk['partitions'] as $p) {
      if(is_mounted( realpath($p) )) {
        $disk = array_merge($disk, $local->get_disk_info($id));
        $info = $local->get_partition_info($p, $disk);
        if (is_shared(basename($info['mounted']))) {
          if (config_shared( $disk['serial'],  $info['part'])) {
            debug("");debug("Reloading shared dir '{$info[mounted]}' ");
            debug("Removing old config ...");
            rm_smb_share($info['mounted'], $info['label']);
            rm_nfs_share($info['mounted']);
            debug("Adding new config ...");
            add_smb_share($info['mountpoint'], $info['label']);
            if (get_config("LOCAL", "Config", "nfs_export") == "yes") {
              if (is_bool(strpos("ntfs vfat exfat", $info['fstype']))) {
                add_nfs_share($info['mountpoint']);
              }
            }
          }
        }
      }
    }
  }
  debug("Done.");
}

#########################################################
############         DISK FUNCTIONS         #############
#########################################################

function get_fsck_commands($fs, $dev, $type = "ro") {
  switch ($fs) {
    case 'vfat':
      $cmd = array('ro'=>'/sbin/fsck -n %s','rw'=>'/sbin/fsck -a %s');
      break;
    case 'ntfs':
      $cmd = array('ro'=>'/bin/ntfsfix -n %s','rw'=>'/bin/ntfsfix -b -d %s');
      break;
    case 'hfsplus';
      $cmd = array('ro'=>'/usr/sbin/fsck.hfsplus -l %s','rw'=>'/usr/sbin/fsck.hfsplus -y %s');
      break;
    case 'xfs':
      $cmd = array('ro'=>'/sbin/xfs_repair -n %s','rw'=>'/sbin/xfs_repair %s');
      break;
    case 'exfat':
      $cmd = array('ro'=>'/sbin/fsck.exfat %s','rw'=>'/sbin/fsck.exfat %s');
      break;
    case 'btrfs':
      $cmd = array('ro'=>'/sbin/btrfs scrub start -B -R -d -r %s','rw'=>'/sbin/btrfs scrub start -B -R -d %s');
      break;
    case 'ext4':
      $cmd = array('ro'=>'/sbin/fsck.ext4 -vn %s','rw'=>'/sbin/fsck.ext4 -v -f -p %s');
      break;
    case 'reiserfs':
      $cmd = array('ro'=>'/sbin/reiserfsck --check %s','rw'=>'/sbin/reiserfsck --fix-fixable %s');
      break;
    default:
      $cmd = array('ro'=>false,'rw'=>false);
      break;
  }
  return $cmd[$type] ? sprintf($cmd[$type], $dev) : "";
}

function setSleepTime($device) {
  $device = preg_replace("/\d+$/", "", $device);
  shell_exec("hdparm -S180 $device 2>&1");
}
