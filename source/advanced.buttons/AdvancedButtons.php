<?
$plugin  = "advanced.buttons";
require_once("/usr/local/emhttp/plugins/${plugin}/assets/common.php");
$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();

$_REQUEST  = array_merge($_GET, $_POST);
$action    = array_key_exists('action',    $_REQUEST) ? $_REQUEST['action'] : '';
$container = array_key_exists('container', $_REQUEST) ? $_REQUEST['container'] : '';
$image     = array_key_exists('image',     $_REQUEST) ? $_REQUEST['image'] : '';
$plugins   = array_key_exists('plugin',    $_REQUEST) ? $_REQUEST['plugin'] : '';

if (! is_dir($Files['StatusDir']) )
{
  mkdir($Files['StatusDir']);
}

function isDockerRunning()
{
  global $Files;
  $file = $Files['DockerStat'];
  if (is_file($file))
  {
    $json = @json_decode(@file_get_contents($file),true) ?: [];
    if ( array_key_exists("pid", $json) && ( $json["pid"] == 0 || posix_kill($json["pid"],0) ) )
    {
      return true;
    }
  }
  return false;
}

function isPluginUpdating()
{
  global $Files;
  $file = $Files['PluginStat'];
  if (is_file($file))
  {
    $json = @json_decode(@file_get_contents($file),true) ?: [];
    if ( array_key_exists("pid", $json) && ( $json["pid"] == 0 || posix_kill($json["pid"],0) ) )
    {
      return true;
    }
  }
  return false;
}

switch ($action) {
  case 'get_content':
    $Output = [];
    $Info   = $DockerTemplates->getAllInfo();
    $Output['Startable'] = [];
    $Output['Stoppable'] = [];
    $Output['Updatable'] = [];
    $Output['Unnamed']   = [];
    $Output['Orphaned']  = [];
    $Output['ForceAll']  = [];
    $Output['DockerRunning']  = isDockerRunning();
    $Output['PluginsAll']     = array_values( array_diff(scandir("/var/log/plugins/"), ['..', '.']) );
    $Output['PluginUpdating'] = isPluginUpdating();
    $Output["Saved"]     = array_map(function($ct){return $ct["name"];} ,json_decode(@file_get_contents($Files['ConfigFile']) ?: "[]", true));
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


  case 'save_status':
    $Info   = $DockerTemplates->getAllInfo();
    $containers = array_map(function($ct) use ($Info)
                            {
                              return [ "name"      => $ct["Name"], 
                                       "status"    => $ct["Running"], 
                                       "autostart" => $Info[$ct["Name"]]["autostart"]
                                      ];
                            }, 
                              $DockerClient->getDockerContainers()
                            );
    file_put_contents($Files['ConfigFile'], json_encode($containers));
    break;


  case 'start':
  case 'stop':
  case 'restart':
    if (is_array($container) && ! isDockerRunning())
    {
      file_put_contents($Files["DockerStat"], '{"pid":0,"title":" ","message":"","status":""}');
      $Cmd = "/usr/local/emhttp/plugins/${plugin}/script/docker '$action' '".implode("' '", $container)."' &>/dev/null &";
      syslog(LOG_NOTICE, $Cmd);
      shell_exec($Cmd);
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


  case 'update_containers':
    if (is_array($container) && ! isDockerRunning())
    {
      file_put_contents($Files["DockerStat"], '{"pid":0,"title":" ","message":"","status":""}');
      shell_exec("/usr/local/emhttp/plugins/${plugin}/script/create 'Updating' '".implode("' '", $container)."' &>/dev/null &");
    }
    break;


  case 'restore_containers':
    $installed = array_map(function($ct){return $ct["Name"];}, $DockerClient->getDockerContainers());
    $restore = array_diff($container, $installed);
    if (count( $restore ) && ! isDockerRunning())
    {
      file_put_contents($Files["DockerStat"], '{"pid":0,"title":" ","message":"","status":""}');
      $Cmd = "/usr/local/emhttp/plugins/${plugin}/script/create 'Restoring' '".implode("' '", $restore)."' &>/dev/null &";
      syslog(LOG_NOTICE, $Cmd);
      shell_exec($Cmd);
    }
    break;


  case 'get_docker_status':
    if (is_file($Files['DockerStat']))
    {
      $status = @file_get_contents($Files["DockerStat"]) ?: "[]";
      $status = json_decode($status, true);
      if (!isDockerRunning())
      {
        $status["type"] = ($status["type"] == "reload") ? "reload" :  "stopped";
      }
      echo json_encode($status);
    }
    else
    {
       echo json_encode(["type" => "stopped"]);
    }
    break;


  case 'get_plugin_status':
    $outout = @file_get_contents($Files['PluginOut']);
    if (is_file($Files["PluginStat"]))
    {
      $status = @file_get_contents($Files["PluginStat"]) ?: "[]";
      $status = json_decode($status, true);
      if (!isPluginUpdating())
      {
        $status["type"] = ($status["type"] == "reload") ? "reload" :  "static";
      }
      $status["content"] = $outout;
      echo json_encode($status);
    }
    else
    {
       echo json_encode(["type" => "stopped"]);
    }
    break;


  case 'remove_status':
    switch ($_REQUEST["scope"]) {
      case 'plugin': $file = $Files["PluginStat"]; break;
      case 'docker': $file = $Files["DockerStat"]; break;
    };
    if ( isset($_REQUEST["disable_reload"]) )
    {
      $status = json_decode(@file_get_contents($file), true);
      $status["type"] = "static";
      file_put_contents($file, json_encode($status));
    }
    else
    {
      @unlink($file);
    }
    break;


  case 'is_running':
    echo json_encode(["docker" => isDockerRunning(), "plugin" => isPluginUpdating()]);
    break;


  case 'plugin_update':
    if (is_array($plugins) && ! isPluginUpdating())
    {
      $method = $_REQUEST["method"];
      file_put_contents($Files["PluginStat"], '{"pid":0,"title":" ","message":"","status":""}');
      $Cmd = "/usr/local/emhttp/plugins/${plugin}/script/plugin '${method}' '".implode("' '", $plugins)."' &>/dev/null &";
      syslog(LOG_NOTICE, $Cmd);
      shell_exec($Cmd);
    }
    break;
}
?>