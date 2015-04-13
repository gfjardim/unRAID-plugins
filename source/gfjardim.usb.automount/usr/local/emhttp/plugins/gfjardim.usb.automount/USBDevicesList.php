<?PHP
$plugin = "gfjardim.usb.automount";
require_once("/usr/local/emhttp/plugins/${plugin}/include/usb_mount_lib.php");
switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    echo "<table class='tablesorter usb_disks'><thead><tr><th>Device</th><th>Identification</th><th>Mount point</th><th>FS</th><th>Size</th><th>Used</th><th>Free</th><th>Open files</th><th>Control</th><th>Auto mount</th><th>Script</th></tr></thead>";
    echo "<tbody>";
    if ( count($disks) ) {
      foreach ($disks as $disk) {
        echo "<tr>";
        $fscheck = sprintf(get_fsck_commands($disk['fstype'])['ro'], $disk['device'] );
        $dev = sprintf( "<img src='/webGui/images/%s'> %s", ( is_shared($disk['label']) ? "green-on.png":"red-on.png" ), basename($disk['device']) );
        echo "<td>".(! $disk['target']  ? "<a style='cursor:pointer;' onclick='openWindow(\"".$fscheck."\",\"Cheching fs\",600,900)'>".$dev."</a>" : $dev )."</td>";
        echo "<td>".$disk['label']."</td>";
        echo "<td><a href='/Shares/Browse?dir=".$disk['target']."' target='_blank' title='Browse ".$disk['target']."'>".$disk['target']."</a></td>";
        echo "<td>".$disk['fstype']."</td>";
        echo "<td>".$disk['size']."</td>";
        echo "<td>".$disk['used']."</td>";
        echo "<td>".$disk['avail']."</td>";
        echo "<td>".(strlen($disk['target']) ? shell_exec("lsof '${disk[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td>";
        echo "<td>".(strlen($disk['target']) ? "<button onclick=\"usb_mount('/usr/local/sbin/usb_umount ${disk[device]}');\">Unmount</button>" : "<button onclick=\"usb_mount('/usr/local/sbin/usb_mount ${disk[device]}');\">Mount</button>")."</td>";
        echo "<td><input type='checkbox' class='autmount' serial='".$disk['serial']."' ".(is_automount($disk['serial']) ? 'checked':'')."></td>";
        echo "<td><a href='/Main/EditScript?serial=".urlencode($disk['serial'])."&label=".urlencode($disk['label'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_config($disk['serial'],"command")) ? "":"opacity: 0.4;" )."'></a></td>";
        echo "</tr>";
      }
    } else {
      echo "<tr><td colspan='12' style='text-align:center;font-weight:bold;'>No usb disks present.</td></tr>";
    }
    echo "</tbody></table><br><br>";

    $config_file = $GLOBALS["paths"]["config_file"];
    $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
    $disks_serials = array();
    foreach ($disks as $disk) $disks_serials[] = $disk['serial'];
    $ct = "";
    foreach ($config as $serial => $value) {
      if (! preg_grep("#${serial}#", $disks_serials)){
        $ct .= "<tr><td><img src='/webGui/images/green-blink.png'> missing</td><td>$serial</td><td><input type='checkbox' class='autmount' serial='${serial}' ".( is_automount($serial) ? 'checked':'' )."></td><td><a href='/Main/EditScript?serial=${serial}'>".basename($value['command'])."</a></td><td colspan='7'><span style='cursor:pointer;' onclick='remove_disk_config(\"${serial}\")'>Remove</a></td></tr>";
      }
    }
    if (strlen($ct)) echo "<table class='tablesorter usb_absent'><thead><tr><th>Device</th><th>Serial Number</th><th>Auto mount</th><th>Script</th><th colspan='7'>Remove config</th></tr></thead><tbody>${ct}</tbody></table>";
    echo '<script type="text/javascript">';
    echo '$(".autmount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});';
    echo '$(".autmount").change(function(){$.post("/plugins/'.$plugin.'/update_cfg.php",{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});';
    echo '</script>';
    break;
  case 'detect':
    if (is_file("/var/state/${plugin}")) {
      echo json_encode(array("reload" => true));
    } else {
      echo json_encode(array("reload" => false));
    }
    break;
  case 'remove_hook':
    @unlink("/var/state/${plugin}");
    break;
}
?>
