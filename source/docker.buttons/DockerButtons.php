<?
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();

$_REQUEST  = array_merge($_GET, $_POST);
$action    = array_key_exists('action', $_REQUEST) ? $_REQUEST['action'] : '';
$container = array_key_exists('container', $_REQUEST) ? $_REQUEST['container'] : '';
$image     = array_key_exists('image', $_REQUEST) ? $_REQUEST['image'] : '';
$Info      = $DockerTemplates->getAllInfo();

function pullImage($image) {
  global $DockerClient, $DockerTemplates;
  $alltotals      = [];
  $curtotals      = [];
  $lastPercentage = 0;
  $fsLayers       = [];

  $DockerClient->pullImage($image, function ($line) use (&$alltotals, &$lastPercentage, &$curtotals, &$fsLayers) {
    $_echo   = function($m){print_r($m); flush(); ob_flush();};
    $content = json_decode($line, true);
    $id      = (isset($content['id'])) ? trim($content['id']) : '';
    $status  = (isset($content['status'])) ? trim($content['status']) : '';

    if (!empty($id)) {

      if (!empty($content['progressDetail']) && !empty($content['progressDetail']['total'])) {
        $alltotals[$id] = $content['progressDetail']['total'];
      }

      if ($status == 'Pulling fs layer')
      {
        $fsLayers[$id] = "";
      }
      else if ($status == 'Downloading')
      {
        $current = $content['progressDetail']['current'];
        $curtotals[$id] = $current;
      }
      else if ($status == "Download complete")
      {
        $curtotals[$id] = $alltotals[$id];
      }
    }

    if ( count($alltotals) && ! array_diff_key($fsLayers, $alltotals))
    {
      $sumCurrent = array_sum($curtotals);
      $sumTotal   = array_sum($alltotals);
      $curPercentage = round(($sumCurrent / $sumTotal) * 100);
      if ($curPercentage != $lastPercentage) {
        $lastPercentage = $curPercentage;
        $_echo(json_encode(["current"=>$sumCurrent,"total"=>$sumTotal,"percentage"=>$curPercentage])."\n");
      }
    }
    if (empty($status)) return;
  });
  return true;
}

switch ($action) {
  case 'get_content':
    $Output = [];
    $Output['Startable'] = [];
    $Output['Stoppable'] = [];
    $Output['Updatable'] = [];
    $Output['Orphaned']  = [];
    $Output['ForceAll']  = [];

    foreach ($DockerClient->getDockerContainers() as $container) {
      $name    = $container["Name"];
      $updated = ($Info[$name]['updated'] == "false") ? false : true;

      if ($container['Running'])
      {
        $Output['Stoppable'][] = $name;
      }
      else
      {
        $Output['Startable'][] = $name;
      }

      if ($updated === false)
      {
        $Output['Updatable'][] = $name;
      }
      $Output['ForceAll'][] = $name;
    }

    foreach ($DockerClient->getDockerImages() as $image)
    {
      if (! count($image['usedBy']) ) {
        $Output['Orphaned'][] = $image['Id'];
      }
    }
    echo json_encode($Output,true);
    break;
  
  case 'start':
    if (is_array($container))
    {
      foreach ($container as $start) {
         $DockerClient->startContainer($start);
      }
    }
    break;

  case 'stop':
    if (is_array($container))
    {
      foreach ($container as $stop) {
         $DockerClient->stopContainer($stop);
      }
    }
    break;

  case 'remove_image':
    if (is_array($image))
    {
      foreach ($image as $img) {
         $DockerClient->removeImage($img);
      }
    }
    break;

  case 'update':
    // Set header
    header('Content-type: application/octet-stream');
    // Turn off output buffering
    ini_set('output_buffering', 'off');
    // Turn off PHP output compression
    ini_set('zlib.output_compression', false);
    // Implicitly flush the buffer(s)
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);
    // Clear, and turn off output buffering
    while (ob_get_level() > 0) {
        // Get the curent level
        $level = ob_get_level();
        // End the buffering
        ob_end_clean();
        // If the current level has not changed, abort
        if (ob_get_level() == $level) break;
    }

    $containers = $DockerClient->getDockerContainers();
    foreach ($container as $update) {
      $key = array_search($update, array_column($containers, 'Name'));
      $image = $containers[$key]["Image"];
    }
    pullImage("sparklyballs/beardrage:latest");
}
?>