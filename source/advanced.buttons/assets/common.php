<?
$plugin = "advanced.buttons";
$docroot = $docroot ?: @$_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp';
require_once("$docroot/plugins/dynamix.docker.manager/include/DockerClient.php");

$Files['StatusDir']  = "/tmp/.${plugin}";
$Files['ConfigFile'] = "/boot/config/plugins/${plugin}/status.json";
$Files['ConfigFile'] = "/boot/config/plugins/${plugin}/${plugin}.cfg";
$Files['DockerStat'] = "${Files['StatusDir']}/docker_status";
$Files['PluginStat'] = "${Files['StatusDir']}/plugin_status";
$Files['PluginOut']  = "${Files['StatusDir']}/plugin_out";

if (! is_dir($Files['StatusDir']))
{
  @mkdir($Files['StatusDir']);
}

if (! is_dir($Files['ConfigFile']))
{
  @mkdir(dirname($Files['ConfigFile']));
}

class Misc
{

  public function save_json_file($file, $content)
  {
    file_put_contents($file, json_encode($content, JSON_PRETTY_PRINT ));
  }


  public function get_json_file($file)
  {
    $ct =  file_exists($file) ? @json_decode(file_get_contents($file), true) : [];
    return is_array($ct) ? $ct : [];
  }
}

function saveStatus($file, $title, $message, $status, $pid = null, $type = null )
{
  if (! $pid)
  {
    $pid = getmypid();
  }
  $status = trim($status);
  $status = (strlen($status) > 57) ? substr($status, 0, 30)." ... ".substr($status, -30) : $status;
  $statusContent = Misc::get_json_file($file);
  $statusContent = [ "pid"     => $pid,
                     "title"   => $title, 
                     "message" => $message, 
                     "status"  => $status,
                     "type"    => $type
                    ];
  Misc::save_json_file($file, $statusContent);
  echo json_encode($statusContent)."\n";
}

?>