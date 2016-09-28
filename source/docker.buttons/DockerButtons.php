<?
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';
$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();

$Info = $DockerTemplates->getAllInfo();

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
    $Output['Orphaned'] = $image['Id'];
  }
}
echo json_encode($Output,true);
?>