<?
function get_proc_class($protocol) {
  switch ($protocol) {
    case 'LOCAL' : return new LOCAL(); break;
    case 'SMB': return new SMB(); break;
    case 'NFS': return new NFS(); break;
  }
}

function get_remote_shares() {
  $samba = get_proc_class("SMB");
  $nfs   = get_proc_class("NFS");
  return array_merge($samba->get_mounts(), $nfs->get_mounts());
}

class CONFIG {
  protected $config_file;

  public function get_config_file() {
    return is_file($this->config_file) ? @parse_ini_file($this->config_file, true) : array();
  }

  private function save_config_file($config) {
    save_ini_file($this->config_file, $config);
  }

  public function get_config($source, $var) {
    $config = $this->get_config_file();
    return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
  }

  public function set_config($source, $var, $val) {
    if (! $source || ! $var ) return FALSE;
    $config = $this->get_config_file();
    $config[$source][$var] = $val;
    $this->save_config_file($config);
    return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
  }

  public function is_automount($source, $usb=false) {
    $auto = $this->get_config($source, "automount");
    return ($auto) ? ( ($auto == "yes" ) ? TRUE : FALSE ) : ( $usb ? TRUE : FALSE );
  }

  public function toggle_automount($sn, $status) {
    $config = $this->get_config_file();
    $config[$sn]["automount"] = ($status == "true") ? "yes" : "no";
    $this->save_config_file($config);
    return ($config[$sn]["automount"] == "yes") ? TRUE : FALSE;
  }

  public function remove_config($sn) {
    $config = $this->get_config_file();
    unset($config[$sn]);
    $this->save_config_file($config);
    return (isset($config[$sn])) ? TRUE : FALSE;
  }
}

/*
    ██████╗ ██╗███████╗██╗  ██╗███████╗
    ██╔══██╗██║██╔════╝██║ ██╔╝██╔════╝
    ██║  ██║██║███████╗█████╔╝ ███████╗
    ██║  ██║██║╚════██║██╔═██╗ ╚════██║
    ██████╔╝██║███████║██║  ██╗███████║
    ╚═════╝ ╚═╝╚══════╝╚═╝  ╚═╝╚══════╝
*/

class LOCAL extends CONFIG {

  public $blkdisks = array();

  public $blkparts = array();

  public $undisks = array();

  protected $globals;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["config_file"];
    $this->globals =& $GLOBALS["GLOBALS"];
    exec("/usr/bin/df --output=source,used|column -t|grep -v 'Filesystem'", $used_info);
    foreach ($used_info as $l) {
      $u = preg_split("#\s+#", $l);
      $used[basename($u[0])] = intval($u[1])*1024;
    }
    exec("/bin/lsblk -nbP -o name,type,label,size,mountpoint,fstype", $blocks);
    foreach ($blocks as $b) {
      $block = parse_ini_string(preg_replace("$\s+$", PHP_EOL, $b));
      if ($block['TYPE'] == "disk") {
        $this->blkdisks[$block['NAME']] = $block;
      } elseif ($block['TYPE'] == "part") {
        $block["USED"] = isset($used[$block['NAME']]) ? $used[$block['NAME']] : 0;
        $this->blkparts[$block['NAME']] = $block;
      }
    }
    $this->undisks = $this->get_unasigned_disks();
  }

  public function mount($info) {
    $dev = $info['device'];
    $dir = $info['mountpoint'];
    $fs  = $info['fstype'];
    if (! is_mounted($dev)) {
      if (is_mounted($dir)) {
        foreach (range(1, 20) as $n) {
          preg_match("#(.*-)(\d)$#", $dir, $matches);
          $n = isset($matches[2]) ? $matches[1].($matches[2]+1) : "-{$n}";
          $dir = "{$dir}{$n}";
          if (! is_mounted($dir)) {
            break;
          }
        }
      }
      if ($fs){
        @mkdir($dir,0777,TRUE);
        $cmd = "mount -t $fs -o ".get_mount_params($fs, $dev)." '${dev}' '${dir}'";
        debug("Mounting drive with command: $cmd");
        $o = shell_exec($cmd." 2>&1");
        foreach (range(0,5) as $t) {
          if (is_mounted($dev)) {
            @chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
            debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
          } else { sleep(0.5);}
        }
        debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
      }else {
        debug("No filesystem detected, aborting.");
      }
    } else {
      debug("Drive '$dev' already mounted");
    }
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

  public function get_all_disks_info() {
    $disks = array();
    foreach ($this->undisks as $id => $disk) {
      $disk = array_merge($disk, $this->get_disk_info($id));
      foreach ($disk['partitions'] as $k => $p) {
        if ($p) $disk['partitions'][$k] = $this->get_partition_info($p, $disk);
      }
      $disk['precleared'] = (count($disk['partitions']) == 1 && ! $disk['partitions'][0]['fstype']) ? verify_precleared($disk['device']) : false;
      $disks[$id] = $disk;
    }
    usort($disks, create_function('$a, $b','$key="device";if ($a[$key] == $b[$key]) return 0; return ($a[$key] < $b[$key]) ? -1 : 1;'));
    return $disks;
  }

  public function get_disk_info($id){
    $device = realpath($id);
    $name   = basename($device);
    $cache_file = $this->globals['paths']['state'];
    $cached = is_file($cache_file) ? @parse_ini_file($cache_file, true) : array();
    $disk =& $cached[$id];
    if (empty($disk)) {
      $attrs = parse_ini_string(shell_exec("udevadm info --query=property --path $(udevadm info -q path -n $id 2>/dev/null) 2>/dev/null"));
      $disk['serial_short'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
      $disk['serial']       = "{$attrs['ID_MODEL']}_{$disk['serial_short']}";
      $disk['size']         = $this->blkdisks[$name]['SIZE'];
      $disk['model']        = $attrs['ID_MODEL'];
      $disk['bus']          = (!is_bool(strpos($attrs['DEVPATH'], "usb"))) ? "usb" : "ata";
      save_ini_file($cache_file, $cached);
    }
    $disk['temp'] = get_temp($id);
    $disk['name']      = basename($device);
    $disk['device']    = $device;
    $disk['automount'] = $this->is_automount($disk['serial'], ($disk['bus'] == "usb" ? true : false));
    return $disk;
  }

  public function get_partition_info($id, $disk){
    $device  = realpath($id);
    $blkinfo = $this->blkparts[basename($device)];
    $part['name']    = basename($device);
    $part['device']  = $device;
    $part['part']    = str_replace($disk['device'], "", $device);
    $part['disk']    = $disk['device'];
    $part['label']   = $blkinfo['LABEL'] ? $blkinfo['LABEL'] : "{$disk['serial']}-part{$part['part']}";
    $part['fstype']  = $blkinfo['FSTYPE'];
    $mp_config       = $this->get_config($disk['serial'], "mountpoint.{$part['part']}");
    $part['mounted'] = trim($blkinfo['MOUNTPOINT']);
    if ($part['mounted']) {
      $part['mountpoint'] = $part['mounted'];
    } elseif ($mp_config) {
      $part['mountpoint'] = $mp_config;
    } else {
      $part['mountpoint'] = "{$this->globals['paths']['usb_mountpoint']}/{$part['label']}";
    }
    $part['size']    = intval($blkinfo['SIZE']);
    $part['used']    = $blkinfo['USED'];
    $part['avail']   = $part['size'] - $part['used'];
    $part['shared']  = $part['mounted'] ? is_shared(basename($part['mountpoint'])) : config_shared($disk['serial'], $part['part']);
    // $part['shared']  = config_shared($disk['serial'], $part['part']);
    $part['command'] = $this->get_config($disk['serial'], "command.{$part['part']}");
    $part['owner']   = isset($_ENV['DEVTYPE']) ? "udev" : "user";
    return $part;
  }
}

/*
    ███████╗███╗   ███╗██████╗ 
    ██╔════╝████╗ ████║██╔══██╗
    ███████╗██╔████╔██║██████╔╝
    ╚════██║██║╚██╔╝██║██╔══██╗
    ███████║██║ ╚═╝ ██║██████╔╝
    ╚══════╝╚═╝     ╚═╝╚═════╝ 
*/


class SMB extends CONFIG {

  protected $config_file, $usb_mountpoint;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["remote_config"];
    $this->usb_mountpoint =& $GLOBALS["paths"]["usb_mountpoint"];
  }

  public function get_mounts() {
    $o = array();
    $samba_mounts = array_filter($this->get_config_file(), function ($v){if(isset($v['protocol']) && $v['protocol'] == "SMB"){return $v;}});
    foreach ($samba_mounts as $serial => $mount) {
      $mount['serial']   = $serial;
      $mount['device']   = "//{$mount[ip]}/{$mount[share]}";
      $mounts = shell_exec("cat /proc/mounts 2>&1");
      $mount['mounted']  = trim(shell_exec("echo '$mounts' 2>&1|grep '".str_replace(" ", '\\\040', $mount['device'])." '|awk '{print $2}'"));
      $mount['fstype']   = "cifs";
      $mount['protocol'] = "SMB";
      $mount['size']     = intval(trim(shell_exec("df --output=size,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount['device']}'|awk '{print $1}'")))*1024;
      $mount['used']     = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount['device']}'|awk '{print $1}'")))*1024;
      $mount['avail']    = $mount['size'] - $mount['used'];
      if ($mount["mounted"]) {
        $mount["mountpoint"] = $mount['mounted'];
      } else {
        $mount["mountpoint"] = $mount['target'] ? $mount['target'] : preg_replace("%\s+%", "_", "{$this->usb_mountpoint}/smb_{$mount['ip']}_{$mount['share']}");
      }
      $mount['icon'] = "/plugins/dynamix/icons/smbsettings.png";
      $mount['automount'] = $this->is_automount($serial);
      $o[] = $mount;
    }
    return $o;
  }

  public function mount($info) {
    $dev = $info['device'];
    $dir = $info['mountpoint'];
    $fs  = $info['fstype'];
    if (! is_mounted($dev)) {
      if (is_mounted($dir)) {
        foreach (range(1, 20) as $n) {
          $t = "{$dir}-{$n}";
          if (! is_mounted($t)) {
            $dir = $t;
            break;
          }
        }
      }
      @mkdir($dir,0777,TRUE);
      $params = sprintf(get_mount_params($fs, $dev), ($info['user'] ? $info['user'] : "guest" ), $info['pass']);
      $cmd = "mount -t $fs -o ".$params." '${dev}' '${dir}'";
      $params = sprintf(get_mount_params($fs, $dev), ($info['user'] ? $info['user'] : "guest" ), '*******');
      debug("Mounting share with command: mount -t $fs -o ".$params." '${dev}' '${dir}'");
      $o = shell_exec($cmd." 2>&1");
      foreach (range(0,5) as $t) {
        if (is_mounted($dev)) {
          @chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
          debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
        } else { sleep(0.5);}
      }
      debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
    } else {
      debug("Share '$dev' already mounted.");
    }
  }
}

/*
███╗   ██╗███████╗███████╗
████╗  ██║██╔════╝██╔════╝
██╔██╗ ██║█████╗  ███████╗
██║╚██╗██║██╔══╝  ╚════██║
██║ ╚████║██║     ███████║
╚═╝  ╚═══╝╚═╝     ╚══════╝
*/

class NFS extends CONFIG {

  protected $config_file, $usb_mountpoint;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["remote_config"];
    $this->usb_mountpoint =& $GLOBALS["paths"]["usb_mountpoint"];
  }

  public function get_mounts() {
    $o = array();
    $nfs_mounts = array_filter($this->get_config_file(), function ($v){if(isset($v['protocol']) && $v['protocol'] == "NFS"){return $v;}});
    foreach ($nfs_mounts as $serial => $mount) {
      $mount['serial']   = $serial;
      $mount['device']   = "{$mount['ip']}:{$mount['share']}";
      $mount['mounted']  = trim(shell_exec("cat /proc/mounts 2>&1|grep '".str_replace(" ", '\\\040', $mount['device'])." '|awk '{print $2}'"));
      $mount['fstype']   = "nfs";
      $mount['protocol'] = "NFS";
      $mount['size']     = intval(trim(shell_exec("df --output=size,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount['device']}'|awk '{print $1}'")))*1024;
      $mount['used']     = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount['device']}'|awk '{print $1}'")))*1024;
      $mount['avail']    = $mount['size'] - $mount['used'];
      if ($mount["mount"]) {
        $mount['mountpoint'] = $mount['mounted'];
      } else {
        $share = preg_replace("%[\s+\\/]%", "_", basename($mount['share']));
        $mount["mountpoint"] = $mount['target'] ? $mount['target'] : preg_replace("%[\s+]%", "_", "{$this->usb_mountpoint}/nfs_{$mount[ip]}_{$share}");
        $mount["mountpoint"] = str_replace("__", "_", $mount["mountpoint"]);
      }
      $mount['icon'] = "/plugins/dynamix/icons/nfs.png";
      $mount['automount'] = $this->is_automount($serial);
      $o[] = $mount;
    }
    return $o;
  }

  public function mount($info) {
    $dev = $info['device'];
    $dir = $info['mountpoint'];
    $fs  = $info['fstype'];
    if (! is_mounted($dev)) {
      if (is_mounted($dir)) {
        foreach (range(1, 20) as $n) {
          $t = "{$dir}-{$n}";
          if (! is_mounted($t)) {
            $dir = $t;
            break;
          }
        }
      }
      @mkdir($dir,0777,TRUE);
      $params = get_mount_params($fs, $dev);
      $cmd = "mount -t $fs -o ".$params." '${dev}' '${dir}'";
      debug("Mounting share with command: mount -t $fs -o ".$params." '${dev}' '${dir}'");
      $o = shell_exec($cmd." 2>&1");
      foreach (range(0,5) as $t) {
        if (is_mounted($dev)) {
          @chmod($dir, 0777);@chown($dir, 99);@chgrp($dir, 100);
          debug("Successfully mounted '${dev}' on '${dir}'"); return TRUE;
        } else { sleep(0.5);}
      }
      debug("Mount of ${dev} failed. Error message: $o"); return FALSE;
    } else {
      debug("Share '$dev' already mounted.");
    }
  }
}

?>