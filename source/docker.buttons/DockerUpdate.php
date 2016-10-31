<?PHP
require_once '/usr/local/emhttp/plugins/dynamix.docker.manager/include/DockerClient.php';

$DockerClient    = new DockerClient();
$DockerTemplates = new DockerTemplates();
$DockerUpdate    = new DockerUpdate();

function download_url($url, $path = "", $bg = false) {
  exec("curl --max-time 30 --silent --insecure --location --fail ".($path ? " -o ".escapeshellarg($path) : "")." $url ".($bg ? ">/dev/null 2>&1 &" : "2>/dev/null"), $out, $exit_code);
  return ($exit_code === 0) ? implode("\n", $out) : false;
}

function getV1Size($strRepo, $digest, $Token)
{
  $sizeBlobURL = sprintf("https://registry-1.docker.io/v2/%s/blobs/%s", $strRepo, $digest);
  $sizeBlobURL = sprintf("--head -H %s %s", $Token, escapeshellarg($sizeBlobURL));
  if (preg_match("/Content-Length: (\d+)/", download_url($sizeBlobURL), $matches))
  {
    return $matches[1];
  }
  else
  {
    return 0;
  }

}

function pullImage($image) {
  global $DockerClient, $DockerTemplates, $DockerUpdate;
  $alltotals      = [];
  $curtotals      = [];
  $lastPercentage = 0;
  $fsLayers       = [];
  $manifestLayers = [];

  $image = DockerUtil::ensureImageTag($image);
  list($strRepo, $strTag) = explode(':', DockerUtil::ensureImageTag($image));
  $strAuthURL = sprintf("https://auth.docker.io/token?service=registry.docker.io&scope=repository:%s:pull", $strRepo);
  $arrAuth    = json_decode(download_url(escapeshellarg($strAuthURL)), true);
  if (! array_key_exists("token", $arrAuth))
  {
    return false;
  }

  $Token = escapeshellarg("Authorization: Bearer ${arrAuth['token']}");

  $strManifestURL = sprintf("https://registry-1.docker.io/v2/%s/manifests/%s", $strRepo, $strTag);
  $strManifestURL = sprintf("-H 'Accept: application/vnd.docker.distribution.manifest.v2+json' -H %s %s", $Token, escapeshellarg($strManifestURL));
  $Manifest       = json_decode(download_url($strManifestURL),true);

  if (! array_key_exists("schemaVersion", $Manifest))
  {
    return false;
  }

  $manifestVersion = $Manifest["schemaVersion"];
  if ($manifestVersion == "1")
  {
    $Manifest["fsLayers"] = array_map("unserialize", array_unique(array_map("serialize", $Manifest["fsLayers"])));
    foreach ($Manifest["fsLayers"] as $blob) {
      $layerId = substr($blob["blobSum"], 7, 12);
      $manifestLayers[$layerId]["digest"] = $blob["blobSum"];
    }
  }
  else
  {
    foreach ($Manifest["layers"] as $layer) {
      $layerId = substr($layer["digest"], 7, 12);
      $manifestLayers[$layerId]["digest"] = $layer["digest"];
      $manifestLayers[$layerId]["size"] = $layer["size"];
    }
  }

  $DockerClient->pullImage($image, function ($line) use (&$alltotals, &$lastPercentage, &$curtotals, &$strRepo, &$manifestLayers, &$Token) {
    $_echo   = function($m){print_r($m); flush(); ob_flush();};
    $content = json_decode($line, true);
    $id      = (isset($content['id'])) ? trim($content['id']) : '';
    $status  = (isset($content['status'])) ? trim($content['status']) : '';

    if (!empty($id)) {

      if ($status == 'Pulling fs layer')
      {
        $manifestLayers[$id]["size"] = $manifestLayers[$id]["size"] ?: getV1Size($strRepo, $manifestLayers[$id]["digest"], $Token);
        $alltotals[$id] = $manifestLayers[$id]["size"];
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

    if ( count($alltotals) )
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



pullImage("sparklyballs/beardrage:latest");
pullImage("gfjardim/crashplan");

?>


