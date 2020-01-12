<?
#load emhttp variables if needed.
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
if (!isset($var)) {
  if (!is_file("$docroot/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(lsof -nPc emhttp | grep -Po 'TCP[^\d]*\K\d+')");
  $var = @parse_ini_file("$docroot/state/var.ini");
}

$state_file = "/var/state/preclear.disk/state.ini";
$log_file   = "/var/log/preclear.disk.log";
$diskinfo   = "/var/local/emhttp/plugins/diskinfo/diskinfo.json";
$unsupported = "/var/state/preclear.disk/unsupported";

class TMUX
{

  public static function isExecutable()
  {
    return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
  }


  public static function hasSession($name)
  {
    exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
    return in_array($name, $screens);
  }


  public static function NewSession($name)
  {
    if (! TMUX::hasSession($name))
    {
      exec("/usr/bin/tmux new-session -d -x 140 -y 200 -s '${name}' 2>/dev/null");
    }
  }


  public static function getSession($name)
  {
    return (TMUX::hasSession($name)) ? shell_exec("/usr/bin/tmux capture-pane -t '${name}' 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : NULL;
  }


  public static function sendCommand($name, $cmd)
  {
    exec("/usr/bin/tmux send -t '$name' '$cmd' ENTER 2>/dev/null");
  }


  public static function killSession($name)
  {
    if (TMUX::hasSession($name))
    {
      exec("/usr/bin/tmux kill-session -t '${name}' >/dev/null 2>&1");
    }
  }

}

class Misc
{

  public static function save_json($file, $content)
  {
    file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT ));
  }


  public static function get_json($file)
  {
    $out = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    return is_array($out) ? $out : []; 
  }


  public static function disk_device($disk)
  {
    $name = Misc::disk_name($disk);
    return (file_exists($disk)) ? $disk : "/dev/${name}";
  }


  public static function disk_name($disk)
  {
    return (file_exists($disk)) ? basename($disk) : $disk;
  }


  public static function array_first_element($arr)
  {
    return (is_array($arr) && count($arr)) ? $arr[0] : $arr;
  }
}


class Preclear
  {

  public $plugin = "preclear.disk";

  function __construct()
  {
    global $diskinfo;
    $this->allDisks = Misc::get_json($diskinfo);
  }


  public function diskSerial($disk)
  {
    $disk  = Misc::disk_name($disk);
    return count($this->allDisks) ? $this->allDisks[$disk]["SERIAL_SHORT"] : NULL;
  }

  
  public function serialDisk($serial)
  {
    $disks = array_values(array_filter($this->allDisks, function($v) use ($serial) {return $v["SERIAL_SHORT"] == $serial;}));
    return count($disks) ? $disks[0]['DEVICE'] : NULL;
  }


  public function Authors()
  {
    $authors      = ["gfjardim" => "gfjardim", "joel" => "Joe L."];
    $scripts      = $this->scriptFiles();

    foreach ($authors as $key => $name) {
      $capabilities = array_key_exists($key, $scripts) ? $this->scriptCapabilities($scripts[$key]) : [];

      if ( array_key_exists("version", $capabilities) && $capabilities["version"] )
      {
        if ( $capabilities["fast_postread"] )
        {
          $name = "bjp999";      
        }
        $authors[$key] = "$name - ${capabilities['version']}";      
      }

    }
    return $authors;
  }


  public function Author($author)
  {
    return $this->Authors()[$author];
  }


  public function scriptCapabilities($file)
  {
    $o["version"]       = (is_file($file)) ? trim(shell_exec("$file -v 2>/dev/null|cut -d: -f2")) : NULL;
    $o["file"]          = $file;
    $o["fast_postread"] = $o["version"] ? (strpos(file_get_contents($file), "fast_postread")   ? TRUE : FALSE ) : FALSE;
    $o["notifications"] = $o["version"] ? (strpos(file_get_contents($file), "notify_channels") ? TRUE : FALSE ) : FALSE;
    $o["noprompt"]      = $o["version"] ? (strpos(file_get_contents($file), "noprompt")        ? TRUE : FALSE ) : FALSE;
    return $o;
  }


  public function scriptFiles()
  {
    $scripts = ["gfjardim" => "/usr/local/emhttp/plugins/".$this->plugin."/script/preclear_disk.sh",
                "joel"     => "/boot/config/plugins/".$this->plugin."/preclear_disk.sh"];

    foreach ($scripts as $author => $file)
    {
      if (! is_file($file))
      {
        unset($scripts[$author]);
      }
    }
    return $scripts;
  }


  public function Script()
  {
    echo "var plugin = '".$this->plugin."';\n";
    echo "var authors = ".json_encode($this->Authors()).";\n";
    echo "var scope  = 'gfjardim';\n";
    echo "var scripts = ".json_encode($this->scriptFiles()).";\n";
    printf("var zip = '%s-%s-%s.zip';\n", str_replace(' ','_',strtolower($var['NAME'])), $this->plugin, date('Ymd-Hi') );
    echo file_get_contents("plugins/".$this->plugin."/assets/javascript.js");
  }


  public function Link($disk, $type)
  {
    global $unsupported;
    $serial = $this->diskSerial($disk);
    $icon   = "<a title='Start Preclear' class='exec tooltip' onclick='getResumablePreclear(\"{$serial}\")'><img src='/plugins/".$this->plugin."/icons/precleardisk.png'></a>";
    $text   = "<a title='Start Preclear' class='exec' onclick='getResumablePreclear(\"{$serial}\")'>Start Preclear</a>";
    if (is_file($unsupported))
    {
      $icon   = "<a title='Start Preclear' class='exec'><img src='/plugins/".$this->plugin."/icons/precleardisk.png' style='-webkit-filter: grayscale(100%); filter: grayscale(100%);'></a>";
      $text   = "Start Preclear";
    } 
    return ($type == "text") ? $text : $icon;
  }


  public function isRunning($disk)
  {
    $serial = $this->diskSerial($disk);
    if ( TMUX::hasSession("preclear_disk_{$serial}") )
    {
      return true;
    }
    else
    {
      return is_file("/tmp/preclear_stat_{$disk}");
    }
  }


  public function Status($disk, $serial)
  {
    $disk    = Misc::disk_name($disk);
    $status  = "";
    $file    = "/tmp/preclear_stat_{$disk}";
    $serial  = $this->diskSerial($disk);
    $session = TMUX::hasSession("preclear_disk_{$serial}");
    $paused  = file_exists("/tmp/.preclear/${disk}/pause") ? "<a class='exec tooltip' style='margin-left:10px;color:#00BE37;' onclick='resumePreclear(\"${disk}\")' title='Resume'><i class='fa fa-play'></i></a>" : "";
    $rm      = "<a id='preclear_rm_{$disk}' class='exec tooltip' style='color:#CC0000;font-weight:bold;margin-left:5px;' title='%s' onclick='stopPreclear(\"{$serial}\",\"%s\");'>";
    $rm     .= "<i class='fa fa-times hdd'></i></a>";
    $preview = "<a id='preclear_open_{$disk}' class='exec tooltip' style='margin-left:5px;color:#1E90FF;' onclick='openPreclear(\"{$serial}\");' title='Preview'><i class='fa fa-eye hdd'></i></a>";
    
    if (is_file($file))
    {
      $stat = explode("|", file_get_contents($file));
      
      switch ( count($stat) )
      {
        case 4:
          $running = file_exists( "/proc/".trim($stat[3]) );
          $log     = "<a class='exec tooltip' title='Preclear Log' style='margin:0px -3px 0px 5px;color:#1E90FF;' onclick='openWindow(\"/plugins/preclear.disk/script/tail_log&amp;arg1=preclear_disk_${serial}_".trim($stat[3])."\",\"Log Information\",600,900);'><i class='fa fa-file-text-o'></i></a>";
          
          if ($running)
          {
            if (preg_match("#PAUSED|QUEUED#", $stat[2]))
            {
              $status .= "<span style='color:#ccb800;'>{$stat[2]}</span>";
            }
            else
            {
              $status .= "<span style='color:#00BE37;'>{$stat[2]}</span>";
            }
          }

          else
          {

            if (preg_match("#failed|FAIL#", $stat[2]))
              {
              $status .= "<span style='color:#CC0000;'>{$stat[2]}</span>";
            }

            else
            {
              $status .= "<span>{$stat[2]}</span>";
            }
          }
          $preview = "${paused}${log}${preview}";
          break;

        default:
          $running = false;
          $log     = "";
          $status .= "<span>{$stat[2]}</span>";
          break;
      }

      if ($session && $running)
      {
        $status .= $preview;
        $status .= sprintf($rm, "Stop Preclear", "ask");
      }

      else if ($session)
      {
        $status .= $preview;
        $status .= sprintf($rm, "Stop Preclear", "");
      }

      elseif ( $file )
      {
        $status .= sprintf($rm, "Clear Stats", "");
      }
    }

    else if($this->isRunning($disk))
    {
      $status .= $preview;
      $status .= sprintf($rm, "Clear Stats", "");
    }

    else
    {
      $status .= sprintf($rm, "Clear Stats", "");
    }
    
    return str_replace("^n", "<BR>" , $status);
  }


  public function html()
  {
    for ($i=1; $i <= 20; $i++)
    {
      $cycles .= "<option value='$i'>$i</option>";
    }

    foreach (range(0,8) as $i)
    {
      $x=pow(2,$i);
      $size .= "<option value='65536 -b ".($x*16)."'>{$x}M</option>";
    }

    for ($i=1; $i <= 7; $i++)
    {
      $cycles2 .= "<option value='$i'>$i</option>";
    }

    foreach (range(5,11) as $i)
    {
      $x=pow(2,$i);
      $size2 .= "<option value='".($x*16*65536)."'>{$x}M</option>";
    }

    $queued = is_file("/boot/config/plugins/preclear.disk/queue") ? trim(file_get_contents("/boot/config/plugins/preclear.disk/queue")) : 0;
    for ($i=0; $i <= 20; $i++)
    {
      $selected = ($i == $queued) ? "selected" : "";
      $queue .= ($i == 0) ? "<option value='$i' $selected>disable</option>" : "<option value='$i' $selected>$i</option>";
    }
    $scripts = $this->scriptFiles();
    $capabilities = array_key_exists("joel", $scripts) ? $this->scriptCapabilities($scripts["joel"]) : [];
    ?>
      <style type="text/css">
        .dl-dialog{margin-bottom: 8px; line-height: 16px; text-align: left;}
        .sweet-alert input[type="checkbox"] {display: initial; width: auto; height: auto; margin: auto 3px auto auto; vertical-align: top;}
      </style>
      <div id="preclear-dialog" style="display:none;" title=""></div>
      <div id="dialog-header-defaults" style="display:none;">
        <dl class="dl-dialog"><dt>Model Family:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{family}</span></dd></dl>
        <dl class="dl-dialog"><dt>Device Model:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{model}</span></dd></dl>
        <dl class="dl-dialog"><dt>Serial Number:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{serial_short}</span></dd></dl>
        <dl class="dl-dialog"><dt>Firmware Version:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{firmware}</span></dd></dl>
        <dl class="dl-dialog"><dt>Size:</dt><dd style='margin-bottom:0px;'><span style='color:#EF3D47;font-weight:bold;'>{size_h}</span></dd></dl>
      </div>
      <div id="dialog-multiple-defaults" style="display:none;">
        <dl class="dl-dialog">
          <dt>Select Disks: </dt>
          <dd style='margin-bottom:0px;'>
              <select id="multiple_preclear" name="disks" multiple class='chosen swal' data-placeholder="Select Your Options">
                {0}
              </select>
          </dd>
        </dl>
      </div>
      <div id="joel-start-defaults" style="display:none;">
        <dl class="dl-dialog">
          <dt>Operation: </dt>
          <dd>
            <select name="op" onchange="toggleSettings(this);">
              <option value='0'>Clear</option>
              <option value='-V'>Run the post-read verify</option>
              <option value='-t'>Test</option>
              <option value='-C 64'>Convert to a start sector of 64</option>
              <option value='-C 63'>Convert to a start sector of 63</option>
              <option value='-z'>Zero only the MBR</option>
            </select>
          </dd>
          <div class='write_options'>
            <dt>Cycles: </dt>
            <dd>
              <select name="-c"><?=$cycles;?></select>
            </dd>
          </div>
          <?if ( array_key_exists("notifications", $capabilities) && $capabilities["notifications"] ):?>
          <div class="notify_options">
            <dt>Notifications: </dt>
            <dd style="font-weight: normal;">
              <input type="checkbox" name="preclear_notify1" onchange="toggleFrequency(this, '-M');">Browser &nbsp;
              <input type="checkbox" name="preclear_notify2" onchange="toggleFrequency(this, '-M');">Email &nbsp;
              <input type="checkbox" name="preclear_notify3" onchange="toggleFrequency(this, '-M');">Agents &nbsp;
            </dd>
            <dt>&nbsp;</dt>
            <dd>
              <select name="-M" disabled>
                <option value="1" selected>On preclear end</option>
                <option value="2">On every cycle end</option>
                <option value="3">On every cycle and step end</option>
                <option value="4">On every 25% of progress</option>
              </select>
              </dd>
          </div>
          <?endif;?>
          <div class='read_options'>
            <dt>Read size: </dt>
            <dd>
              <select name="-r">
                <option value="0">Default</option><?=$size;?>
              </select>
            </dd>
          </div>
          <div class='write_options'>
            <dt>Write size: </dt>
            <dd>
              <select name="-w">
                <option value="0">Default</option><?=$size;?>
              </select>
            </dd>
            <dt>Skip Pre-read: </dt>
            <dd>
              <input type="checkbox" name="-W" class="switch" >
            </dd>
          </div>
          <?if ( array_key_exists("fast_postread", $capabilities) && $capabilities["fast_postread"] ):?>
          <div class='postread_options'>
            <dt>Fast post-read verify: </dt>
            <dd>
              <input type="checkbox" name="-f" class="switch" >
            </dd>
          </div>
          <?endif;?>
          <div class='inline_help'>
            <dt>Enable Testing (just for debugging):</dt>
            <dd>
              <input type="checkbox" name="-s" class="switch" >
            </dd>
          </div>
        </dl>
      </div>

      <div id="gfjardim-start-defaults" style="display:none;">
        <dl class="dl-dialog">
          <dt>Operation: </dt>
          <dd>
            <select name="op" onchange="toggleSettings(this);">
              <option value="0">Clear</option>
              <option value="--verify">Verify All the Disk</option>
              <option value="--signature">Verify MBR Only</option>
              <option value="--erase">Erase All the Disk</option>
              <option value="--erase-clear">Erase and Clear the Disk</option>
            </select>
          </dd>
          <div class="write_options cycles_options">
            <dt>Cycles: </dt>
            <dd>
              <select name="--cycles"><?=$cycles2;?></select>
            </dd>
          </div>
          <div class="notify_options">
            <dt>Notifications:</dt>
            <dd style="font-weight: normal;">
              <input type="checkbox" name="preclear_notify1" onchange="toggleFrequency(this, '--frequency');">Browser &nbsp;
              <input type="checkbox" name="preclear_notify2" onchange="toggleFrequency(this, '--frequency');">Email &nbsp;
              <input type="checkbox" name="preclear_notify3" onchange="toggleFrequency(this, '--frequency');">Agents &nbsp;
            </dd>
            <dt>&nbsp;</dt>
            <dd>
              <select name="--frequency" disabled>
                <option value="1" selected>On preclear end</option>
                <option value="2">On every cycle end</option>
                <option value="3">On every cycle and step end</option>
                <option value="4">On every 25% of progress</option>
              </select>
            </dd>
          </div>
          <div class="write_options">
            <dt>Skip Pre-Read: </dt>
            <dd>
              <input type="checkbox" name="--skip-preread" class="switch" >
            </dd>
            <dt>Skip Post-Read: </dt>
            <dd>
              <input type="checkbox" name="--skip-postread" class="switch" >
            </dd>
          </div>
          <div class='inline_help'>
            <dt>Enable Testing (just for debugging):</dt>
            <dd>
              <input type="checkbox" name="--test" class="switch" >
            </dd>
          </div>
        </dl>
      </div>

      <div id="preclear-set-queue-defaults" style="display:none;">
        <dl>
          If you set a queue limit, all running preclear sessions above that limit will be paused and remain in the queue until a session finishes.<br><br>
        </dl>
        <dl class="dl-dialog">
          <dt>Concurrent sessions: </dt>
          <dd>
            <select name="queue">
                <?=$queue;?>
              </select>
          </dd>
        </dl>
      </div>
    <?
  }

}
?>
