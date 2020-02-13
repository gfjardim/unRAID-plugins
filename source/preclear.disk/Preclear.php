<?
set_error_handler("log_error");
set_exception_handler( "log_exception" );
$plugin = "preclear.disk";

require_once( "webGui/include/Helpers.php" );
require_once( "plugins/${plugin}/assets/lib.php" );

#########################################################
#############           VARIABLES          ##############
#########################################################

$Preclear     = new Preclear;
$script_files = $Preclear->scriptFiles();
// $VERBOSE        = TRUE;
// $TEST           = TRUE;

if (isset($_POST['display']))
{
  $display = $_POST['display'];
}

if (! is_dir(dirname($state_file)) )
{
  @mkdir(dirname($state_file),0777,TRUE);
}

#########################################################
#############        MISC FUNCTIONS        ##############
#########################################################

function log_error($errno, $errstr, $errfile, $errline)
{
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

function log_exception( $e )
{
  debug("PHP Exception: {$e->getMessage()} in {$e->getFile()} on line {$e->getLine()}");
}

function debug($msg, $type = "NOTICE")
{
  if ( $type == "DEBUG" && ! $GLOBALS["VERBOSE"] )
  {
    return NULL;
  }
  $msg = "\n".date("D M j G:i:s T Y").": ".print_r($msg,true);
  file_put_contents($GLOBALS["log_file"], $msg, FILE_APPEND);
}


function _echo($m)
{
  echo "<pre>".print_r($m,TRUE)."</pre>";
};


function reload_partition($name)
{
  exec("hdparm -z /dev/{$name} >/dev/null 2>&1 &");
}


function listDir($root)
{
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root, 
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = array();

  foreach ($iter as $path => $fileinfo)
  {
    if (! $fileinfo->isDir()) $paths[] = $path;
  }

  return $paths;
}

$start_time = time();
switch ($_POST['action'])
{

  case 'get_content':
    debug("Starting get_content: ".(time() - $start_time),'DEBUG');
    // shell_exec("/etc/rc.d/rc.diskinfo --daemon &>/dev/null");
    $disks = Misc::get_json($diskinfo);
    foreach ($disks as $disk => $attibutes) {
      $disks[$disk]["PRECLEARING"] = $Preclear->isRunning($attibutes["DEVICE"]);
    }
    $all_status = array();
    $all_disks_o = [];

    $sort = array_flip(is_file($sort_file) ? file($sort_file,FILE_IGNORE_NEW_LINES) : []);

    if ( count($disks) )
    {
      $odd="odd";
      $counter = 9999;
      foreach ($disks as $disk)
      {
        $disk_name = $disk['NAME'];
        $disk_icon = ($disk['RUNNING']) ? "green-on.png" : "green-blink.png";
        $serial    = trim($disk['SERIAL']);
        $temp      = $disk['TEMP'] ? my_temp($disk['TEMP']) : "*";
        $mounted   = $disk["MOUNTED"];
        $reports   = is_dir("/boot/preclear_reports") ? glob("/boot/preclear_reports/*.txt") : [];
        $reports   = array_filter($reports, function ($report) use ($disk)
                                  {
                                    return preg_match("|".$disk["SERIAL_SHORT"]."|", $report) && ( preg_match("|_report_|", $report) || preg_match("|_rpt_|", $report) ); 
                                  });

        if (count($reports))
        {
          $title  = "<span title='Click to view reports.' class='exec toggle-reports' style='margin-left:0px;' hdd='{$disk_name}'>
                      <i class='fa fa-hdd-o hdd'></i>
                      <i class='fa fa-plus-circle fa-append'></i>
                      ${disk['SERIAL']}
                    </span>";
          
          $report_files = "<div class='toggle-${disk_name}' style='display:none;'>";

          foreach ($reports as $report)
          {
            $report_files .= "<div style='margin:4px 0px 4px 0px;'>
                                <i class='fa fa-list-alt hdd'></i>
                                <span style='margin:7px;'></span>
                                <a href='${report}' target='_blank'>".pathinfo($report, PATHINFO_FILENAME)."</a>
                                <a class='exec' title='Remove Report' style='color:#CC0000;font-weight:bold;' onclick='rmReport(\"{$report}\", this);'>
                                  &nbsp;<i class='fa fa-times hdd'></i>
                                </a>
                              </div>";  
          }
          
          $report_files .= "</div>";
        }
        else
        {
          $report_files="";
          $title  = "<span class='toggle-reports' hdd='{$disk_name}' style='margin-left:0px;'><i class='fa fa-hdd-o hdd'></i><span style='margin:8px;'></span>{$serial}";
        }

        if ($Preclear->isRunning($disk_name))
        {
          $status  = $Preclear->Status($disk_name, $disk["SERIAL_SHORT"]);
          $footer = base64_encode("<span>${disk['SERIAL']} - ${disk['SIZE_H']} (${disk['NAME']})</span><br><span style='float:right;'>$status</span>");
          $footer = "<a class='tooltip-toggle-html exec' id='preclear_footer_${disk['SERIAL_SHORT']}' title=' ' data='${footer}'><img src='/plugins/preclear.disk/icons/precleardisk.png'></a>";
          $all_status[$disk['SERIAL_SHORT']]["footer"] = $footer;
          $all_status[$disk['SERIAL_SHORT']]["footer"] = "<span>${disk['SERIAL']} (${disk['NAME']}) <br> Size: ${disk['SIZE_H']} | Temp: ". my_temp($disk['TEMP']) ."</span><br><span style='float:right;'>$status</span>";
          $all_status[$disk['SERIAL_SHORT']]["status"] = $status;
        }
        else
        {
          $status  = $mounted ? "Disk mounted" : $Preclear->Link($disk_name, "text");
        }
        
        $output = "<tr class='$odd sortable' device='${disk_name}'>
                      <td><img src='/webGui/images/${disk_icon}'><a href='/Tools/Preclear/New?name=$disk_name'> $disk_name</a></td>
                      <td>${title}${report_files}</td>
                      <td>{$temp}</td>
                      <td><span>${disk['SIZE_H']}</span></td>
                      <td><a href=\"#\" title=\"Disk Log Information\" onclick=\"openBox('/webGui/scripts/disk_log&amp;arg1=$disk_name','Disk Log Information',600,900,false);return false\"><i class=\"fa fa-file-text-o icon\"></i></a></td>
                      <td>{$status}</td>
                    </tr>";
        $disks_o .= "${output}${report_files}";
        $pos = array_key_exists($disk_name, $sort) ? $sort[$disk_name] : $counter;
        $sort[$disk_name] = $pos;
        $all_disks_o[$disk_name] = "${output}${report_files}";
        $odd = ($odd == "odd") ? "even" : "odd";
        $counter++;
      }
    }

    else 
    {
      $all_disks_o[] = "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No unassigned disks available.</td></tr>";
    }
    debug("get_content Finished: ".(time() - $start_time),'DEBUG');
    $sort = array_flip($sort);
    sort($sort, SORT_NUMERIC);
    $queue = (is_file("/var/run/preclear_queue.pid") && posix_kill(file_get_contents("/var/run/preclear_queue.pid"), 0)) ? true : false;
    echo json_encode(array("disks" => $all_disks_o, "info" => json_encode($disks), "status" => $all_status, "sort" => $sort, "queue" => $queue));
    break;



  case 'get_status':
    $disk_name = urldecode($_POST['device']);
    $serial    = urldecode($_POST['serial']);
    $status    = $Preclear->Status($disk_name, $serial);
    echo json_encode(array("status" => $status));
    break;



  case 'start_preclear':
    $devices = is_array($_POST['device']) ? $_POST['device'] : [$_POST['device']];
    $success = true;

    foreach ($devices as $device) {
      $serial  = $Preclear->diskSerial($device);
      $session = "preclear_disk_{$serial}";
      $op      = (isset($_POST['op']) && $_POST['op'] != "0") ? urldecode($_POST['op']) : "";
      $file    = (isset($_POST['file'])) ? urldecode($_POST['file']) : "";
      $scope   = $_POST['scope'];
      $script  = $script_files[$scope];
      $devname = basename($device);

      # Verify if the disk is suitable to preclear
      if ( $Preclear->isRunning($device) || (array_key_exists($devname, $Preclear->allDisks) && $Preclear->allDisks[$devname]["MOUNTED"] ))
      {
        debug("Disk ${serial} not suitable for preclear.");
        continue;
      }

      @file_put_contents("/tmp/preclear_stat_{$devname}","{$devname}|NN|Starting...");

      if ( $op == "resume" && is_file($file))
      {
        $cmd = "$script --load-file ".escapeshellarg($file)." ${device}";
      }

      else if($op == "resume" && ! is_file($file))
      {
        break;
      }

      else if ($scope == "gfjardim")
      {
        $notify    = (isset($_POST['--notify']) && $_POST['--notify'] > 0) ? " --notify ".urldecode($_POST['--notify']) : "";
        $frequency = (isset($_POST['--frequency']) && $_POST['--frequency'] > 0 && intval($_POST['--notify']) > 0) ? " --frequency ".urldecode($_POST['--frequency']) : "";
        $cycles    = (isset($_POST['--cycles'])) ? " --cycles ".urldecode($_POST['--cycles']) : "";
        $pre_read  = (isset($_POST['--skip-preread']) && $_POST['--skip-preread'] == "on") ? " --skip-preread" : "";
        $post_read = (isset($_POST['--skip-postread']) && $_POST['--skip-postread'] == "on") ? " --skip-postread" : "";
        $test      = (isset($_POST['--test']) && $_POST['--test'] == "on") ? " --test" : "";
        $noprompt  = " --no-prompt";

        $cmd = "$script {$op}${notify}${frequency}{$cycles}{$pre_read}{$post_read}{$noprompt}{$test} $device";
        
      }

      else
      {
        $notify    = (isset($_POST['-o']) && $_POST['-o'] > 0) ? " -o ".urldecode($_POST['-o']) : "";
        $mail      = (isset($_POST['-M']) && $_POST['-M'] > 0 && intval($_POST['-o']) > 0) ? " -M ".urldecode($_POST['-M']) : "";
        $passes    = isset($_POST['-c']) ? " -c ".urldecode($_POST['-c']) : "";
        $read_sz   = (isset($_POST['-r']) && $_POST['-r'] != 0) ? " -r ".urldecode($_POST['-r']) : "";
        $write_sz  = (isset($_POST['-w']) && $_POST['-w'] != 0) ? " -w ".urldecode($_POST['-w']) : "";
        $pre_read  = (isset($_POST['-W']) && $_POST['-W'] == "on") ? " -W" : "";
        $post_read = (isset($_POST['-X']) && $_POST['-X'] == "on") ? " -X" : "";
        $fast_read = (isset($_POST['-f']) && $_POST['-f'] == "on") ? " -f" : "";
        $confirm   = (! $op || $op == " -z" || $op == " -V") ? TRUE : FALSE;
        $test      = (isset($_POST['-s']) && $_POST['-s'] == "on") ? " -s" : "";

        $capable  = array_key_exists("joel", $script_files) ? $Preclear->scriptCapabilities($script_files["joel"]) : [];
        $noprompt = (array_key_exists("noprompt", $capable) && $capable["noprompt"]) ? " -J" : "";
        
        if ( $post_read && $pre_read )
        {
          $post_read = " -n";
          $pre_read = "";
        }
        
        if (! $op )
        {
          $cmd = "$script {$op}{$mail}{$notify}{$passes}{$read_sz}{$write_sz}{$pre_read}{$post_read}{$fast_read}{$noprompt}{$test} $device";
        }

        else if ( $op == "-V" )
        {
          $cmd = "$script {$op}{$fast_read}{$mail}{$notify}{$read_sz}{$write_sz}{$noprompt}{$test} $device";
        }

        else
        {
          $cmd = "$script {$op}{$noprompt} $device";
          @unlink("/tmp/preclear_stat_{$devname}");
        }
      }

      // Enabling queue
      $queue_file="/boot/config/plugins/${plugin}/queue";
      $queue = is_file($queue_file) ? (is_numeric(file_get_contents($queue_file)) ? file_get_contents($queue_file) : 0 ) : 0;
      $queue_running = is_file("/var/run/preclear_queue.pid") && posix_kill(file_get_contents("/var/run/preclear_queue.pid"), 0);
      if ($queue > 0)
      {
        if (! TMUX::hasSession("preclear_queue"))
        {
          TMUX::NewSession("preclear_queue");
        }
        if (! $queue_running)
        {
          TMUX::sendCommand("preclear_queue", "/usr/local/emhttp/plugins/${plugin}/script/preclear_queue.sh $queue");
        }
      }

      if (! TMUX::hasSession( $session ))
      {
        TMUX::NewSession( $session );
        usleep( 500 * 1000 );
        TMUX::sendCommand($session, $cmd);
      }
      else
      {
        $success = false;
      }

      if ( $confirm && ! $noprompt )
      {
        foreach( range(0, 5) as $x )
        {
          if ( strpos(TMUX::getSession($session), "Answer Yes to continue") )
          {
            sleep(1);
            TMUX::sendCommand($session, "Yes");
            break;
          }

          else
          {
            sleep(1);
          }
        }
      }
    }

    echo json_encode(["success" => $success]);
    break;



  case 'stop_preclear':
    $serials = is_array($_POST['serial']) ? $_POST['serial'] : [$_POST['serial']];
    foreach ($serials as $serial)
    {
      $device = basename($Preclear->serialDisk($serial));
      
      TMUX::sendKeys("preclear_disk_{$serial}", "C-c");
      
      $file = "/tmp/preclear_stat_{$device}";
      if (is_file($file))
      {
        $stat = explode("|", file_get_contents($file));
        $pid  = count($stat) == 4 ? trim($stat[3]) : "";
        foreach (range(0, 30) as $num)
        {
          if (! file_exists( "/proc/$pid/exe")) break;
          usleep( 500 * 1000 );
        }
        # make sure all children are killed
        shell_exec("kill $(ps -s '$pid' -o pid=) &>/dev/null");
      }

      TMUX::killSession("preclear_disk_{$serial}");
      if (is_file("/tmp/preclear_stat_{$device}")) @unlink("/tmp/preclear_stat_{$device}");
      if (is_file("/tmp/.preclear/{$device}/pid")) @unlink("/tmp/.preclear/{$device}/pid");

      reload_partition($serial);
    }

    echo json_encode(["success" => true]);
    break;
    


  case 'stop_all_preclear':
    exec("/usr/bin/tmux ls 2>/dev/null|grep 'preclear_disk_'|cut -d: -f1", $sessions);
    foreach ($sessions as $session) {
      $serial = str_replace("preclear_disk_", "", $session);
      $device = basename($Preclear->serialDisk($serial));
      $file = "/tmp/preclear_stat_{$device}";
      TMUX::sendKeys($session, "C-c");
      if (is_file($file))
      {
        $stat = explode("|", file_get_contents($file));
        $pid  = count($stat) == 4 ? trim($stat[3]) : "";
        foreach (range(0, 30) as $num)
        {
          if (! file_exists( "/proc/$pid/exe")) break;
          usleep( 500 * 1000 );
        }
        # make sure all children are killed
        shell_exec("kill $(ps -s '$pid' -o pid=) &>/dev/null");
      }
      TMUX::killSession($session);
      @unlink("/tmp/preclear_stat_{$device}");
      reload_partition($serial);
    }
    echo json_encode(["success" => true]);
    break;


  case 'clear_preclear':
    $serial = urldecode($_POST['serial']);
    $device = basename($Preclear->serialDisk($serial));
    TMUX::killSession("preclear_disk_{$serial}");
    @unlink("/tmp/preclear_stat_{$device}");
    echo "<script>parent.location=parent.location;</script>";
    break;


  case 'get_preclear':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    if ( ! TMUX::hasSession($session))
    {
      $output = "<script>window.close();</script>";
    }
    $content = preg_replace("#root@[^:]*:.*#", "", TMUX::getSession($session));
    $output .= "<pre>".preg_replace("#\n{5,}#", "<br>", $content)."</pre>";
    if ( strpos($content, "Answer Yes to continue") || strpos($content, "Type Yes to proceed") )
    {
      $output .= "<br><center><button onclick='hit_yes(\"{$serial}\")'>Answer Yes</button></center>";
    }
    echo json_encode(array("content" => $output));
    break;


  case 'hit_yes':
    $serial  = urldecode($_POST['serial']);
    $session = "preclear_disk_{$serial}";
    TMUX::sendCommand($session, "Yes");
    break;


  case 'remove_report':
    $file = $_POST['file'];
    if (! is_bool( strpos($file, "/boot/preclear_reports")))
    {
      unlink($file);
      echo "true";
    }
    break;


  case 'download':
    $dir  = "/preclear";
    $file = $_POST["file"];
    @mkdir($dir);
    exec("cat $log_file 2>/dev/null | todos >".escapeshellarg("$dir/preclear_disk_log.txt"));
    exec("cat /var/log/diskinfo.log 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_log.txt"));
    exec("cat /var/local/emhttp/plugins/diskinfo/diskinfo.json 2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_json.txt"));
    exec("/etc/rc.d/rc.diskinfo --version  2>/dev/null | todos >".escapeshellarg("$dir/diskinfo_status.txt"));
    exec("/etc/rc.d/rc.diskinfo --status  2>/dev/null | todos >>".escapeshellarg("$dir/diskinfo_status.txt"));
    exec("zip -qmr ".escapeshellarg($file)." ".escapeshellarg($dir));
    echo "/$file";
  break;


  case 'get_resumable':
    $serial  = urldecode($_POST['serial']);
    if (is_file("/tmp/.preclear/${serial}.resume"))
    {
      echo json_encode(["resume" => "/tmp/.preclear/${serial}.resume"]);
    }
    else if (is_file("/boot/config/plugins/$plugin/${serial}.resume"))
    {
      echo json_encode(["resume" => "/boot/config/plugins/$plugin/${serial}.resume"]);
    }
    else
    {
      echo json_encode(["resume" => false]);
    }
    break;


  case 'resume_preclear':
    $disk = $_POST['disk'];
    $file = "/tmp/.preclear/${disk}/pause";
    if (file_exists($file))
    {
      unlink($file);
    }
    break;


  case 'set_queue':
    $queue_session = "preclear_queue";
    $queue = $_POST["queue"];
    $session = TMUX::hasSession($queue_session);
    $pid_file = "/var/run/preclear_queue.pid";
    $pid = is_file($pid_file) ? file_get_contents($pid_file) : 0;

    file_put_contents("/boot/config/plugins/${plugin}/queue", $queue);

    if ($queue > 0)
    {
      if ($session && $pid > 0)
      {
        if (! posix_kill($pid, 0))
        {
          @unlink($pid_file);
          foreach (range(0, 10) as $i) if (! posix_kill($pid, 0)) break; else sleep(1);
        }
        else
        {
          posix_kill($pid, 1);
        }
      }
      else
      {
        TMUX::NewSession( $queue_session );
        TMUX::sendCommand( $queue_session, "/usr/local/emhttp/plugins/${plugin}/script/preclear_queue.sh $queue");
      }
    }
    else
    {
        @unlink($pid_file);
      foreach (glob("/tmp/.preclear/*/queued") as $file)
      {
        @unlink($file);
        TMUX::killSession( $queue_session );
      }
    }
    sleep(1);
    break;


  case 'get_queue':
    echo is_file("/boot/config/plugins/preclear.disk/queue") ? trim(file_get_contents("/boot/config/plugins/preclear.disk/queue")) : 0;
    break;


  case 'save_sort':
    $devices = $_POST["devices"];
    file_put_contents($sort_file, implode(PHP_EOL, $devices));
    break;


  case 'reset_sort':
    if (is_file($sort_file)) unlink($sort_file);
    break;


  case 'resume_all':
    $paused = glob("/tmp/.preclear/*/pause");
    if ($paused)
    {
      foreach ($paused as $file) {
        unlink($file);
      }
    }
    break;;


  case 'pause_all':
    $sessions = glob("/tmp/.preclear/*/");
    if ($sessions)
    {
      foreach ($sessions as $session) {
        file_put_contents("${session}pause", "");
      }
    }
    break;;


  case 'clear_all_preclear':
    shell_exec("/usr/local/emhttp/plugins/preclear.disk/script/clear_preclear.sh");
    echo json_encode(["success" => true]);
    break;;
}


switch ($_GET['action']) {

  case 'show_preclear':
    $serial = urldecode($_GET['serial']);
    ?>
    <html>
      <body>
        <table style="width: 100%;float: center;" >
          <tbody>
            <tr>
              <td style="width: auto;">&nbsp;</td>
              <td style="width: 968px;"><div id="data_content"></div></td>
              <td style="width: auto;">&nbsp;</td>
            </tr>
            <tr>
              <td></td>
              <td><div style="text-align: center;"><button class="btn" data-clipboard-target="#data_content">Copy to clipboard</button></div></td>
              <td></td>
            </tr>
          </tbody>
        </table>
        <?if (is_file("webGui/scripts/dynamix.js")):?>
        <script type='text/javascript' src='/webGui/scripts/dynamix.js'></script>
        <?else:?>
        <script type='text/javascript' src='/webGui/javascript/dynamix.js'></script>
        <?endif;?>
        <script src="/plugins/<?=$plugin;?>/assets/clipboard.min.js"></script>
        <script>
          var timers = {};
          var URL = "/plugins/<?=$plugin;?>/Preclear.php";
          var serial = "<?=$serial;?>";

          function get_preclear()
          {
            clearTimeout(timers.preclear);
            $.post(URL,{action:"get_preclear",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"},function(data) {
              if (data.content)
              {
                $("#data_content").html(data.content);
              }
            },"json").always(function() {
              timers.preclear=setTimeout('get_preclear()',1000);
            }).fail(function (jqXHR, textStatus, error)
            {
              if (jqXHR.status == 200)
              {
                window.location=window.location.pathname+window.location.hash;
              }
            });
          }
          function hit_yes(serial)
          {
            $.post(URL,{action:"hit_yes",serial:serial,csrf_token:"<?=$var['csrf_token'];?>"});
          }
          $(function() {
            document.title='Preclear for disk <?=$serial;?> ';
            get_preclear();
            new Clipboard('.btn');
          });
        </script>
      </body>
    </html>
    <?
    break;

  case 'get_csrf_token':
    echo json_encode(["csrf_token" => $var['csrf_token']]);
    break;

  case 'get_log':
    $session = urldecode($_GET['session']);
    $file = file("/var/log/preclear.disk.log", FILE_IGNORE_NEW_LINES);
    $output = preg_grep("/${session}/i",$file);
    $tmpfile = "/tmp/${session}.txt";

    file_put_contents($tmpfile, implode("\r\n", $output));

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename='.basename($tmpfile));
    header('Content-Transfer-Encoding: binary');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($tmpfile));
    readfile($tmpfile);

    unlink($tmpfile);
    break;
}

?>