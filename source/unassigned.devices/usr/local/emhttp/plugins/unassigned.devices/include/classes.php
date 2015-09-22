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

class LOCAL {

  protected $config_file;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["config_file"];
  }

  public function get_config_file() {
    if (! is_file($this->config_file)) @mkdir(dirname($this->config_file),0777,TRUE);
    return is_file($this->config_file) ? @parse_ini_file($this->config_file, true) : array();
  }

  private function save_config_file($config) {
    save_ini_file($this->config_file, $config);
  }

  public function get_config($sn, $var) {
    $config = $this->get_config_file();
    return (isset($config[$sn][$var])) ? html_entity_decode($config[$sn][$var]) : FALSE;
  }

  public function set_config($sn, $var, $val) {
    if (! $sn || ! $var ) return FALSE;
    $config = $this->get_config_file();
    $config[$sn][$var] = htmlentities($val, ENT_COMPAT);
    $this->save_config_file($config);
    return (isset($config[$sn][$var])) ? $config[$sn][$var] : FALSE;
  }

  public function is_automount($sn, $usb=false) {
    $auto = $this->get_config($sn, "automount");
    return ($auto == "yes" || (! $auto && $usb) ) ? TRUE : FALSE; 
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
}


class SMB {

  protected $config_file, $usb_mountpoint;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["remote_config"];
    $this->usb_mountpoint =& $GLOBALS["paths"]["usb_mountpoint"];
  }

  public function get_config_file() {
    if (! is_file($this->config_file)) @mkdir(dirname($this->config_file),0777,TRUE);
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
    if (! $source || ! $var || ! $val) return FALSE;
    $config = $this->get_config_file();
    $config[$source][$var] = $val;
    $this->save_config_file($config);
    return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
  }

  public function is_automount($source, $usb=false) {
    $auto = $this->get_config($source, "automount");
    return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
  }

  public function get_mounts() {
    $o = array();
    $samba_mounts = array_filter($this->get_config_file(), function ($v){if($v['protocol'] == "SMB"){return $v;}});
    foreach ($samba_mounts as $serial => $mount) {
      $mount['serial']   = $serial;
      $mount['device']   = "//{$mount[ip]}/{$mount[share]}";
      $mounts = shell_exec("cat /proc/mounts 2>&1");
      $mount['target']   = trim(shell_exec("echo '$mounts' 2>&1|grep '".str_replace(" ", '\\\040', $mount['device'])." '|awk '{print $2}'"));
      $mount['fstype']   = "cifs";
      $mount['protocol'] = "SMB";
      $mount['size']     = intval(trim(shell_exec("df --output=size,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount[device]}'|awk '{print $1}'")))*1024;
      $mount['used']     = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount[device]}'|awk '{print $1}'")))*1024;
      $mount['avail']    = $mount['size'] - $mount['used'];
      if (! $mount["mountpoint"]) {
        $mount["mountpoint"] = $mount['target'] ? $mount['target'] : preg_replace("%\s+%", "_", "{$this->usb_mountpoint}/smb_{$mount[ip]}_{$mount[share]}");
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

  public function toggle_automount($source, $status) {
    $config = $this->get_config_file();
    $config[$source]["automount"] = ($status == "true") ? "yes" : "no";
    $this->save_config_file($config);
    return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
  }

  public function remove_config($source) {
    $config = $this->get_config_file();
    unset($config[$source]);
    $this->save_config_file($config);
    return (isset($config[$source])) ? TRUE : FALSE;
  }
}


class NFS {

  protected $config_file, $usb_mountpoint;

  public function __construct() {
    global $GLOBALS;
    $this->config_file =& $GLOBALS["paths"]["remote_config"];
    $this->usb_mountpoint =& $GLOBALS["paths"]["usb_mountpoint"];
  }

  public function get_config_file() {
    if (! is_file($this->config_file)) @mkdir(dirname($this->config_file),0777,TRUE);
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
    if (! $source || ! $var || ! $val) return FALSE;
    $config = $this->get_config_file();
    $config[$source][$var] = $val;
    $this->save_config_file($config);
    return (isset($config[$source][$var])) ? $config[$source][$var] : FALSE;
  }

  public function is_automount($source, $usb=false) {
    $auto = $this->get_config($source, "automount");
    return ( ($auto) ? ( ($auto == "yes") ? TRUE : FALSE ) : TRUE);
  }

  public function get_mounts() {
    $o = array();
    $nfs_mounts = array_filter($this->get_config_file(), function ($v){if($v['protocol'] == "NFS"){return $v;}});
    foreach ($nfs_mounts as $serial => $mount) {
      $mount['serial']   = $serial;
      $mount['device'] = "{$mount[ip]}:{$mount[share]}";
      $mounts = shell_exec("cat /proc/mounts 2>&1");
      $mount['target'] = trim(shell_exec("cat /proc/mounts 2>&1|grep '".str_replace(" ", '\\\040', $mount['device'])." '|awk '{print $2}'"));
      $mount['fstype'] = "nfs";
      $mount['protocol'] = "NFS";
      $mount['size']   = intval(trim(shell_exec("df --output=size,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount[device]}'|awk '{print $1}'")))*1024;
      $mount['used']   = intval(trim(shell_exec("df --output=used,source 2>/dev/null|grep -v 'Filesystem'|grep '{$mount[device]}'|awk '{print $1}'")))*1024;
      $mount['avail']  = $mount['size'] - $mount['used'];
      if (! $mount["mountpoint"]) {
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

  public function toggle_automount($source, $status) {
    $config = $this->get_config_file();
    $config[$source]["automount"] = ($status == "true") ? "yes" : "no";
    $this->save_config_file($config);
    return ($config[$source]["automount"] == "yes") ? TRUE : FALSE;
  }

  public function remove_config($source) {
    $config = $this->get_config_file();
    unset($config[$source]);
    $this->save_config_file($config);
    return (isset($config[$source])) ? TRUE : FALSE;
  }
}

?>