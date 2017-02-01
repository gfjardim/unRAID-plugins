<?
$plugin = "statistics.sender";

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
    echo shell_exec("/usr/local/emhttp/plugins/statistics.sender/scripts/send_statistics ".escapeshellarg($file));
    break;

  case 'remove_statistics':
    $file = $_POST["file"];
    @rename($file,"${file}.dismiss");
    break;
}

?>