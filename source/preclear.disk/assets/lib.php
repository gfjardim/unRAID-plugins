<?
#load emhttp variables if needed.
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
if (!isset($var)) {
  if (!is_file("$docroot/state/var.ini")) shell_exec("wget -qO /dev/null localhost:$(lsof -nPc emhttp | grep -Po 'TCP[^\d]*\K\d+')");
  $var = @parse_ini_file("$docroot/state/var.ini");
}

$state_file   = "/var/state/{$plugin}/state.ini";
$log_file     = "/var/log/{$plugin}.log";


class TMUX
{

  public function isExecutable()
  {
    return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
  }


  public function hasSession($name)
  {
    exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
    return in_array($name, $screens);
  }


  public function NewSession($name)
  {
    if (! TMUX::hasSession($name))
    {
      exec("/usr/bin/tmux new-session -d -x 140 -y 200 -s '${name}' 2>/dev/null");
    }
  }


  public function getSession($name)
  {
    return (TMUX::hasSession($name)) ? shell_exec("/usr/bin/tmux capture-pane -t '${name}' 2>/dev/null;/usr/bin/tmux show-buffer 2>&1") : NULL;
  }


  public function sendCommand($name, $cmd)
  {
    exec("/usr/bin/tmux send -t '$name' '$cmd' ENTER 2>/dev/null");
  }


  public function killSession($name)
  {
    if (TMUX::hasSession($name))
    {
      exec("/usr/bin/tmux kill-session -t '${name}' >/dev/null 2>&1");
    }
  }

}


class Preclear
  {

  public $plugin = "preclear.disk";

  function __construct()
  {
    exec("/bin/lsblk -nbP -o name,type,label,size,mountpoint,fstype 2>/dev/null", $blocks);
    foreach ($blocks as $b)
    {
      $block = parse_ini_string(preg_replace("$\s+$", PHP_EOL, $b));
      if ($block['TYPE'] == "disk")
      {
        $attrs = parse_ini_string(shell_exec("udevadm info --query=property --name /dev/${block['NAME']} 2>/dev/null"));
        $block['SERIAL'] = isset($attrs["ID_SCSI_SERIAL"]) ? $attrs["ID_SCSI_SERIAL"] : $attrs['ID_SERIAL_SHORT'];
        $this->allDisks[$block['NAME']] = $block;
      }
    }
  }


  public function diskSerial($disk)
  {
    $disk  = basename(trim($disk));
    $disks = array_filter($this->allDisks, function($k) use ($disk) {return $k == $disk;}, ARRAY_FILTER_USE_KEY);
    return count($disks) ? $disks[$disk]["SERIAL"] : NULL;
  }

  
  public function serialDisk($serial)
  {
    $disks = array_values(array_filter($this->allDisks, function($v) use ($serial) {return $v["SERIAL"] == $serial;}));
    var_dump($disks);
    return count($disks) ? "/dev/{$disks[0]['NAME']}" : NULL;
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
    $o["fast_postread"] = $o["version"] ? (strpos(file_get_contents($file), "fast_postread") ? TRUE : FALSE ) : FALSE;
    $o["notifications"] = $o["version"] ? (strpos(file_get_contents($file), "notify_channels") ? TRUE : FALSE ) : FALSE;
    $o["noprompt"]      = $o["version"] ? (strpos(file_get_contents($file), "noprompt") ? TRUE : FALSE ) : FALSE;
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
    $serial = $this->diskSerial($disk);
    $icon   = "<a title='Start Preclear' class='exec tooltip' onclick='startPreclear(\"{$serial}\")'><img src='/plugins/".$this->plugin."/icons/precleardisk.png'></a>";
    $text   = "<a title='Start Preclear' class='exec' onclick='startPreclear(\"{$serial}\")'>Start Preclear</a>";
    return ($type == "text") ? $text : $icon;
  }


  public function isRunning($disk)
  {
    $disk   = basename($disk);
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
    $status  = "";
    $file    = "/tmp/preclear_stat_{$disk}";
    $serial  = $this->diskSerial($disk);
    $session = TMUX::hasSession("preclear_disk_{$serial}");
    $rm      = "<a id='preclear_rm' class='exec tooltip' style='color:#CC0000;font-weight:bold;margin-left:5px;' title='%s' onclick='stopPreclear(\"{$serial}\",\"%s\");'>";
    $rm     .= "<i class='glyphicon glyphicon-remove hdd'></i></a>";
    $preview = "<a id='preclear_open' class='exec tooltip' style='margin-left:5px;' onclick='openPreclear(\"{$serial}\");' title='Preview'><i class='glyphicon glyphicon-eye-open hdd'></i></a>";
    
    if (is_file($file))
    {
      $stat = explode("|", file_get_contents($file));
      
      switch ( count($stat) )
      {
        case 4:
          $running = file_exists( "/proc/".trim($stat[3]) );
          
          if ($running)
          {
            $status .= "<span style='color:#478406;'>{$stat[2]}</span>";
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
          break;

        default:
          $running = false;
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
    $scripts = $this->scriptFiles();
    $capabilities = array_key_exists("joel", $scripts) ? $this->scriptCapabilities($scripts["joel"]) : [];
    ?>
      <div id="preclear-dialog" style="display:none;" title=""></div>
      <div id="joel-start-defaults" style="display:none;">
        <dl>
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
            <dt>Notifications:</dt>
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
        <dl>
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
    <?
  }

}
?>
