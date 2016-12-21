<?
function lsDir($root, $ext = null)
{
  $iter = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($root,
          RecursiveDirectoryIterator::SKIP_DOTS),
          RecursiveIteratorIterator::SELF_FIRST,
          RecursiveIteratorIterator::CATCH_GET_CHILD);
  $paths = [];
  foreach ($iter as $path => $fileinfo)
  {
    $fext = $fileinfo->getExtension();
    if ($ext && ($ext != $fext))
    {
      continue;
    }
    if ($fileinfo->isFile())
    {
      $paths[] = $path;
    }
  }
  return $paths;
}


function getReports()
{
  return lsDir("/boot/config/plugins/","sreport");
}


$_REQUEST = array_merge($_POST,$_GET);

switch ($_REQUEST['action'])
{
  case 'get_statistics':
    $reports = getReports();
    if (count($reports))
    {
      $rfile = $reports[0];
      $report = parse_ini_file($rfile, true) ?: [];
      if (array_key_exists("report", $report))
      {
        $report["report"]["file"] = $rfile;
        echo json_encode($report);
      }
    }
    break;


  case 'send_statistics':
    $file = $_POST["file"];
    $report = parse_ini_file($file,true) ?: [];
    if (array_key_exists("report", $report))
    {
      $url = $report["report"]["url"];

      // Start TOR
      shell_exec("/etc/rc.d/rc.tor start 2>&1");

      $myip = trim(shell_exec("curl -s http://whatismyip.akamai.com 2>/dev/null"));
      $torip = trim(shell_exec("curl -s --socks5-hostname 127.0.0.1:9050 http://whatismyip.akamai.com 2>/dev/null"));

      // Exit if not anonymized
      if ($myip == $torip)
      {
        exit();
      }
      unset($report["report"]);
      $cmd = "curl -s --socks5-hostname 127.0.0.1:9050 ".escapeshellarg($url)." -d ifq";
      foreach ($report as $key => $value)
      {
        $arg = str_replace("^n", "\n", $value['value']);
        $arg = "{$value['entry']}={$arg}";
        $cmd .= " -d ".escapeshellarg($arg);
      }

      shell_exec("echo sending statistics:{$report['report']['title']}|logger -t'${plugin}'");
      shell_exec("echo my ip = $myip|logger -t'${plugin}'");
      shell_exec("echo tor ip = $torip|logger -t'${plugin}'");
      exec($cmd, $out, $exit_code);
      if ($exit_code === 0)
      {
        shell_exec("echo statistics sent.|logger -t'${plugin}'");
        echo json_encode(["success"=>true]);
        @rename($file,"${file}.sent");
      }
      else
      {
        shell_exec("echo send statistics failed.|logger -t'${plugin}'");
        echo json_encode(["success"=>false,"output"=>"Error code: $exit_code"]);
      }

      // Stop TOR
      shell_exec("/etc/rc.d/rc.tor stop 2>&1");
    }

    break;


  case 'remove_statistics':
    $file = $_POST["file"];
    @rename($file,"${file}.dismiss");
    break;
}

?>