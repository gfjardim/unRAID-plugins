<?

class Preclear
  {

  public $plugin = "preclear.disk";
  

  public $authors = ["gfjardim" => "gfjardim", "joel" => "Joe L."];


  public function Author($author)
  {
    return $this->authors[$author];
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
    echo "var plugin = '".$this->plugin."';";
    echo "var authors = ".json_encode($this->authors).";";
    echo "var scope  = 'gfjardim';";
    echo "var scripts = ".json_encode($this->scriptFiles()).";";
    echo file_get_contents("plugins/".$this->plugin."/assets/javascript.js");
  }


  public function Link($disk, $type)
  {
    $disk = file_exists($disk) ? basename($disk) : $disk;
    $icon = "<a title='Preclear Disk' class='exec' onclick='startPreclear(\"{$disk}\")'><img src='/plugins/".$this->plugin."/icons/precleardisk.png'></a>";
    $text = "<a title='Preclear Disk' class='exec' onclick='startPreclear(\"{$disk}\")'>Start Preclear</a>";
    return ($type == "text") ? $text : $icon;
  }


  private function is_tmux_executable()
  {
    return is_file("/usr/bin/tmux") ? (is_executable("/usr/bin/tmux") ? TRUE : FALSE) : FALSE;
  }


  private function tmux_is_session($disk)
  {
    if (is_tmux_executable())
    {
      exec('/usr/bin/tmux ls 2>/dev/null|cut -d: -f1', $screens);
      return in_array($disk, $screens);
    }

    else
    {
      return false;
    }
  }


  public function isRunning($disk)
  {
    $disk = file_exists($disk) ? basename($disk) : $disk;
    if ( $this->tmux_is_session("preclear_disk_{$disk}") )
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
    $session = $this->tmux_is_session("preclear_disk_{$disk}");
    $rm      = "<a class='exec' title='%s' style='color:#CC0000;font-weight:bold;' onclick='stopPreclear(\"{$serial}\",\"{$disk}\",\"%s\");'>";
    $rm     .= "<i class='glyphicon glyphicon-remove hdd'></i></a>";
    $preview = "<a class='exec' onclick='openPreclear(\"{$disk}\");' title='Preview'><i class='glyphicon glyphicon-eye-open'></i></a>";
    
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
              $status .= "<span'>{$stat[2]}</span>";
            }
          }
          break;

        default:
          $running = false;
          $status .= "<span >{$stat[2]}</span>";
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
    global $notifications, $fast_postread;
    for ($i=1; $i <= 20; $i++)
    {
      $cycles .= "<option value='$i'>$i</option>";
    }

    foreach (range(0,8) as $i)
    {
      $x=pow(2,$i);
      $size .= "<option value='65536 -b ".($x*16)."'>{$x}M</option>";
    }

    for ($i=1; $i <= 5; $i++)
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
          <div class='clear_options'>
            <dt>Cycles: </dt>
            <dd>
              <select name="-c"><?=$cycles;?></select>
            </dd>
          </div>
          <?if ( array_key_exists("notifications", $capabilities) && $capabilities["notifications"] ):?>
          <div class="clear_verify_options">
            <dt>Notifications:</dt>
            <dd style="font-weight: normal;">
              <input type="checkbox" name="preclear_notify1" onchange="toggleFrequency(this, '-M');">Browser &nbsp;
              <input type="checkbox" name="preclear_notify2" onchange="toggleFrequency(this, '-M');">Email &nbsp;
              <input type="checkbox" name="preclear_notify3" onchange="toggleFrequency(this, '-M');">Agents &nbsp;
            </dd>
            <dt>&nbsp;</dt>
            <dd>
              <select name="-M" disabled>
                <option value="1" selected>On preclear's end</option>
                <option value="2">On every cycles's end</option>
                <option value="3">On every cycles's and step's end</option>
                <option value="4">On every 25% of progress</option>
              </select>
              </dd>
          </div>
          <?endif;?>
          <div class='clear_options'>
            <dt>Read size: </dt>
            <dd>
              <select name="-r">
                <option value="0">Default</option><?=$size;?>
              </select>
            </dd>
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
          <div class='test_options'>
            <dt>Fast post-read verify: </dt>
            <dd>
              <input type="checkbox" name="-f" class="switch" >
            </dd>
          </div>
          <?endif;?>
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
            </select>
          </dd>
          <div class="clear_options">
            <dt>Cycles: </dt>
            <dd>
              <select name="--cycles"><?=$cycles2;?></select>
            </dd>
          </div>
          <div class="clear_verify_options">
            <dt>Notifications:</dt>
            <dd style="font-weight: normal;">
              <input type="checkbox" name="preclear_notify1" onchange="toggleFrequency(this, '--frequency');">Browser &nbsp;
              <input type="checkbox" name="preclear_notify2" onchange="toggleFrequency(this, '--frequency');">Email &nbsp;
              <input type="checkbox" name="preclear_notify3" onchange="toggleFrequency(this, '--frequency');">Agents &nbsp;
            </dd>
            <dt>&nbsp;</dt>
            <dd>
              <select name="--frequency" disabled>
                <option value="1" selected>On preclear's end</option>
                <option value="2">On every cycles's end</option>
                <option value="3">On every cycles's and step's end</option>
                <option value="4">On every 25% of progress</option>
              </select>
            </dd>
            <dt>Read size: </dt>
            <dd>
              <select name="--read-size" >
                <option value="33554432">32M</option>
                <option value="67108864">64M</option>
                <option value="134217728" selected>128M</option>
                <option value="268435456">256M</option>
                <option value="536870912">512M</option>
                <option value="1073741824">1024M</option>
                <option value="2147483648">2048M</option>
              </select>
            </dd>
          </div>
          <div class="clear_options">
            <dt>Skip Pre-Read: </dt>
            <dd>
              <input type="checkbox" name="--skip-preread" class="switch" >
            </dd>
            <dt>Skip Post-Read: </dt>
            <dd>
              <input type="checkbox" name="--skip-postread" class="switch" >
            </dd>
          </div>
        </dl>
      </div>
    <?
  }

}
?>
