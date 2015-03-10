<?PHP
$plugin = "gfjardim.usb.automount";
require_once("/usr/local/emhttp/plugins/${plugin}/include/usb_mount_lib.php");
switch ($_POST['action']) {
  case 'get_content':
    $disks = get_all_disks_info();
    $config_file = $GLOBALS["paths"]["config_file"];
    $config = is_file($config_file) ? @parse_ini_file($config_file, true) : array();
    echo "<table class='usb_disks' id='usb_table'>";
    echo "<thead><tr><td>Device</td><td>Identification</td><td>Mount point</td><td>FS</td><td>Size</td><td>Used</td><td>Free</td><td>Open files</td><td>Control</td><td>Auto mount</td><td>Script</td></tr></thead>";
    echo "<tbody>";
    if ( count($disks) ) {
      foreach ($disks as $disk) {
        echo "<tr>";
        echo "<td><img src='/webGui/images/".(is_shared($disk['label']) ? "green-on.png":"red-on.png")."'> ".basename($disk['device'])."</td>";
        echo "<td>".$disk['label']."</td>";
        echo "<td><a href='/Shares/Browse?dir=".$disk['target']."' target='_blank' title='Browse ".$disk['target']."'>".$disk['target']."</a></td>";
        echo "<td>".$disk['fstype']."</td>";
        echo "<td>".$disk['size']."</td>";
        echo "<td>".$disk['used']."</td>";
        echo "<td>".$disk['avail']."</td>";
        echo "<td>".(strlen($disk['target']) ? shell_exec("lsof '${disk[target]}' 2>/dev/null|grep -c -v COMMAND") : "-")."</td>";
        echo "<td>".(strlen($disk['target']) ? "<button onclick=\"usb_mount('/usr/local/sbin/usb_umount ${disk[device]}');\">Unmount</button>" : "<button onclick=\"usb_mount('/usr/local/sbin/usb_mount ${disk[device]}');\">Mount</button>")."</td>";
        echo "<td><input type='checkbox' class='autmount' serial='".$disk['serial']."' ".(is_automount($disk['serial']) ? 'checked':'')."></td>";
        echo "<td><a href='/Main/EditScript?serial=".htmlentities($disk['serial'])."'><img src='/webGui/images/default.png' style='cursor:pointer;width:16px;".( (get_command($disk['serial'])) ? "":"opacity: 0.4;" )."'></a></td>";
        echo "</tr>";
      }
    };
    echo "</tbody></table><br><br>";

    $disks_serials = array();
    foreach ($disks as $disk) $disks_serials[] = $disk['serial'];
    $ct = "";
    foreach ($config as $serial => $value) {
      if (! preg_grep("#${serial}#", $disks_serials)){
        $ct .= "<tr><td><img src='/webGui/images/green-blink.png'> absent</td><td>$serial</td><td><input type='checkbox' class='autmount' serial='${serial}' ".( (get_config($serial, "automount") == "yes") ? "checked":"")."></td><td>${value[command]}</td><td colspan='7'><span style='cursor:pointer;' onclick='remove_disk_config(\"${serial}\")'>Remove</a></td></tr>";
      }
    }
    if (strlen($ct)) echo "<table class='usb_absent'><thead><tr><td>Device</td><td>Serial Number</td><td>Auto mount</td><td>Script</td><td colspan='7'>Remove config</td></tr></thead><tbody>${ct}</tbody></table>";

    echo '<script type="text/javascript">';
    echo '$(".autmount").each(function(){var checked = $(this).is(":checked");$(this).switchButton({labels_placement: "right", checked:checked});});';
    echo '$(".autmount").change(function(){$.post("/plugins/'.$plugin.'/update_cfg.php",{action:"automount",serial:$(this).attr("serial"),status:$(this).is(":checked")},function(data){$(this).prop("checked",data.automount);},"json");});';
    echo '</script>';
    break;

  case 'detect':
    if (is_file("/var/state/${plugin}")) {
      echo '{"reload":"true"}';
      unlink("/var/state/${plugin}");
    }
    break;
}
