<?
$plugin = "statistics.sender";
ignore_user_abort(true);

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

  case 'get_dismissed':
    $reports = array_filter(lsDir("/boot/config/plugins/"), function($v){ return preg_match("#\.sreport\.dismiss$#", $v);});
    $output = [];
    if (count($reports))
    {
      $rep = &$output["reports"];
      foreach ($reports as $key => $rfile) {
        $report = parse_ini_file($rfile, true) ?: [];
        $mtime = filemtime($rfile);
        if (array_key_exists("report", $report))
        {
          $report["report"]["file"] = $rfile;
          $report["report"]["select"] = date("M j, Y, G:i:s", $mtime).": ".(preg_replace("#<[^>]*>#i", " - ", $report["report"]["title"]));
          $rep[$rfile] = $report;
        }
      }
    }
    echo json_encode($output);
    break;

  case 'send_statistics':
    $file = $_POST["file"];
    echo shell_exec("/usr/local/emhttp/plugins/statistics.sender/scripts/send_statistics ".escapeshellarg($file));
    break;

  case 'remove_statistics':
    $file = $_POST["file"];
    if (!preg_match("#\.dismiss$#", $file))
    {
      @rename($file,"${file}.dismiss");
    }
    break;
}

?>