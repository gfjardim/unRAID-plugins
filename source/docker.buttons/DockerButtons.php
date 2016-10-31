<?
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once "$docroot/plugins/dynamix.docker.manager/include/DockerClient.php";
$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();

$_REQUEST  = array_merge($_GET, $_POST);
$action    = array_key_exists('action', $_REQUEST) ? $_REQUEST['action'] : '';
$container = array_key_exists('container', $_REQUEST) ? $_REQUEST['container'] : '';
$image     = array_key_exists('image', $_REQUEST) ? $_REQUEST['image'] : '';
$plugin    = array_key_exists('plugin', $_REQUEST) ? $_REQUEST['plugin'] : '';
$Info      = $DockerTemplates->getAllInfo();

switch ($action) {
  case 'get_content':
    $Output = [];
    $Output['Startable'] = [];
    $Output['Stoppable'] = [];
    $Output['Updatable'] = [];
    $Output['Unnamed']   = [];
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

      if (preg_match("/[a-z]*?_[a-z]*$/i", $name))
      {
        $Output['Unnamed'][] = $name;
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

  case 'remove_container':
    if (is_array($container))
    {
      foreach ($container as $remove) {
         $DockerClient->removeContainer($remove);
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

  case 'plugin_update':
    if (is_array($plugin))
    {
      readfile('logging.htm');
      $write_log = function($string) {
        if (empty($string))
        {
          return;
        }
        $string = str_replace("\n", "<br>", $string);
        $string = str_replace('"', "\\\"", trim($string));
        echo "<script>addLog(\"{$string}\");</script>";
        @flush();
      };
      foreach ($plugin as $file) {
        $command = escapeshellcmd("/usr/local/emhttp/plugins/dynamix.plugin.manager/scripts/plugin");
        $command = "${command} update ".escapeshellarg($file);
        $proc = popen($command, 'r');
        while (!feof($proc)) {
          $write_log(fgets($proc));
        }
        $write_log("\n\n");
      }
    }
    break;
}
?>